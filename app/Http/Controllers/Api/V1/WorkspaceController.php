<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWorkspaceRequest;
use App\Http\Requests\UpdateWorkspaceRequest;
use App\Http\Resources\WorkspaceResource;
use App\Models\Workspace;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use OpenApi\Annotations as OA;

class WorkspaceController extends Controller
{
    use AuthorizesRequests;

    /**
     * @OA\Get(
     *     path="/api/v1/workspaces",
     *     tags={"Workspaces"},
     *     summary="List workspaces accessible to the authenticated user",
     *     operationId="workspacesIndex",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", minimum=1, example=15)),
     *
     *     @OA\Response(response=200, description="Workspace collection"),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = $request->input('per_page', 15);

        $workspaces = Workspace::with(['owner', 'members'])
            ->where('owner_id', auth()->id())
            ->orWhereHas('members', function ($query) {
                $query->where('user_id', auth()->id());
            })
            ->paginate($perPage);

        return WorkspaceResource::collection($workspaces);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/workspaces",
     *     tags={"Workspaces"},
     *     summary="Create a workspace",
     *     operationId="workspacesStore",
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"name","slug"},
     *
     *             @OA\Property(property="name", type="string", example="Acme"),
     *             @OA\Property(property="slug", type="string", example="acme"),
     *             @OA\Property(property="domain", type="string", nullable=true, example="app.acme.test"),
     *             @OA\Property(property="settings", type="object")
     *         )
     *     ),
     *
     *     @OA\Response(response=200, description="Workspace created"),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     * Store a newly created resource in storage.
     */
    public function store(StoreWorkspaceRequest $request): WorkspaceResource
    {
        $workspace = DB::transaction(function () use ($request) {
            // Create workspace with authenticated user as owner
            $workspace = Workspace::create([
                ...$request->validated(),
                'owner_id' => auth()->id(),
            ]);

            // Automatically add creator as owner member with joined_at timestamp
            $workspace->addMember(auth()->user(), 'owner');

            return $workspace;
        });

        // Load relationships for resource
        $workspace->load(['owner', 'members']);

        return new WorkspaceResource($workspace);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/workspaces/{workspace}",
     *     tags={"Workspaces"},
     *     summary="Show a workspace",
     *     operationId="workspacesShow",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="workspace", in="path", required=true, description="Workspace ID", @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Workspace details"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Not found")
     * )
     * Display the specified resource.
     */
    public function show(Workspace $workspace): WorkspaceResource
    {
        $this->authorize('view', $workspace);

        $workspace->load(['owner', 'members']);

        return new WorkspaceResource($workspace);
    }

    /**
     * @OA\Patch(
     *     path="/api/v1/workspaces/{workspace}",
     *     tags={"Workspaces"},
     *     summary="Update a workspace",
     *     operationId="workspacesUpdate",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="workspace", in="path", required=true, description="Workspace ID", @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="name", type="string", example="Acme Inc."),
     *             @OA\Property(property="slug", type="string", example="acme-inc"),
     *             @OA\Property(property="domain", type="string", nullable=true),
     *             @OA\Property(property="settings", type="object")
     *         )
     *     ),
     *
     *     @OA\Response(response=200, description="Workspace updated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=404, description="Not found")
     * )
     * Update the specified resource in storage.
     */
    public function update(UpdateWorkspaceRequest $request, Workspace $workspace): WorkspaceResource
    {
        $this->authorize('update', $workspace);

        $data = $request->validated();

        // Merge settings if provided, otherwise keep existing
        if (isset($data['settings'])) {
            $data['settings'] = array_merge(
                $workspace->settings ?? [],
                $data['settings']
            );
        }

        $workspace->update($data);
        $workspace->load(['owner', 'members']);

        return new WorkspaceResource($workspace);
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/workspaces/{workspace}",
     *     tags={"Workspaces"},
     *     summary="Delete a workspace",
     *     operationId="workspacesDestroy",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="workspace", in="path", required=true, description="Workspace ID", @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=204, description="Workspace deleted"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Not found")
     * )
     * Remove the specified resource from storage.
     */
    public function destroy(Workspace $workspace): Response
    {
        $this->authorize('delete', $workspace);

        $workspace->delete();

        return response()->noContent();
    }
}
