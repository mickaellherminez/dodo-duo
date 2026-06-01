<?php

use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\MyWorkspaceController;
use App\Http\Controllers\Api\V1\OAuthController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\VerificationController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use App\Http\Controllers\Api\V1\WorkspaceInvitationController;
use App\Http\Controllers\Api\V1\WorkspaceMemberController;
use App\Http\Middleware\SetCurrentWorkspace;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Health check — no auth required
Route::get('/health', function () {
    $checks = [];
    $healthy = true;

    // Database check
    try {
        \Illuminate\Support\Facades\DB::connection()->getPdo();
        $checks['database'] = 'ok';
    } catch (\Throwable $e) {
        $checks['database'] = 'error: '.$e->getMessage();
        $healthy = false;
    }

    // Cache check
    try {
        \Illuminate\Support\Facades\Cache::put('health_check', true, 10);
        \Illuminate\Support\Facades\Cache::has('health_check');
        \Illuminate\Support\Facades\Cache::forget('health_check');
        $checks['cache'] = 'ok';
    } catch (\Throwable $e) {
        $checks['cache'] = 'error: '.$e->getMessage();
        $healthy = false;
    }

    // Storage check
    try {
        if (is_writable(storage_path())) {
            $checks['storage'] = 'ok';
        } else {
            $checks['storage'] = 'fail';
            $healthy = false;
        }
    } catch (\Throwable $e) {
        $checks['storage'] = 'error: '.$e->getMessage();
        $healthy = false;
    }

    return response()->json([
        'status' => $healthy ? 'healthy' : 'unhealthy',
        'checks' => $checks,
        'timestamp' => now()->toIso8601String(),
    ], $healthy ? 200 : 503);
})->name('health');

Route::prefix('v1')->group(function () {
    // Invitation public actions
    Route::post('invitations/{token}/decline', [WorkspaceInvitationController::class, 'decline'])
        ->name('invitations.decline');

    // Invitation accept (authenticated)
    Route::middleware('auth:sanctum')->post('invitations/{token}/accept', [WorkspaceInvitationController::class, 'accept'])
        ->name('api.invitations.accept');

    // Public authentication routes with rate limiting
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])
            ->middleware('throttle:10,1') // 10 attempts per minute
            ->name('auth.register');
        Route::post('login', [AuthController::class, 'login'])
            ->middleware('throttle:5,1') // 5 attempts per minute (brute force protection)
            ->name('auth.login');
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])
            ->middleware('throttle:5,1') // 5 attempts per minute (prevent abuse)
            ->name('auth.forgot-password');
        Route::post('reset-password', [AuthController::class, 'resetPassword'])
            ->middleware('throttle:5,1') // 5 attempts per minute (prevent abuse)
            ->name('auth.reset-password');

        // OAuth social authentication (public)
        Route::get('google/redirect', [OAuthController::class, 'googleRedirect'])
            ->name('auth.google.redirect');
        Route::get('google/callback', [OAuthController::class, 'googleCallback'])
            ->name('auth.google.callback');
        Route::get('github/redirect', [OAuthController::class, 'githubRedirect'])
            ->name('auth.github.redirect');
        Route::get('github/callback', [OAuthController::class, 'githubCallback'])
            ->name('auth.github.callback');

        // Protected authentication routes
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout'])->name('auth.logout');
            Route::post('logout-all', [AuthController::class, 'logoutAll'])->name('auth.logout-all');
        });
    });

    // Email verification (signed URL)
    Route::get('email/verify/{id}/{hash}', [VerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    // Profile + verification notification routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [ProfileController::class, 'show'])->name('profile.show');
        Route::patch('me', [ProfileController::class, 'update'])->name('profile.update');
        Route::post('me/change-password', [ProfileController::class, 'changePassword'])->name('profile.change-password');

        Route::post('email/verification-notification', [VerificationController::class, 'resend'])
            ->middleware('throttle:6,1')
            ->name('verification.resend');
    });

    // Global admin routes (explicit escape hatch; workspace scope bypass is authorized)
    Route::middleware(['auth:sanctum', 'super_admin:viewAdminDashboard'])
        ->prefix('admin')
        ->group(function () {
            Route::get('dashboard', [AdminController::class, 'dashboard'])
                ->name('admin.dashboard');
        });

    // Protected workspace routes
    Route::middleware(['auth:sanctum', SetCurrentWorkspace::class])->group(function () {
        // My workspaces routes
        Route::get('my/workspaces', [MyWorkspaceController::class, 'index'])
            ->name('my.workspaces.index');
        Route::post('my/workspaces/{workspace}/switch', [MyWorkspaceController::class, 'switch'])
            ->name('my.workspaces.switch');
        Route::get('my/current-workspace', [MyWorkspaceController::class, 'currentWorkspace'])
            ->name('my.current-workspace');

        // Project CRUD routes
        Route::apiResource('projects', ProjectController::class);
        Route::post('projects/{id}/restore', [ProjectController::class, 'restore'])->name('projects.restore');

        // Workspace CRUD routes
        Route::apiResource('workspaces', WorkspaceController::class);

        // Workspace members
        Route::get('workspaces/{workspace}/members', [WorkspaceMemberController::class, 'index'])
            ->name('workspaces.members.index');
        Route::patch('workspaces/{workspace}/members/{user}', [WorkspaceMemberController::class, 'update'])
            ->name('workspaces.members.update');
        Route::delete('workspaces/{workspace}/members/{user}', [WorkspaceMemberController::class, 'destroy'])
            ->name('workspaces.members.destroy');

        // Workspace invitations
        Route::post('workspaces/{workspace}/invitations', [WorkspaceInvitationController::class, 'store'])
            ->name('workspaces.invitations.store');
        Route::get('workspaces/{workspace}/invitations', [WorkspaceInvitationController::class, 'index'])
            ->name('workspaces.invitations.index');
        Route::delete('workspaces/{workspace}/invitations/{invitation}', [WorkspaceInvitationController::class, 'destroy'])
            ->name('workspaces.invitations.destroy');

        // Workspace audit logs
        Route::get('workspaces/{workspace}/audit-logs', [AuditLogController::class, 'index'])
            ->name('workspaces.audit-logs.index');
    });
});
