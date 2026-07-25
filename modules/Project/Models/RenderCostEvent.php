<?php

namespace Modules\Project\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single money-costing external call recorded during a render pipeline
 * (see the migration for semantics). Written exclusively through
 * {@see \Modules\Project\Services\CostTracker}.
 */
class RenderCostEvent extends Model
{
    protected $table = 'render_cost_events';

    protected $fillable = [
        'project_id',
        'user_id',
        'template_type',
        'service',
        'label',
        'units',
        'unit',
        'cost_usd',
        'meta',
    ];

    protected $casts = [
        'project_id' => 'integer',
        'user_id' => 'integer',
        'units' => 'float',
        'cost_usd' => 'float',
        'meta' => 'array',
    ];
}
