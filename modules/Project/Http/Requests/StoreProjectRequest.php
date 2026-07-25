<?php

namespace Modules\Project\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
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
            'title' => 'required|string|max:255|min:3',
            'description' => 'nullable|string|max:1000',
            'template_type' => 'required|string|in:simple_video,yt_automation_short,ai_image_based_shorts,ai_horror_shorts,yt_gameplay_short,yt_compilation_short,ranking_moments_short,template_a,template_b,template_c',
            'settings' => 'nullable|array',
            // Settings are schema-driven and may be strings, booleans (checkbox
            // fields) or numbers. Forcing `string` here rejected boolean fields
            // like crop_to_faces; allow any scalar and let processors type them.
            'settings.*' => 'nullable',
            'is_public' => 'boolean',
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
            'title.required' => 'Project title is required.',
            'title.min' => 'Project title must be at least 3 characters.',
            'title.max' => 'Project title may not be greater than 255 characters.',
            'description.max' => 'Project description may not be greater than 1000 characters.',
            'template_type.required' => 'Template type is required.',
            'template_type.in' => 'Selected template type is invalid.',
            'is_public.boolean' => 'Is public field must be true or false.',
        ];
    }
}
