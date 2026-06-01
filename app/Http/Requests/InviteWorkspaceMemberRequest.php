<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Models\WorkspaceInvitation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteWorkspaceMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $workspace = $this->route('workspace');

        if (! $user || ! $workspace) {
            return false;
        }

        if ($workspace->owner_id === $user->id) {
            return true;
        }

        return in_array($user->roleInWorkspace($workspace), ['owner', 'admin'], true);
    }

    public function rules(): array
    {
        $workspace = $this->route('workspace');
        $actingUser = $this->user();
        $isOwner = $workspace && $actingUser && $workspace->owner_id === $actingUser->id;

        // Admins can only assign member or guest; only owners can assign owner or admin.
        $allowedRoles = $isOwner
            ? ['owner', 'admin', 'member', 'guest']
            : ['member', 'guest'];

        return [
            'email' => [
                'required',
                'email',
                'max:255',
                function (string $attribute, $value, $fail) use ($workspace) {
                    if (! $workspace) {
                        return;
                    }

                    $normalizedEmail = strtolower($value);

                    $user = User::whereRaw('LOWER(email) = ?', [$normalizedEmail])->first();
                    if ($user && $user->belongsToWorkspace($workspace)) {
                        $fail('This user is already a member of the workspace.');

                        return;
                    }

                    $pendingInviteExists = WorkspaceInvitation::where('workspace_id', $workspace->id)
                        ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
                        ->where('status', WorkspaceInvitation::STATUS_PENDING)
                        ->exists();

                    if ($pendingInviteExists) {
                        $fail('An invitation is already pending for this email.');
                    }
                },
            ],
            'role' => [
                'required',
                Rule::in($allowedRoles),
            ],
        ];
    }
}
