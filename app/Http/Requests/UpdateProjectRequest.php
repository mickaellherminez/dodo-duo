<?php

namespace App\Http\Requests;

use App\Rules\UniqueInWorkspace;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                new UniqueInWorkspace('projects', 'name', $this->route('project')->id),
            ],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', 'in:active,archived,completed'],
        ];
    }
}
