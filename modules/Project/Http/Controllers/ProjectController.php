<?php

namespace Modules\Project\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Project\Models\Project;
use Modules\Project\Http\Resources\ProjectResource;
use Modules\Project\Http\Resources\ProjectCollection;
use Modules\Project\Http\Requests\StoreProjectRequest;
use Modules\Project\Http\Requests\UpdateProjectRequest;
use Modules\Project\Jobs\ProcessVideoJob;
use Modules\Project\Processors\TemplateProcessorFactory;
use Modules\Project\Services\TemplateSettingsService;
use Modules\User\Models\User;
use Modules\Billing\Services\CreditService;
use Modules\Billing\Exceptions\InsufficientCreditsException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use Illuminate\Support\Facades\Log;

class ProjectController extends Controller
{
    /**
     * Display a listing of the user's projects.
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $query = $user->projects();

        // Filter by status if provided
        if ($request->has('status')) {
            $query->status($request->status);
        }

        // Search by title if provided
        if ($request->has('search')) {
            $query->where('title', 'like', '%' . $request->search . '%');
        }

        // Sort by created_at desc by default
        $query->orderBy('created_at', 'desc');

        $projects = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => new ProjectCollection($projects),
            'message' => 'Projects retrieved successfully'
        ]);
    }

    /**
     * Finished renders for the "My Videos" page — completed projects only,
     * newest first, paginated. Kept separate from index() so the videos grid
     * never has to over-fetch every draft/failed project to filter them out
     * client-side.
     */
    public function videos(Request $request): JsonResponse
    {
        $user = auth()->user();
        $query = $user->projects()->completed();

        if ($request->filled('search')) {
            $query->where('title', 'like', '%' . $request->search . '%');
        }

        // Prefer the completion time; fall back so a completed project with a
        // null completed_at still sorts sensibly.
        $query->orderByRaw('COALESCE(completed_at, updated_at, created_at) DESC');

        $videos = $query->paginate($request->get('per_page', 12));

        return response()->json([
            'success' => true,
            'data' => new ProjectCollection($videos),
            'message' => 'Videos retrieved successfully'
        ]);
    }

    /**
     * Store a newly created project.
     */
    public function store(StoreProjectRequest $request): JsonResponse
    {
        try {
            $user = auth()->user();

            $projectData = $request->validated();

            if (!TemplateSettingsService::isEnabled($projectData['template_type'])) {
                return response()->json([
                    'success' => false,
                    'code' => 'template_disabled',
                    'message' => 'This template is currently unavailable.',
                ], 400);
            }

            $projectData['user_id'] = $user->id;
            $projectData['status'] = 'draft';
            $projectData['progress'] = 0;
            $projectData['aspect_ratio'] = $this->resolveAspectRatio(
                $projectData['template_type'],
                $projectData['settings'] ?? []
            );

            $project = Project::create($projectData);

            return response()->json([
                'success' => true,
                'data' => new ProjectResource($project),
                'message' => 'Project created successfully'
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create project: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Resolve the project aspect ratio based on template type and settings.
     */
    private function resolveAspectRatio(string $templateType, array $settings = []): string
    {
        $validRatios = ['9:16', '16:9', '1:1'];

        if (in_array($templateType, ['template_a', 'yt_compilation_short'], true) && isset($settings['aspect_ratio']) && in_array($settings['aspect_ratio'], $validRatios, true)) {
            return $settings['aspect_ratio'];
        }

        return match ($templateType) {
            'template_a' => '9:16',
            'template_b' => '16:9',
            'template_c' => '16:9',
            'yt_automation_short' => '9:16',
            'ai_image_based_shorts' => '9:16',
            'ai_horror_shorts' => '9:16',
            'yt_gameplay_short' => '9:16',
            'yt_compilation_short' => '16:9',
            'ranking_moments_short' => '9:16',
            'simple_video' => '16:9',
            default => '16:9',
        };
    }

    /**
     * Display the specified project.
     */
    public function show(Project $project): JsonResponse
    {
        // Check if user owns the project or project is public
        if ($project->user_id !== auth()->id() && !$project->is_public) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this project'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => new ProjectResource($project),
            'message' => 'Project retrieved successfully'
        ]);
    }

    /**
     * Update the specified project.
     */
    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        // Check if user owns the project
        if ($project->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this project'
            ], 403);
        }

