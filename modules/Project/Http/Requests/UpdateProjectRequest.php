<?php

namespace Modules\Project\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:255|min:3',
            'description' => 'nullable|string|max:1000',
            'template_type' => 'sometimes|string|in:simple_video,yt_automation_short,ai_image_based_shorts,ai_horror_shorts,yt_gameplay_short,yt_compilation_short,template_a,template_b,template_c',
            'settings' => 'nullable|array',
            // See StoreProjectRequest: allow scalar settings (string/bool/number)
            // so checkbox fields (booleans) are not rejected.
            'settings.*' => 'nullable',
            'is_public' => 'sometimes|boolean',
            'status' => 'sometimes|string|in:draft,processing,completed,failed,published',
            'progress' => 'sometimes|integer|min:0|max:100',
            'error_message' => 'nullable|string|max:500',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.min' => 'Project title must be at least 3 characters.',
            'title.max' => 'Project title may not be greater than 255 characters.',
            'description.max' => 'Project description may not be greater than 1000 characters.',
            'template_type.in' => 'Selected template type is invalid.',
            'status.in' => 'Selected status is invalid.',
            'progress.min' => 'Progress must be at least 0.',
            'progress.max' => 'Progress may not be greater than 100.',
            'error_message.max' => 'Error message may not be greater than 500 characters.',
        ];
    }
}
