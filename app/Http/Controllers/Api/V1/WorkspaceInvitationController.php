<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\InviteWorkspaceMemberRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationNotification;
use App\Support\ApiResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use OpenApi\Annotations as OA;
use Symfony\Component\HttpFoundation\Response;

class WorkspaceInvitationController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/v1/workspaces/{workspace}/invitations",
     *     tags={"Team"},
     *     summary="List pending workspace invitations",
     *     operationId="workspaceInvitationsIndex",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="workspace", in="path", required=true, description="Workspace ID", @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Pending invitations"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Workspace not found")
     * )
     * List pending invitations for a workspace (owner only).
     */
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->ensureOwner($request->user(), $workspace);

        $invitations = WorkspaceInvitation::where('workspace_id', $workspace->id)
            ->where('status', WorkspaceInvitation::STATUS_PENDING)
            ->get(['id', 'email', 'role', 'status', 'expires_at']);

        return response()->json([
            'data' => $invitations,
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/workspaces/{workspace}/invitations",
     *     tags={"Team"},
     *     summary="Create a workspace invitation",
     *     operationId="workspaceInvitationsStore",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="workspace", in="path", required=true, description="Workspace ID", @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"email","role"},
     *
     *             @OA\Property(property="email", type="string", format="email", example="teammate@example.com"),
     *             @OA\Property(property="role", type="string", example="member")
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="Invitation created"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Workspace not found"),
     *     @OA\Response(response=422, description="Validation error or duplicate invitation")
     * )
     * Store a newly created invitation.
     */
    public function store(InviteWorkspaceMemberRequest $request, Workspace $workspace): JsonResponse
    {
        $token = Str::random(64);
        $hashedToken = hash('sha256', $token);

        try {
            $invitation = WorkspaceInvitation::create([
                'workspace_id' => $workspace->id,
                'email' => strtolower($request->string('email')->toString()),
                'role' => $request->string('role')->toString(),
                'token' => $hashedToken,
                'status' => WorkspaceInvitation::STATUS_PENDING,
                'invited_by' => $request->user()->id,
                'expires_at' => now()->addDays(7),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'message' => 'An invitation is already pending for this email.',
                'errors' => ['email' => ['An invitation is already pending for this email.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $invitation->loadMissing(['workspace', 'inviter']);

        Notification::route('mail', $invitation->email)
            ->notify(new WorkspaceInvitationNotification($invitation, $token));

        return response()->json([
            'data' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'status' => $invitation->status,
                'expires_at' => $invitation->expires_at?->toISOString(),
                'accept_token' => $token,
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/invitations/{token}/accept",
     *     tags={"Team"},
     *     summary="Accept a workspace invitation",
     *     operationId="workspaceInvitationsAccept",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="token", in="path", required=true, description="Invitation token", @OA\Schema(type="string")),
     *
     *     @OA\Response(response=200, description="Invitation accepted"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Invitation not found"),
     *     @OA\Response(response=409, description="Invitation already used"),
     *     @OA\Response(response=410, description="Invitation expired")
     * )
     * Accept an invitation by token (authenticated user).
     */
    public function accept(Request $request, string $token): JsonResponse
    {
        $invitation = WorkspaceInvitation::where('token', hash('sha256', $token))->first();

        if (! $invitation) {
            return ApiResponse::error('Resource not found.', Response::HTTP_NOT_FOUND);
        }

        if ($invitation->isExpired()) {
            return response()->json(['message' => 'This invitation has expired.'], Response::HTTP_GONE);
        }

        if (! $invitation->isPending()) {
            return response()->json(['message' => 'This invitation has already been used.'], Response::HTTP_CONFLICT);
        }

        $user = $request->user();
        $invitation->loadMissing('workspace');

        if (strtolower($user->email) !== strtolower($invitation->email)) {
            return ApiResponse::error('This action is unauthorized.', Response::HTTP_FORBIDDEN);
        }

        if (! $user->belongsToWorkspace($invitation->workspace)) {
            $invitation->workspace->addMember($user, $invitation->role);
        }

        $invitation->update([
            'status' => WorkspaceInvitation::STATUS_ACCEPTED,
            'accepted_at' => now(),
        ]);

        return response()->json(['message' => 'Invitation accepted.']);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/invitations/{token}/decline",
     *     tags={"Team"},
     *     summary="Decline a workspace invitation",
     *     operationId="workspaceInvitationsDecline",
     *
     *     @OA\Parameter(name="token", in="path", required=true, description="Invitation token", @OA\Schema(type="string")),
     *
     *     @OA\Response(response=200, description="Invitation declined"),
     *     @OA\Response(response=404, description="Invitation not found"),
     *     @OA\Response(response=409, description="Invitation already used"),
     *     @OA\Response(response=410, description="Invitation expired")
     * )
     * Decline an invitation by token.
     */
    public function decline(string $token): JsonResponse
    {
        $invitation = WorkspaceInvitation::where('token', hash('sha256', $token))->first();

        if (! $invitation) {
            return ApiResponse::error('Resource not found.', Response::HTTP_NOT_FOUND);
        }

        if ($invitation->isExpired()) {
            return response()->json([
                'message' => 'This invitation has expired',
            ], Response::HTTP_GONE);
        }

        if (! $invitation->isPending()) {
            return response()->json([
                'message' => 'This invitation has already been used',
            ], Response::HTTP_CONFLICT);
        }

        $invitation->update([
            'status' => WorkspaceInvitation::STATUS_DECLINED,
        ]);

        return response()->json([
            'message' => 'Invitation declined.',
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/workspaces/{workspace}/invitations/{invitation}",
     *     tags={"Team"},
     *     summary="Cancel a pending invitation",
     *     operationId="workspaceInvitationsDestroy",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="workspace", in="path", required=true, description="Workspace ID", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="invitation", in="path", required=true, description="Invitation ID", @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=204, description="Invitation canceled"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Not found"),
     *     @OA\Response(response=409, description="Invitation not pending")
     * )
     * Cancel a pending invitation (owner only).
     */
    public function destroy(Request $request, Workspace $workspace, WorkspaceInvitation $invitation): Response
    {
        $this->ensureOwner($request->user(), $workspace);

        if ($invitation->workspace_id !== $workspace->id) {
            abort(Response::HTTP_NOT_FOUND);
        }

        if (! $invitation->isPending()) {
            return response()->json([
                'message' => 'Invitation is not pending.',
            ], Response::HTTP_CONFLICT);
        }

        $invitation->update([
            'status' => WorkspaceInvitation::STATUS_CANCELED,
        ]);

        return response()->noContent();
    }

    protected function ensureOwner(?User $user, Workspace $workspace): void
    {
        if (! $user || $workspace->owner_id !== $user->id) {
            abort(Response::HTTP_FORBIDDEN, 'Only the workspace owner can perform this action.');
        }
    }
}
