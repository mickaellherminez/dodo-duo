<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkspaceRequest extends FormRequest
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
        $workspace = $this->route('workspace');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['prohibited'], // Slug cannot be updated
            'domain' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                'regex:/^([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{2,}$/',
                Rule::unique('workspaces', 'domain')->ignore($workspace->id ?? null),
            ],
            'settings' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /**
     * Get custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.prohibited' => 'The slug cannot be updated.',
            'domain.regex' => 'The domain must be a valid domain name.',
        ];
    }
}
