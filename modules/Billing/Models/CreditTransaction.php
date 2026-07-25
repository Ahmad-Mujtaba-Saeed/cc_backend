<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\User\Models\User;

/**
 * Immutable ledger entry for every credit movement (grant / debit / refund).
 * Provides the audit trail and the idempotency key (`reference`) used to make
 * refunds safe to retry.
 */
class CreditTransaction extends Model
{
    protected $table = 'credit_transactions';

    protected $fillable = [
        'user_id',
        'project_id',
        'type',
        'amount',
        'balance_after',
        'template_type',
        'reason',
        'reference',
    ];

    protected $casts = [
        'amount' => 'integer',
        'balance_after' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
