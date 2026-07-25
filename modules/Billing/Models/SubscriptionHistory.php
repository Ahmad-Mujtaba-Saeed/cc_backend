<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\User\Models\User;
use Modules\Billing\Models\Subscription;
use OwenIt\Auditing\Contracts\Auditable;

class SubscriptionHistory extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'subscription_histories';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'subscription_id',
        'user_id',
        'name',
        'payment_id',
        'type',
        'type_id',
        'sub_id',
        'cus_id',
        'status',
        'trial_ends_at',
        'ends_at',
        'starts_at',
        'cancel_at_period_end',
        'action',
        'notes',
        'changed_by',
        'ip_address',
        'user_agent',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'trial_ends_at' => 'datetime',
        'ends_at' => 'datetime',
        'starts_at' => 'datetime',
        'cancel_at_period_end' => 'boolean',
    ];

    /**
     * Get the subscription that owns the history record.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Get the user that owns the subscription.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user who made the change.
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * Create a new history record from a subscription.
     */
    public static function createFromSubscription(
        Subscription $subscription,
        string $action,
        ?string $notes = null,
        ?int $changedById = null
    ): self {
        return static::create([
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'name' => $subscription->name,
            'payment_id' => $subscription->payment_id,
            'type' => $subscription->type,
            'type_id' => $subscription->type_id,
            'sub_id' => $subscription->sub_id,
            'cus_id' => $subscription->cus_id,
            'status' => $subscription->status,
            'trial_ends_at' => $subscription->trial_ends_at,
            'ends_at' => $subscription->ends_at,
            'starts_at' => $subscription->starts_at,
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
            'action' => $action,
            'notes' => $notes,
            'changed_by' => $changedById,
            'ip_address' => request() ? request()->ip() : null,
            'user_agent' => request() ? request()->userAgent() : null,
        ]);
    }
}
