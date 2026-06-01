<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateWorkspaceMemberRoleRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\ApiResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Annotations as OA;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class WorkspaceMemberController extends Controller
{
    use AuthorizesRequests;

    /**
     * @OA\Get(
     *     path="/api/v1/workspaces/{workspace}/members",
     *     tags={"Team"},
     *     summary="List workspace members",
     *     operationId="workspaceMembersIndex",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="workspace", in="path", required=true, description="Workspace ID", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="role", in="query", required=false, @OA\Schema(type="string", example="admin")),
     *     @OA\Parameter(name="search", in="query", required=false, @OA\Schema(type="string", example="alice")),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", minimum=1, maximum=100)),
     *
     *     @OA\Response(response=200, description="Members list"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Workspace not found")
     * )
     * List all active members of a workspace.
     * Supports ?role= filter and ?search= (name/email) filter.
     * Ordered: owner → admin → member → guest.
     */
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('viewAny', [WorkspaceMember::class, $workspace]);

        $query = WorkspaceMember::where('workspace_id', $workspace->id)
            ->join('users', 'users.id', '=', 'workspace_members.user_id')
            ->select('workspace_members.*')
            ->with('user');

        if ($request->filled('role')) {
            $query->where('role', $request->string('role')->toString());
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('users.name', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%");
            });
        }

        $query->orderByRaw("CASE role
            WHEN 'owner'  THEN 1
            WHEN 'admin'  THEN 2
            WHEN 'member' THEN 3
            WHEN 'guest'  THEN 4
            ELSE 5 END")
            ->orderBy('users.name');

        $perPage = min($request->integer('per_page', 15), 100);
        $paginated = $query->paginate($perPage);

        $data = $paginated->getCollection()->map(fn (WorkspaceMember $m) => [
            'user_id' => $m->user_id,
            'name' => $m->user->name,
            'email' => $m->user->email,
            'role' => $m->role,
            'joined_at' => $m->joined_at?->toISOString(),
        ]);

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/workspaces/{workspace}/members/{user}",
     *     tags={"Team"},
     *     summary="Remove a workspace member",
     *     operationId="workspaceMembersDestroy",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="workspace", in="path", required=true, description="Workspace ID", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="user", in="path", required=true, description="User ID", @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=204, description="Member removed"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Member not found"),
     *     @OA\Response(response=422, description="Cannot remove last owner")
     * )
     * Remove a member from a workspace (soft delete).
     * Owner only; prevents removing last owner.
     */
    public function destroy(Workspace $workspace, User $user): JsonResponse|HttpResponse
    {
        $member = WorkspaceMember::where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $member) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        $this->authorize('delete', $member);

        return DB::transaction(function () use ($workspace, $member) {
            if ($member->role === 'owner') {
                $ownerCount = WorkspaceMember::where('workspace_id', $workspace->id)
                    ->where('role', 'owner')
                    ->lockForUpdate()
                    ->count();

                if ($ownerCount <= 1) {
                    return response()->json(
                        ['message' => 'Cannot remove the last owner of a workspace.'],
                        HttpResponse::HTTP_UNPROCESSABLE_ENTITY
                    );
                }
            }

            $member->delete();

            return response()->noContent();
        });
    }

    /**
     * @OA\Patch(
     *     path="/api/v1/workspaces/{workspace}/members/{user}",
     *     tags={"Team"},
     *     summary="Update a workspace member role",
     *     operationId="workspaceMembersUpdate",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="workspace", in="path", required=true, description="Workspace ID", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="user", in="path", required=true, description="User ID", @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"role"},
     *
     *             @OA\Property(property="role", type="string", example="member")
     *         )
     *     ),
     *
     *     @OA\Response(response=200, description="Role updated"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Member not found"),
     *     @OA\Response(response=422, description="Validation error or last owner protection")
     * )
     * Update the role of a workspace member.
     * Owner can change any role; admin can only change member ↔ guest.
     */
    public function update(
        UpdateWorkspaceMemberRoleRequest $request,
        Workspace $workspace,
        User $user
    ): JsonResponse {
        $member = WorkspaceMember::where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $member) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        $this->authorize('update', $member);

        $newRole = $request->string('role')->toString();

        // Determine acting user's role in this workspace
        $actingMembership = WorkspaceMember::where('workspace_id', $workspace->id)
            ->where('user_id', $request->user()->id)
            ->first();

        $actingRole = $actingMembership?->role;

        if ($workspace->owner_id === $request->user()->id) {
            $actingRole = 'owner';
        }

        // Admins can only swap between member and guest
        if ($actingRole !== 'owner') {
            $allowedRoles = ['member', 'guest'];
            if (! in_array($member->role, $allowedRoles, true) || ! in_array($newRole, $allowedRoles, true)) {
                return ApiResponse::error('This action is unauthorized.', HttpResponse::HTTP_FORBIDDEN);
            }
        }

        return DB::transaction(function () use ($workspace, $member, $newRole) {
            // Prevent demoting last owner
            if ($member->role === 'owner' && $newRole !== 'owner') {
                $ownerCount = WorkspaceMember::where('workspace_id', $workspace->id)
                    ->where('role', 'owner')
                    ->lockForUpdate()
                    ->count();

                if ($ownerCount <= 1) {
                    return response()->json(
                        ['message' => 'Cannot demote the last owner of a workspace.'],
                        HttpResponse::HTTP_UNPROCESSABLE_ENTITY
                    );
                }
            }

            $member->update(['role' => $newRole]);

            return response()->json([
                'data' => [
                    'user_id' => $member->user_id,
                    'role' => $member->role,
                ],
            ]);
        });
    }
}
