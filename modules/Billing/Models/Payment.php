<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\User\Models\User;
use Modules\Billing\Models\Plan;
use OwenIt\Auditing\Contracts\Auditable;


class Payment extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'payments';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'related_type_id',
        'related_type',
        'note',
        'payment_status',
        'payment_amount',
        'payment_currency',
        'payment_gateway',
        'payment_transaction_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'payment_amount' => 'integer',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array
     */
    protected $attributes = [
        'payment_status' => 'pending',
    ];

    /**
     * Get the user that owns the payment.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the related model that the payment is for.
     */
    public function related()
    {
        // If related_type is not set, return a generic belongsTo relationship
        if (empty($this->related_type)) {
            return $this->belongsTo(Plan::class, 'related_type_id')
                ->whereRaw('1=0');
        }

        // Check if it's already a fully qualified class name
        if (class_exists($this->related_type)) {
            return $this->morphTo('related', 'related_type', 'related_type_id');
        }
        
        // Try to resolve the class with the full namespace
        $fullClass = 'Modules\\Billing\\Models\\' . $this->related_type;
        if (class_exists($fullClass)) {
            $this->related_type = $fullClass;
            return $this->morphTo('related', 'related_type', 'related_type_id');
        }
        
        // Special case for Plan
        if ($this->related_type === 'Plan' || $this->related_type === 'Modules\\Billing\\Models\\Plan') {
            $this->related_type = 'Modules\\Billing\\Models\\Plan';
            return $this->belongsTo(Plan::class, 'related_type_id')
                ->withDefault();
        }

        // Log the related_type for debugging
        if (app()->bound('log')) {
            \Log::info('Related type:', [
                'related_type' => $this->related_type,
                'related_type_id' => $this->related_type_id,
                'payment_id' => $this->id
            ]);
        }

        // If we get here, we couldn't resolve the relationship
        // Return a relation that won't throw an error
        if (app()->bound('log')) {
            \Log::warning('Could not resolve related model type', [
                'related_type' => $this->related_type,
                'related_type_id' => $this->related_type_id
            ]);
        }
        
        return $this->belongsTo(Plan::class, 'related_type_id')
            ->whereRaw('1=0'); // This will return no results but won't throw an error
    }

    /**
     * Scope a query to only include pending payments.
     */
    public function scopePending($query)
    {
        return $query->where('payment_status', 'pending');
    }

    /**
     * Scope a query to only include completed payments.
     */
    public function scopeCompleted($query)
    {
        return $query->where('payment_status', 'completed');
    }

    /**
     * Mark the payment as completed.
     */
    public function markAsCompleted()
    {
        $this->update(['payment_status' => 'completed']);
    }
}
