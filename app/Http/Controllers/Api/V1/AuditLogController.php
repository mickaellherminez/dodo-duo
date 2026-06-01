<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Workspace;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [AuditLog::class, $workspace]);

        $request->validate([
            'event' => ['nullable', 'string'],
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = AuditLog::with(['user'])
            ->where('workspace_id', $workspace->id)
            ->latest();

        if ($request->filled('event')) {
            $query->where('event', $request->string('event'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->date('to')->endOfDay());
        }

        return AuditLogResource::collection($query->paginate(50));
    }
}
