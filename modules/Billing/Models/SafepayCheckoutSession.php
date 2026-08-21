<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\User\Models\User;

/**
 * One row per redirect into Safepay hosted Subscriptions Checkout.
 *
 * Safepay creates the subscription itself, so the `reference` on this row is
 * how the incoming `subscription.*` webhook is tied back to a local user and
 * plan. See the migration for the full rationale.
 */
class SafepayCheckoutSession extends Model
{
    protected $table = 'safepay_checkout_sessions';

    protected $primaryKey = 'reference';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'reference',
        'user_id',
        'plan_id',
        'safepay_plan_id',
        'status',
        'subscription_token',
        'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
