<?php

namespace App\Http\Controllers;

use App\Models\WorkspaceInvitation;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class WorkspaceInvitationAcceptController extends Controller
{
    public function __invoke(string $token): RedirectResponse
    {
        $invitation = WorkspaceInvitation::where('token', hash('sha256', $token))->first();

        if (! $invitation) {
            abort(Response::HTTP_NOT_FOUND, 'Invitation not found');
        }

        if ($invitation->isExpired()) {
            abort(Response::HTTP_GONE, 'This invitation has expired');
        }

        if (! $invitation->isPending()) {
            abort(Response::HTTP_CONFLICT, 'This invitation has already been used');
        }

        if (! auth()->check()) {
            return redirect()->guest(url('/login').'?invitation='.$token);
        }

        $invitation->loadMissing('workspace');
        $user = auth()->user();

        if (! $user->belongsToWorkspace($invitation->workspace)) {
            $invitation->workspace->addMember($user, $invitation->role);
        }

        $invitation->update([
            'status' => WorkspaceInvitation::STATUS_ACCEPTED,
            'accepted_at' => now(),
        ]);

        return redirect('/dashboard');
    }
}
