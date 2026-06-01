<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkspaceMemberRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled via policy in controller
    }

    public function rules(): array
    {
        return [
            'role' => [
                'required',
                'string',
                Rule::in(['owner', 'admin', 'member', 'guest']),
            ],
        ];
    }
}
