<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectCollection;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\WorkspaceMember;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenApi\Annotations as OA;

class ProjectController extends Controller
{
    use AuthorizesRequests;

    private const ALLOWED_STATUSES = ['active', 'archived', 'completed'];

    private const ALLOWED_SORT_FIELDS = ['name', 'created_at', 'updated_at', 'status'];

    /**
     * @OA\Get(
     *     path="/api/v1/projects",
     *     tags={"Projects"},
     *     summary="List projects in the current workspace",
     *     operationId="projectsIndex",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="X-Workspace-ID", in="header", required=false, description="Workspace context header (one resolution strategy)", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", minimum=1, maximum=100)),
     *     @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer", minimum=1)),
     *     @OA\Parameter(name="status", in="query", required=false, description="Comma-separated statuses", @OA\Schema(type="string", example="active,archived")),
     *     @OA\Parameter(name="created_by", in="query", required=false, description="User ID or 'me'", @OA\Schema(type="string", example="me")),
     *     @OA\Parameter(name="search", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="sort", in="query", required=false, description="Comma-separated sortable fields, prefix with - for desc", @OA\Schema(type="string", example="-created_at,name")),
     *
     *     @OA\Response(response=200, description="Project collection"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Workspace not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function index(Request $request): ProjectCollection
    {
        $this->ensureWorkspaceContext();
        $this->authorize('viewAny', Project::class);

        $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string'],
            'created_by' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'string'],
        ]);

        $perPage = min($request->integer('per_page', 15), 100);

        $query = Project::with(['creator', 'updater']);

        if ($request->filled('status')) {
            $query->whereIn('status', $this->parseStatuses($request->string('status')->toString()));
        }

        if ($request->filled('created_by')) {
            $creatorId = $this->resolveCreatorFilter(
                $request->string('created_by')->toString(),
                (int) $request->user()->id
            );

            $query->where('created_by', $creatorId);
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $sortInput = $request->filled('sort')
            ? $request->string('sort')->toString()
            : '-created_at';

        foreach ($this->parseSortTokens($sortInput) as [$field, $direction]) {
            $query->orderBy($field, $direction);
        }

        $projects = $query
            ->paginate($perPage);

        return new ProjectCollection($projects);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/projects",
     *     tags={"Projects"},
     *     summary="Create a project in the current workspace",
     *     operationId="projectsStore",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="X-Workspace-ID", in="header", required=false, description="Workspace context header (one resolution strategy)", @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"name","status"},
     *
     *             @OA\Property(property="name", type="string", example="Marketing Website"),
     *             @OA\Property(property="description", type="string", nullable=true),
     *             @OA\Property(property="status", type="string", example="active")
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="Project created"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Workspace not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(StoreProjectRequest $request): JsonResponse
    {
        $this->ensureWorkspaceContext();
        $this->authorize('create', Project::class);

        try {
            $project = DB::transaction(function () use ($request): Project {
                $validated = $request->validated();

                $this->lockCurrentWorkspaceForWrite();
                $this->assertNoActiveProjectNameConflict($validated['name']);

                return Project::create($validated);
            });
        } catch (QueryException $e) {
            if ($this->isProjectNameUniqueViolation($e)) {
                throw ValidationException::withMessages([
                    'name' => ['The name has already been taken in this workspace.'],
                ]);
            }
            throw $e;
        }

        return (new ProjectResource($project->load('creator')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/projects/{project}",
     *     tags={"Projects"},
     *     summary="Show a project",
     *     operationId="projectsShow",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="X-Workspace-ID", in="header", required=false, description="Workspace context header (one resolution strategy)", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="project", in="path", required=true, description="Project ID", @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Project details"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function show(Project $project): ProjectResource
    {
        $this->ensureWorkspaceContext();
        $this->authorize('view', $project);

        return new ProjectResource($project->load(['creator', 'updater']));
    }

    /**
     * @OA\Patch(
     *     path="/api/v1/projects/{project}",
     *     tags={"Projects"},
     *     summary="Update a project",
     *     operationId="projectsUpdate",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="X-Workspace-ID", in="header", required=false, description="Workspace context header (one resolution strategy)", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="project", in="path", required=true, description="Project ID", @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="description", type="string", nullable=true),
     *             @OA\Property(property="status", type="string", example="archived")
     *         )
     *     ),
     *
     *     @OA\Response(response=200, description="Project updated"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(UpdateProjectRequest $request, Project $project): ProjectResource
    {
        $this->ensureWorkspaceContext();
        $this->authorize('update', $project);

        try {
            DB::transaction(function () use ($project, $request): void {
                $validated = $request->validated();

                $this->lockCurrentWorkspaceForWrite();

                if (array_key_exists('name', $validated)) {
                    $this->assertNoActiveProjectNameConflict($validated['name'], $project->id);
                }

                $project->update($validated);
            });
        } catch (QueryException $e) {
            if ($this->isProjectNameUniqueViolation($e)) {
                throw ValidationException::withMessages([
                    'name' => ['The name has already been taken in this workspace.'],
                ]);
            }
            throw $e;
        }

        return new ProjectResource($project->refresh()->load(['creator', 'updater']));
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/projects/{project}",
     *     tags={"Projects"},
     *     summary="Soft-delete a project",
     *     operationId="projectsDestroy",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="X-Workspace-ID", in="header", required=false, description="Workspace context header (one resolution strategy)", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="project", in="path", required=true, description="Project ID", @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=204, description="Project deleted"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function destroy(Project $project): Response
    {
        $this->ensureWorkspaceContext();
        $this->authorize('delete', $project);

        $project->delete();

        return response()->noContent();
    }

    /**
     * @OA\Post(
     *     path="/api/v1/projects/{id}/restore",
     *     tags={"Projects"},
     *     summary="Restore a soft-deleted project",
     *     operationId="projectsRestore",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="X-Workspace-ID", in="header", required=false, description="Workspace context header (one resolution strategy)", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="id", in="path", required=true, description="Project ID", @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Project restored"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function restore(int $id): ProjectResource
    {
        $this->ensureWorkspaceContext();

        /** @var Project $project */
        $project = Project::withTrashed()->findOrFail($id);

