<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkspaceResource;
use App\Models\Workspace;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class MyWorkspaceController extends Controller
{
    /**
     * Get all workspaces the authenticated user belongs to.
     */
    public function index(): AnonymousResourceCollection
    {
        $workspaces = auth()->user()
            ->workspaces()
            ->with(['owner', 'members'])
            ->latest('workspace_members.created_at')
            ->get();

        return WorkspaceResource::collection($workspaces);
    }

    /**
     * Switch to a different workspace.
     * Issues a new Sanctum token with workspace context.
     */
    public function switch(Workspace $workspace): JsonResponse
    {
        $user = auth()->user();

        // Verify user is a member of the target workspace
        if (! $user->belongsToWorkspace($workspace)) {
            return ApiResponse::error('This action is unauthorized.', Response::HTTP_FORBIDDEN);
        }

        // Issue new token with workspace context in abilities
        $token = $user->createToken('api', ["workspace:{$workspace->id}"]);

        return response()->json([
            'message' => 'Workspace switched successfully.',
            'workspace' => new WorkspaceResource($workspace),
            'token' => $token->plainTextToken,
        ]);
    }

    /**
     * Get the currently active workspace.
     */
    public function currentWorkspace(): JsonResponse|WorkspaceResource
    {
        $currentWorkspace = current_workspace();

        if (! $currentWorkspace) {
            return ApiResponse::error('Resource not found.', Response::HTTP_NOT_FOUND);
        }

        $workspace = $currentWorkspace->workspace;
        $user = auth()->user();

        return (new WorkspaceResource($workspace))->additional([
            'user_role' => $user->roleInWorkspace($workspace),
        ]);
    }
}
