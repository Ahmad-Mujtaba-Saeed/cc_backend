<?php

namespace Modules\Billing\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Exceptions\InsufficientCreditsException;
use Modules\Billing\Models\CreditTransaction;
use Modules\Project\Models\Project;
use Modules\Project\Services\TemplateSettingsService;
use Modules\User\Models\User;

/**
 * CreditService — the single source of truth for credit balances.
 *
 * Rules:
 *  - Only users with an active subscription ever hold credits.
 *  - Credits refill DAILY to the plan's `daily_credits` with NO rollover.
 *  - Each template render costs `config('credits.templates')` credits.
 *  - Charging is atomic (row lock) so concurrent requests cannot double-spend.
 *  - Refunds are idempotent via the `reference` on the ledger.
 */
class CreditService
{
    /**
     * Apply the daily allotment if the user is subscribed and hasn't been
     * refreshed today. No-op (and resets to 0) when not subscribed. Lazy:
     * call this before reading a balance or charging.
     */
    public function syncDailyGrant(User $user): void
    {
        $subscription = $user->activeSubscription();
        $today = now()->toDateString();

        // Not subscribed: no credits. Zero out any stale balance once.
        if (!$subscription) {
            if ((int) $user->credits !== 0 || $user->credits_refreshed_on !== null) {
                $user->forceFill(['credits' => 0, 'credits_refreshed_on' => null])->save();
            }
            return;
        }

        $alreadyToday = $user->credits_refreshed_on
            && $user->credits_refreshed_on->toDateString() === $today;
        if ($alreadyToday) {
            return;
        }

        $daily = (int) ($subscription->plan->daily_credits ?? 0);

        DB::transaction(function () use ($user, $daily, $today) {
            $locked = User::whereKey($user->getKey())->lockForUpdate()->first();
            if (!$locked) {
                return;
            }

            // Re-check inside the lock to avoid a double grant under a race.
            if ($locked->credits_refreshed_on && $locked->credits_refreshed_on->toDateString() === $today) {
                $user->setRawAttributes($locked->getAttributes(), true);
                return;
            }

            // No rollover: set (not add) to the daily allotment.
            $locked->forceFill([
                'credits' => $daily,
                'credits_refreshed_on' => $today,
            ])->save();

            $this->log($locked, 'grant', $daily, $locked->credits, null, null, 'Daily credit grant', 'grant:' . $locked->id . ':' . $today);

            // Keep the passed-in instance in sync.
            $user->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Credit cost for a template type.
     */
    public function costFor(string $templateType): int
    {
        $override = TemplateSettingsService::creditCost($templateType);
        if ($override !== null) {
            return $override;
        }

        $map = (array) config('credits.templates', []);

        return (int) ($map[$templateType] ?? config('credits.default', 3));
    }

    /**
     * Snapshot for gating + UI: subscription status, balance, cost, affordability.
     */
    public function canAfford(User $user, string $templateType): array
    {
        $this->syncDailyGrant($user);

        $subscribed = $user->hasActiveSubscription();
        $balance = (int) $user->fresh()->credits;
        $cost = $this->costFor($templateType);

        return [
            'subscribed' => $subscribed,
            'balance' => $balance,
            'cost' => $cost,
            'affordable' => $subscribed && $balance >= $cost,
        ];
    }

    /**
     * Atomically deduct $cost from the user for a project render. Re-checks the
     * balance under a row lock. Throws InsufficientCreditsException if short.
     */
    public function charge(User $user, Project $project, int $cost): void
    {
        DB::transaction(function () use ($user, $project, $cost) {
            $locked = User::whereKey($user->getKey())->lockForUpdate()->first();

            if (!$locked || (int) $locked->credits < $cost) {
                throw new InsufficientCreditsException((int) ($locked->credits ?? 0), $cost);
            }

            $locked->decrement('credits', $cost);
            $locked->refresh();

            $this->log(
                $locked,
                'debit',
                -$cost,
                (int) $locked->credits,
                $project->template_type,
                $project->id,
                'Render charge',
                'charge:project:' . $project->id
            );

            $user->setRawAttributes($locked->getAttributes(), true);
        });

        Log::info('CreditService: charged render', [
            'user_id' => $user->id,
            'project_id' => $project->id,
            'cost' => $cost,
            'balance_after' => (int) $user->credits,
        ]);
    }

    /**
     * Refund the charge for a project's render. Idempotent: if a refund already
     * exists for the latest charge, or no charge exists, it does nothing.
     */
    public function refund(Project $project): void
    {
        DB::transaction(function () use ($project) {
            $chargeRef = 'charge:project:' . $project->id;
            $refundRef = 'refund:project:' . $project->id;

            $charge = CreditTransaction::where('reference', $chargeRef)
                ->where('type', 'debit')
                ->latest('id')
                ->first();

            if (!$charge) {
                return; // never charged (e.g. legacy project)
            }

            // Already refunded a charge at/after this one? Then we're done.
            $alreadyRefunded = CreditTransaction::where('reference', $refundRef)
                ->where('type', 'refund')
                ->where('id', '>', $charge->id)
                ->exists();
            if ($alreadyRefunded) {
                return;
            }

            $amount = abs($charge->amount);

            $locked = User::whereKey($charge->user_id)->lockForUpdate()->first();
            if (!$locked) {
                return;
            }

            $locked->increment('credits', $amount);
            $locked->refresh();

            $this->log(
                $locked,
                'refund',
                $amount,
                (int) $locked->credits,
                $project->template_type,
                $project->id,
                'Refund for failed render',
                $refundRef
            );

            Log::info('CreditService: refunded failed render', [
                'user_id' => $locked->id,
                'project_id' => $project->id,
                'amount' => $amount,
                'balance_after' => (int) $locked->credits,
            ]);
        });
    }

    private function log(User $user, string $type, int $amount, int $balanceAfter, ?string $templateType, ?int $projectId, string $reason, ?string $reference): void
    {
        CreditTransaction::create([
            'user_id' => $user->id,
            'project_id' => $projectId,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'template_type' => $templateType,
            'reason' => $reason,
            'reference' => $reference,
        ]);
    }
}
