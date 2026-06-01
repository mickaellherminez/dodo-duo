<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AdminController extends Controller
{
    /**
     * Minimal admin example endpoint demonstrating a safe workspace-scope bypass.
     */
    public function dashboard(Request $request): JsonResponse
    {
        // Defense in depth: middleware already checks super-admin, but authorize explicitly
        // before any cross-workspace query escape hatch is used.
        Gate::authorize('viewAdminDashboard');

        // ESCAPE HATCH (Story 6.4): this endpoint is intentionally global and must
        // bypass WorkspaceScope after super-admin authorization.
        $allProjects = Project::forAllWorkspaces();

        $projectsByStatus = (clone $allProjects)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();

        $counts = [
            'workspaces' => Workspace::query()->count(),
            'projects' => [
                'total' => (clone $allProjects)->count(),
                'by_status' => [
                    'active' => $projectsByStatus['active'] ?? 0,
                    'archived' => $projectsByStatus['archived'] ?? 0,
                    'completed' => $projectsByStatus['completed'] ?? 0,
                ],
            ],
        ];

        AuditService::log(
            AuditEvent::ADMIN_DASHBOARD_VIEWED,
            $request->user(),
            null,
            [
                'action' => 'admin.dashboard.view',
                'route' => (string) $request->route()?->getName(),
                'scope' => [
                    'workspace_scope_bypassed' => true,
                    'helper' => 'forAllWorkspaces',
                ],
                'counts' => $counts,
            ]
        );

        return response()->json([
            'data' => [
                'scope' => [
                    'workspace_scope_bypassed' => true,
                    'helper' => 'forAllWorkspaces',
                ],
                'counts' => $counts,
            ],
        ]);
    }
}