        $this->authorize('restore', $project);

        if ($project->deleted_at === null) {
            throw ValidationException::withMessages([
                'status' => ['Project is not deleted.'],
            ]);
        }

        DB::transaction(function () use ($project): void {
            $this->lockCurrentWorkspaceForWrite();
            $this->assertNoActiveProjectNameConflict(
                (string) $project->name,
                $project->id,
                'A project with this name already exists in the workspace.'
            );

            $project->restore();
        });

        return new ProjectResource($project->refresh()->load(['creator', 'updater']));
    }

    private function ensureWorkspaceContext(): void
    {
        if (current_workspace_id() === null) {
            abort(404, 'Workspace not found.');
        }
    }

    private function lockCurrentWorkspaceForWrite(): void
    {
        DB::table('workspaces')
            ->where('id', current_workspace_id())
            ->lockForUpdate()
            ->first();
    }

    private function assertNoActiveProjectNameConflict(
        string $name,
        ?int $ignoreProjectId = null,
        string $message = 'The name has already been taken in this workspace.'
    ): void {
        $query = Project::query()->where('name', $name);

        if ($ignoreProjectId !== null) {
            $query->whereKeyNot($ignoreProjectId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => [$message],
            ]);
        }
    }

    private function isProjectNameUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $message = strtolower((string) ($e->errorInfo[2] ?? $e->getMessage()));

        if (! in_array($sqlState, ['23000', '23505'], true)) {
            return false;
        }

        // MySQL duplicate entry (legacy unique index still present or rollback scenarios).
        if ($driverCode === 1062 && str_contains($message, 'project')) {
            return str_contains($message, 'workspace_id_name')
                || (str_contains($message, 'workspace_id') && str_contains($message, 'name'));
        }

        // SQLite duplicate unique constraint on projects(workspace_id, name).
        if (str_contains($message, 'unique constraint failed')) {
            return str_contains($message, 'projects.workspace_id')
                && str_contains($message, 'projects.name');
        }

        // PostgreSQL duplicate unique violation (if applicable in some environments).
        if (str_contains($message, 'duplicate key value violates unique constraint')) {
            return str_contains($message, 'project')
                && (str_contains($message, 'workspace_id') || str_contains($message, 'name'));
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function parseStatuses(string $rawStatus): array
    {
        $statuses = collect(explode(',', $rawStatus))
            ->map(fn (string $status) => trim($status))
            ->filter(fn (string $status) => $status !== '')
            ->values();

        if ($statuses->isEmpty()) {
            throw ValidationException::withMessages([
                'status' => ['The status filter must contain at least one valid status.'],
            ]);
        }

        $invalid = $statuses->reject(fn (string $status) => in_array($status, self::ALLOWED_STATUSES, true));

        if ($invalid->isNotEmpty()) {
            throw ValidationException::withMessages([
                'status' => ['Invalid status filter. Allowed values: active, archived, completed.'],
            ]);
        }

        return $statuses->unique()->values()->all();
    }

    private function resolveCreatorFilter(string $rawCreatedBy, int $authenticatedUserId): int
    {
        if ($rawCreatedBy === 'me') {
            return $authenticatedUserId;
        }

        if (! ctype_digit($rawCreatedBy)) {
            throw ValidationException::withMessages([
                'created_by' => ['The created_by filter must be a user id or "me".'],
            ]);
        }

        $creatorId = (int) $rawCreatedBy;

        $isWorkspaceMember = WorkspaceMember::query()
            ->where('workspace_id', current_workspace_id())
            ->where('user_id', $creatorId)
            ->exists();

        if (! $isWorkspaceMember) {
            throw ValidationException::withMessages([
                'created_by' => ['The selected creator must be an active member of the current workspace.'],
            ]);
        }

        return $creatorId;
    }

    /**
     * @return array<int, array{0: string, 1: 'asc'|'desc'}>
     */
    private function parseSortTokens(string $rawSort): array
    {
        $tokens = collect(explode(',', $rawSort))
            ->map(fn (string $token) => trim($token))
            ->filter(fn (string $token) => $token !== '')
            ->values();

        if ($tokens->isEmpty()) {
            throw ValidationException::withMessages([
                'sort' => ['The sort filter must contain at least one valid field.'],
            ]);
        }

        return $tokens->map(function (string $token) {
            $leadingDashCount = strspn($token, '-');

            if ($leadingDashCount > 1) {
                throw ValidationException::withMessages([
                    'sort' => ['Invalid sort field. Allowed values: name, created_at, updated_at, status.'],
                ]);
            }

            $direction = $leadingDashCount === 1 ? 'desc' : 'asc';
            $field = $leadingDashCount === 1 ? substr($token, 1) : $token;

            if ($field === '' || ! in_array($field, self::ALLOWED_SORT_FIELDS, true)) {
                throw ValidationException::withMessages([
                    'sort' => ['Invalid sort field. Allowed values: name, created_at, updated_at, status.'],
                ]);
            }

            return [$field, $direction];
        })->all();
    }
}
