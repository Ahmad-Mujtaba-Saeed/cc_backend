<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class Plan extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'name',
        'price',
        'daily_credits',
        'tier',
        'is_popular',
        'subdesc',
        'currency',
        'interval',
        'interval_count',
        'stripe_product_id',
        'stripe_price_id',
        'features',
        'is_active',
    ];

    protected $casts = [
        'features' => 'array',
        'daily_credits' => 'integer',
        'is_popular' => 'boolean',
        'is_active' => 'boolean',
        'price' => 'decimal:2',
    ];

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }
}
