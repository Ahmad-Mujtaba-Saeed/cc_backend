<?php

namespace Modules\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePlanRequest extends FormRequest
{
    public function rules()
    {
        return [
            'name' => 'required|string',
            'price' => 'required|numeric|min:1',
            'currency' => 'required|string|size:3',
            'interval' => 'required|in:day,week,month,year',
            'interval_count' => 'required|integer|min:1',
            'subdesc' => 'required|string',
            'features' => 'required|array',
            'daily_credits' => 'sometimes|integer|min:0',
            'tier' => 'sometimes|nullable|string',
            'is_popular' => 'sometimes|boolean',
            // Safepay applies the free trial at the plan level.
            'trial_period_days' => 'sometimes|integer|min:0|max:365',
        ];
    }
}