        try {
            $project->update($request->validated());

            return response()->json([
                'success' => true,
                'data' => new ProjectResource($project),
                'message' => 'Project updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update project: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified project.
     */
    public function destroy(Project $project): JsonResponse
    {
        // Check if user owns the project
        if ($project->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this project'
            ], 403);
        }

        try {
            // Delete associated files
            if ($project->video_path && Storage::disk('public')->exists($project->video_path)) {
                Storage::disk('public')->delete($project->video_path);
            }
            
            if ($project->output_path && Storage::disk('public')->exists($project->output_path)) {
                Storage::disk('public')->delete($project->output_path);
            }
            
            if ($project->thumbnail_path && Storage::disk('public')->exists($project->thumbnail_path)) {
                Storage::disk('public')->delete($project->thumbnail_path);
            }

            $project->delete();

            return response()->json([
                'success' => true,
                'message' => 'Project deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete project: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload video for the project.
     */
    public function uploadVideo(Request $request, Project $project): JsonResponse
    {
        // Check if user owns the project
        if ($project->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this project'
            ], 403);
        }

        $request->validate([
            'video' => 'required|file|mimetypes:video/mp4,video/avi,video/mov,video/wmv,video/flv,video/webm|max:512000', // 500MB max
        ]);

        try {
            $video = $request->file('video');
            $fileName = Str::uuid() . '.' . $video->getClientOriginalExtension();
            $filePath = $video->storeAs('projects/videos', $fileName, 'public');

            // Get video metadata
            $ffprobe = FFProbe::create();
            $videoInfo = $ffprobe->format($storage_path = Storage::disk('public')->path($filePath));

            // Update project with video information
            $project->update([
                'video_path' => $filePath,
                'file_name' => $video->getClientOriginalName(),
                'file_type' => $video->getMimeType(),
                'file_size' => $video->getSize(),
                'duration' => $videoInfo->get('duration'),
                'status' => 'draft',
                'progress' => 0,
                'error_message' => null,
            ]);

            return response()->json([
                'success' => true,
                'data' => new ProjectResource($project),
                'message' => 'Video uploaded successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload video: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload a settings file for the project (e.g., custom gameplay file).
     */
    public function uploadSettingFile(Request $request, Project $project): JsonResponse
    {
        // Check if user owns the project
        if ($project->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this project'
            ], 403);
        }

        $request->validate([
            'file' => 'required|file|mimetypes:video/mp4,video/avi,video/mov,video/wmv,video/flv,video/webm|max:512000', // 500MB max
            'field_key' => 'required|string',
        ]);

        try {
            $file = $request->file('file');
            $fieldKey = $request->input('field_key');
            
            // Put it under projects/{project->id}/
            $fileName = $fieldKey . '_' . Str::uuid() . '.' . $file->getClientOriginalExtension();
            $filePath = $file->storeAs("projects/{$project->id}", $fileName, 'public');

            return response()->json([
                'success' => true,
                'data' => [
                    'path' => $filePath,
                    'file_name' => $file->getClientOriginalName(),
                ],
                'message' => 'File uploaded successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload setting file: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download the original project video.
     */
    public function downloadVideo(Project $project)
    {
        if ($project->user_id !== auth()->id() && !$project->is_public) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this project'
            ], 403);
        }

        if (!$project->output_path || !Storage::disk('public')->exists($project->output_path)) {
            return response()->json([
                'success' => false,
                'message' => 'Project video not found'
            ], 404);
        }

        $filePath = Storage::disk('public')->path($project->output_path);
        $mimeType = Storage::disk('public')->mimeType($project->output_path) ?: 'application/octet-stream';

        return response()->file($filePath, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . ($project->file_name ?? basename($filePath)) . '"',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
        ]);
    }

    /**
     * Start processing the project.
     */
    /**
     * Gate a render on subscription + credits and charge the cost.
     *
     * Returns a 402 JsonResponse to abort with, or null when the charge
     * succeeded and processing may proceed.
     */
    private function chargeForRender(Project $project): ?JsonResponse
    {
        if (!TemplateSettingsService::isEnabled($project->template_type)) {
            return response()->json([
                'success' => false,
                'code' => 'template_disabled',
                'message' => 'This template is currently unavailable.',
            ], 400);
        }

        /** @var CreditService $credits */
        $credits = app(CreditService::class);
        $user = $project->user ?: User::find($project->user_id);

        $credits->syncDailyGrant($user);

        if (!$user->hasActiveSubscription()) {
            return response()->json([
                'success' => false,
                'code' => 'no_subscription',
                'message' => 'An active subscription is required to generate videos.',
            ], 402);
        }

        $cost = $credits->costFor($project->template_type);

        try {
            $credits->charge($user, $project, $cost);
        } catch (InsufficientCreditsException $e) {
            return response()->json([
                'success' => false,
                'code' => 'insufficient_credits',
                'message' => 'You do not have enough credits for this video.',
                'balance' => $e->balance,
                'cost' => $e->cost,
            ], 402);
        }

        return null;
    }

    public function process(Project $project): JsonResponse
    {
        // Check if user owns the project
        if ($project->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this project'
            ], 403);
        }

        if (!$project->isReadyForProcessing()) {
            return response()->json([
                'success' => false,
                'message' => 'Project is not ready for processing'
            ], 400);
        }

        // Gate + charge credits before starting the render.
        if ($gate = $this->chargeForRender($project)) {
            return $gate;
        }

        try {
            Log::info("PROJECT_CONTROLLER: Starting process for project {$project->id}", [
                'project_id' => $project->id,
                'template_type' => $project->template_type,
                'video_path' => $project->video_path,
                'status_before' => $project->status,
                'user_id' => $project->user_id
            ]);

            $project->update([
                'status' => 'processing',
                'progress' => 0,
                'error_message' => null,
            ]);

            Log::info("PROJECT_CONTROLLER: Project status updated to processing", [
                'project_id' => $project->id,
                'new_status' => 'processing'
            ]);

            // Queue the video processing job
            $dispatchedJob = ProcessVideoJob::dispatch($project->fresh());
            
            Log::info("PROJECT_CONTROLLER: Job dispatched successfully", [
                'project_id' => $project->id,
                'job_class' => get_class($dispatchedJob),
                'queue_name' => 'video-processing'
            ]);

            return response()->json([
                'success' => true,
                'data' => new ProjectResource($project),
                'message' => 'Project processing started'
            ]);
        } catch (\Exception $e) {
            Log::error("PROJECT_CONTROLLER: Failed to start processing", [
                'project_id' => $project->id ?? 'unknown',
                'error_message' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            // The job never queued — give the charged credits back.
            app(CreditService::class)->refund($project);

            return response()->json([
                'success' => false,
                'message' => 'Failed to start processing: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update project progress.
     */
    public function updateProgress(Request $request, Project $project): JsonResponse
    {
        $request->validate([
            'progress' => 'required|integer|min:0|max:100',
            'status' => 'sometimes|string|in:draft,processing,completed,failed',
            'error_message' => 'sometimes|string|nullable',
        ]);

        try {
            $updateData = [
                'progress' => $request->progress,
            ];

            if ($request->has('status')) {
                $updateData['status'] = $request->status;
                if ($request->status === 'completed') {
                    $updateData['completed_at'] = now();
                }
            }

            if ($request->has('error_message')) {
                $updateData['error_message'] = $request->error_message;
            }

            $project->update($updateData);

            return response()->json([
                'success' => true,
                'data' => new ProjectResource($project),
                'message' => 'Project progress updated'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update progress: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Duplicate a project.
     */
    public function duplicate(Project $project): JsonResponse
    {
        // Check if user owns the project
        if ($project->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this project'
            ], 403);
        }

        try {
            $newProject = $project->replicate();
            $newProject->title = $project->title . ' (Copy)';
            $newProject->status = 'draft';
            $newProject->progress = 0;
            $newProject->video_path = null;
            $newProject->output_path = null;
            $newProject->thumbnail_path = null;
            $newProject->error_message = null;
            $newProject->failed_step = null;
            $newProject->processing_state = null;
            $newProject->completed_at = null;
            $newProject->published_at = null;
            $newProject->save();

            return response()->json([
                'success' => true,
                'data' => new ProjectResource($newProject),
                'message' => 'Project duplicated successfully'
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to duplicate project: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available templates.
     */
    public function getTemplates(): JsonResponse
    {
        try {
            $templates = TemplateProcessorFactory::getAvailableTemplates();
            $settings = TemplateSettingsService::all();

            foreach ($templates as $type => &$meta) {
                $meta['enabled'] = $settings[$type]['enabled'] ?? true;
                $meta['credit_cost'] = $settings[$type]['credit_cost'] ?? config('credits.default', 3);
                $meta['trending'] = $settings[$type]['trending'] ?? false;
            }
            unset($meta);

            return response()->json([
                'success' => true,
                'data' => $templates,
                'message' => 'Templates retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve templates: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get template configuration.
     */
    public function getTemplateConfig(string $templateType): JsonResponse
    {
        try {
            $config = TemplateProcessorFactory::getTemplateConfig($templateType);

            return response()->json([
                'success' => true,
                'data' => $config,
                'message' => 'Template configuration retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve template configuration: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Validate template settings.
     */
    public function validateTemplateSettings(Request $request): JsonResponse
    {
        $request->validate([
            'template_type' => 'required|string|in:simple_video,advanced_video,ai_image_based_shorts,ai_horror_shorts,yt_gameplay_short,yt_compilation_short,ranking_moments_short,template_a,template_b,template_c',
            'settings' => 'required|array',
        ]);

        try {
            $templateType = $request->template_type;
            $settings = $request->settings;

            $errors = TemplateProcessorFactory::validateTemplateSettings($templateType, $settings);

            return response()->json([
                'success' => empty($errors),
                'data' => [
                    'valid' => empty($errors),
                    'errors' => $errors,
                ],
                'message' => empty($errors) ? 'Template settings are valid' : 'Template settings validation failed'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to validate template settings: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get estimated processing time.
     */
    public function getEstimatedProcessingTime(Request $request): JsonResponse
    {
        $request->validate([
            'template_type' => 'required|string|in:simple_video,advanced_video,ai_image_based_shorts,ai_horror_shorts,yt_gameplay_short,yt_compilation_short,ranking_moments_short,template_a,template_b,template_c',
            'video_duration' => 'required|numeric|min:1',
        ]);

        try {
            $templateType = $request->template_type;
            $videoDuration = $request->video_duration;

            $estimation = TemplateProcessorFactory::getEstimatedProcessingTime($templateType, $videoDuration);

            return response()->json([
                'success' => true,
                'data' => $estimation,
                'message' => 'Processing time estimated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to estimate processing time: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Retry processing a failed project from the failed step.
     */
    public function retry(Project $project): JsonResponse
    {
        // Check if user owns the project
        if ($project->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this project'
            ], 403);
        }

        if ($project->status !== 'failed') {
            return response()->json([
                'success' => false,
                'message' => 'Project is not in a failed state'
            ], 400);
        }

        // A failed render is refunded, so a retry must charge again.
        if ($gate = $this->chargeForRender($project)) {
            return $gate;
        }

        try {
            $failedStep = $project->failed_step;

            Log::info("PROJECT_CONTROLLER: Retrying failed project {$project->id}", [
                'project_id' => $project->id,
                'failed_step' => $failedStep,
                'template_type' => $project->template_type,
            ]);

            $updateData = [
                'status' => 'processing',
                'progress' => 0,
                'error_message' => null,
                'failed_step' => $failedStep,
            ];

            // No known failed step means we can't resume — clear state to restart from scratch
            if (!$failedStep) {
                $updateData['processing_state'] = null;
            }

            $project->update($updateData);

            $project = $project->fresh();
            Log::info("PROJECT_CONTROLLER: Project freshness after retry update", [
                'project_id' => $project->id,
                'failed_step' => $project->failed_step,
            ]);

            Log::info("PROJECT_CONTROLLER: Project status updated to processing for retry", [
                'project_id' => $project->id,
                'new_status' => 'processing'
            ]);

            // Queue the video processing job
            $dispatchedJob = ProcessVideoJob::dispatch($project->fresh());
            
            Log::info("PROJECT_CONTROLLER: Retry job dispatched successfully", [
                'project_id' => $project->id,
                'job_class' => get_class($dispatchedJob),
                'queue_name' => 'video-processing'
            ]);

            return response()->json([
                'success' => true,
                'data' => new ProjectResource($project),
                'message' => 'Project retry started'
            ]);
        } catch (\Exception $e) {
            Log::error("PROJECT_CONTROLLER: Failed to start retry", [
                'project_id' => $project->id ?? 'unknown',
                'error_message' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            // The retry job never queued — give the charged credits back.
            app(CreditService::class)->refund($project);

            return response()->json([
                'success' => false,
                'message' => 'Failed to start retry: ' . $e->getMessage()
            ], 500);
        }
    }
}
