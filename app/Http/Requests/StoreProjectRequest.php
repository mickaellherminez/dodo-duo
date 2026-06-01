<?php

namespace App\Http\Requests;

use App\Rules\UniqueInWorkspace;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
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
                'required',
                'string',
                'max:255',
                new UniqueInWorkspace('projects', 'name'),
            ],
            'description' => ['nullable', 'string'],
            'status' => ['required', 'string', 'in:active,archived,completed'],
        ];
    }
}
