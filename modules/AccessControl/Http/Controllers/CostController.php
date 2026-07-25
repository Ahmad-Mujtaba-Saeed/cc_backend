<?php

namespace Modules\AccessControl\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\User\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Project\Models\Project;
use Modules\Project\Models\RenderCostEvent;
use Modules\Project\Services\CostTracker;

/**
 * Admin-only reporting over the render cost ledger (render_cost_events).
 *
 * Events are denormalized — project_id / user_id / template_type live on the
 * event row — so spend history survives project deletion. Project and user
 * details are therefore hydrated separately and may legitimately be null.
 */
class CostController extends Controller
{
    /** Dashboard payload: combined totals, breakdowns, recent renders, rates. */
    public function index(Request $request): JsonResponse
    {
        $now = now();

        $totals = [
            'today' => $this->windowTotals($now->copy()->startOfDay()),
            'last_7_days' => $this->windowTotals($now->copy()->subDays(7)),
            'last_30_days' => $this->windowTotals($now->copy()->subDays(30)),
            'all_time' => $this->windowTotals(null),
        ];

        $byService = RenderCostEvent::query()
            ->select('service')
            ->selectRaw('COUNT(*) as events')
            ->selectRaw('SUM(units) as units')
            ->selectRaw('MAX(unit) as unit')
            ->selectRaw('SUM(cost_usd) as cost_usd')
            ->groupBy('service')
            ->orderByDesc(DB::raw('SUM(cost_usd)'))
            ->get()
            ->map(fn ($row) => [
                'service' => $row->service,
                'events' => (int) $row->events,
                'units' => round((float) $row->units, 2),
                'unit' => (string) $row->unit,
                'cost_usd' => round((float) $row->cost_usd, 4),
            ])
            ->values();

        $byTemplate = RenderCostEvent::query()
            ->select('template_type')
            ->selectRaw('COUNT(DISTINCT project_id) as renders')
            ->selectRaw('COUNT(*) as events')
            ->selectRaw('SUM(cost_usd) as cost_usd')
            ->groupBy('template_type')
            ->orderByDesc(DB::raw('SUM(cost_usd)'))
            ->get()
            ->map(fn ($row) => [
                'template_type' => $row->template_type,
                'renders' => (int) $row->renders,
                'events' => (int) $row->events,
                'cost_usd' => round((float) $row->cost_usd, 4),
                'avg_per_render' => $row->renders > 0
                    ? round((float) $row->cost_usd / (int) $row->renders, 4)
                    : null,
            ])
            ->values();

        // Last 30 days, one point per day — feeds the dashboard trend chart.
        $daily = RenderCostEvent::query()
            ->where('created_at', '>=', $now->copy()->subDays(30)->startOfDay())
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('SUM(cost_usd) as cost_usd')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'day' => (string) $row->day,
                'cost_usd' => round((float) $row->cost_usd, 4),
            ])
            ->values();

        $limit = min(50, max(5, (int) $request->query('limit', 20)));

        // One row per project, newest activity first. NULL project_ids (script
        // drafts, voice previews — spend before a project exists) group into a
        // single "unattributed" row.
        $recentRows = RenderCostEvent::query()
            ->select('project_id')
            ->selectRaw('MAX(template_type) as template_type')
            ->selectRaw('MAX(user_id) as user_id')
            ->selectRaw('COUNT(*) as events')
            ->selectRaw('SUM(cost_usd) as cost_usd')
            ->selectRaw('MAX(created_at) as last_event_at')
            ->groupBy('project_id')
            ->orderByDesc(DB::raw('MAX(id)'))
            ->limit($limit)
            ->get();

        $projects = Project::query()
            ->whereIn('id', $recentRows->pluck('project_id')->filter()->all())
            ->get(['id', 'title', 'status', 'template_type'])
            ->keyBy('id');

        $users = User::query()
            ->whereIn('id', $recentRows->pluck('user_id')->filter()->unique()->all())
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        $recent = $recentRows->map(function ($row) use ($projects, $users) {
            $project = $row->project_id ? $projects->get($row->project_id) : null;
            $user = $row->user_id ? $users->get($row->user_id) : null;

            return [
                'project_id' => $row->project_id ? (int) $row->project_id : null,
                'title' => $project?->title,
                'status' => $project?->status,
                'project_deleted' => $row->project_id !== null && $project === null,
                'template_type' => $row->template_type,
                'user' => $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email] : null,
                'events' => (int) $row->events,
                'cost_usd' => round((float) $row->cost_usd, 4),
                'last_event_at' => (string) $row->last_event_at,
            ];
        })->values();

        return response()->json([
            'totals' => $totals,
            'by_service' => $byService,
            'by_template' => $byTemplate,
            'daily' => $daily,
            'recent' => $recent,
            'rates' => CostTracker::rates(),
            'rate_labels' => CostTracker::RATE_LABELS,
            'rate_defaults' => CostTracker::DEFAULT_RATES,
        ]);
    }

    /**
     * Every ledger line for one render. Plain int (not route-model-binding)
     * so events for a deleted project remain viewable.
     */
    public function project(int $projectId): JsonResponse
    {
        $events = RenderCostEvent::query()
            ->where('project_id', $projectId)
            ->orderBy('id')
            ->get();

        if ($events->isEmpty()) {
            return response()->json(['message' => 'No cost events for this project.'], 404);
        }

        $project = Project::query()->find($projectId, ['id', 'title', 'status', 'template_type', 'created_at']);
        $userId = $events->firstWhere('user_id', '!=', null)?->user_id;
        $user = $userId ? User::query()->find($userId, ['id', 'name', 'email']) : null;

        $byService = $events->groupBy('service')->map(fn ($group, $service) => [
            'service' => $service,
            'events' => $group->count(),
            'units' => round($group->sum('units'), 2),
            'unit' => (string) $group->first()->unit,
            'cost_usd' => round($group->sum('cost_usd'), 4),
        ])->values();

        return response()->json([
            'project' => [
                'id' => $projectId,
                'title' => $project?->title,
                'status' => $project?->status,
                'template_type' => $project?->template_type ?? $events->first()->template_type,
                'deleted' => $project === null,
            ],
            'user' => $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email] : null,
            'total_cost_usd' => round($events->sum('cost_usd'), 4),
            'by_service' => $byService,
            'events' => $events->map(fn ($event) => [
                'id' => $event->id,
                'service' => $event->service,
                'label' => $event->label,
                'units' => (float) $event->units,
                'unit' => $event->unit,
                'cost_usd' => (float) $event->cost_usd,
                'meta' => $event->meta,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /** Update the admin-editable unit rates (applies to future events only). */
    public function updateRates(Request $request): JsonResponse
    {
        $rules = [];
        foreach (array_keys(CostTracker::DEFAULT_RATES) as $key) {
            $rules[$key] = ['sometimes', 'numeric', 'min:0'];
        }
        $data = $request->validate($rules);

        return response()->json([
            'rates' => CostTracker::updateRates($data),
            'rate_labels' => CostTracker::RATE_LABELS,
            'rate_defaults' => CostTracker::DEFAULT_RATES,
        ]);
    }

    private function windowTotals(?\Illuminate\Support\Carbon $since): array
    {
        $query = RenderCostEvent::query();
        if ($since !== null) {
            $query->where('created_at', '>=', $since);
        }

        $row = $query->selectRaw('COUNT(*) as events')
            ->selectRaw('COUNT(DISTINCT project_id) as renders')
            ->selectRaw('COALESCE(SUM(cost_usd), 0) as cost_usd')
            ->first();

        return [
            'events' => (int) $row->events,
            'renders' => (int) $row->renders,
            'cost_usd' => round((float) $row->cost_usd, 4),
        ];
    }
}
