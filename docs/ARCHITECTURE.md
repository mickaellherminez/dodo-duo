# SaaSForge Architecture

## Purpose

This document explains the multi-tenant architecture used by SaaSForge and the technical patterns you should preserve when extending the codebase.

SaaSForge is a Laravel 11 API-first starter template for workspace-based SaaS applications. The main architectural goal is simple:

- keep tenant data isolated by default
- make cross-tenant access explicit and auditable
- keep developer workflows fast (tests, CI, deployment, local setup)

## Technology Stack

- Laravel 11 (PHP 8.2+)
- Laravel Sanctum (API authentication)
- Laravel Socialite (OAuth: Google/GitHub)
- Pest + PHPUnit runtime (testing)
- PHPStan + Larastan (static analysis)
- Laravel Pint (formatting)

## High-Level System View

```text
Client / Browser / API Consumer
            |
            v
      Laravel HTTP Kernel
            |
            v
  API Route Middleware Stack
  (auth, workspace resolution, role/permission checks)
            |
            v
 Controllers / Policies / Services
            |
            v
 Eloquent Models + WorkspaceScope
            |
            v
   Database (workspace-scoped rows)
```

### Tenant Data Flow (Core Idea)

```text
User -> Workspace Context -> Tenant-Scoped Resources
```

- `SetCurrentWorkspace` resolves which workspace the request is operating on.
- `CurrentWorkspace` stores that context for the duration of the request.
- Models using `BelongsToWorkspace` get automatic `WorkspaceScope` filtering.
- Policies and middleware provide additional authorization guarantees.

## Core Domain Concepts

### User

- Authenticates via Sanctum tokens or session-compatible flows.
- Can belong to multiple workspaces.
- May be a super admin (`is_super_admin`) for approved global operations.

### Workspace

- Tenant boundary for application data.
- Resolution inputs include subdomain, custom domain, route param, header, and token ability.
- Membership controls roles and permissions inside the tenant.

### Tenant-Scoped Resources

Examples in the current codebase include:

- `Project`
- workspace members / invitations
- `AuditLog` (workspace-aware access rules)

## Request Lifecycle (API)

### 1. Routing

Routes live in `routes/api.php`. Most application endpoints are grouped under `/api/v1`, while the health check is exposed at `/api/health`.

Key groups:

- public routes (`/api/health`, auth callbacks, public invitation actions)
- authenticated non-tenant routes (profile, auth logout, etc.)
- authenticated tenant routes (`auth:sanctum` + `SetCurrentWorkspace`)
- authenticated admin escape-hatch routes (`/api/v1/admin/*`)

### 2. Authentication

- Sanctum protects authenticated API endpoints.
- Public auth endpoints are throttled.
- Email verification routes use signed URLs and throttling.

### 3. Workspace Resolution

`App\Http\Middleware\SetCurrentWorkspace` resolves tenant context in configured priority order.

Configured priorities (`config/workspace.php`):

1. subdomain
2. custom domain
3. `X-Workspace-ID` header
4. route parameter `{workspace}`
5. token ability (`workspace:{id}`)

### 4. Authorization

Authorization is layered:

- route middleware (`RequireWorkspaceRole`, `RequireWorkspacePermission`, `RequireSuperAdmin`)
- Laravel gates (e.g. `viewAdminDashboard`)
- policies (`ProjectPolicy`, `AuditLogPolicy`, base workspace policy helpers)

### 5. Data Isolation at Model Layer

`App\Models\Concerns\BelongsToWorkspace` provides:

- global `WorkspaceScope` query filtering
- automatic `workspace_id` assignment from current context on create
- immutable `workspace_id` enforcement on update
- explicit escape hatch scopes (`acrossWorkspaces()` / `forAllWorkspaces()`)

## Multi-Tenancy Isolation Layers (Defense in Depth)

### Layer 1: Middleware Context Resolution

- Determines the active workspace for the request
- Verifies access (configurable via `WORKSPACE_VERIFY_ACCESS`)
- Binds `CurrentWorkspace` into the container

### Layer 2: Policy & Gate Authorization

- Prevents users from acting on resources they do not own/manage
- Allows explicit global operations only through approved admin paths

### Layer 3: Eloquent Scope Enforcement

- `WorkspaceScope` automatically adds `WHERE workspace_id = ?`
- Tenant filtering applies by default to all models using the trait

### Layer 4: Model Integrity Guards

- `workspace_id` is immutable after creation
- Create-time forged `workspace_id` attempts are overridden/blocked by patterns in the model/controller layers

### Layer 5: Adversarial Tests

- Security suite validates cross-tenant read/update/delete/create isolation
- Prevents regressions when routes/models evolve

## Security & Audit Architecture

### Audit Logging

`AuditService` records important events such as:

- workspace membership changes (via observers)
- approved admin escape-hatch usage (e.g. admin dashboard)

Audit metadata can include scope information to indicate when workspace scoping was intentionally bypassed.

### Escape Hatches (Documented, Restricted)

Approved cross-tenant access patterns exist for administrative needs, but they are:

- explicit (`forAllWorkspaces()` / `withoutGlobalScope(...)`)
- authorization-gated (super-admin checks)
- auditable (audit events + metadata)
- constrained by route validation rules (`workspace:validate-routes`)

## API & Operational Architecture

### Health & Operability

- `GET /api/health` checks database, cache, and storage writability
- `benchmark:run` validates performance against shared-hosting-friendly thresholds
- `queue:monitor` surfaces queue/failure counts
- `deploy:prod` automates cache rebuild + migration + storage link steps

### Performance Guardrails

`AppServiceProvider` enables:

- `Model::preventLazyLoading(! app()->isProduction())`
- slow query logging via `DB::listen()` for queries over 100ms

## Extending the Architecture Safely

When adding a new tenant-scoped feature:

1. Add/extend the model with `BelongsToWorkspace`
2. Expose routes under tenant middleware (`auth:sanctum` + workspace middleware)
3. Add/update policy rules
4. Add feature tests (including tenant isolation tests if resource is tenant-owned)
5. Validate route protection with `php artisan workspace:validate-routes`

## Related Documents

- [`docs/MULTI_TENANCY.md`](MULTI_TENANCY.md)
- [`docs/SECURITY.md`](SECURITY.md)
- [`docs/TESTING.md`](TESTING.md)
- [`docs/deployment/shared-hosting.md`](deployment/shared-hosting.md)
