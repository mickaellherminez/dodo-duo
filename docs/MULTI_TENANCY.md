# Multi-Tenancy Developer Guide

## Goal

This guide explains how SaaSForge enforces workspace isolation and how to build new tenant-safe features without breaking that guarantee.

## Multi-Tenancy Model (Workspace-Scoped)

SaaSForge uses a shared-database, shared-schema multi-tenant model:

- all tenants share the same tables
- tenant-owned rows carry a `workspace_id`
- requests are executed within a resolved workspace context
- models are filtered automatically by a global scope

## Isolation Invariants (Do Not Break)

These are core rules of the project:

- Tenant-scoped queries must not return rows from another workspace by default
- `workspace_id` must not be mutable after creation
- Cross-tenant access must be explicit, authorized, and auditable
- Route-level protections must match tenant-sensitive endpoints

## Workspace Resolution (Request Context)

`App\Http\Middleware\SetCurrentWorkspace` resolves the active workspace in a configured priority order (see `config/workspace.php`):

1. **Subdomain** (`acme.your-app.tld` -> `acme`)
2. **Custom domain** (`app.customer.com`)
3. **Header** (`X-Workspace-ID: 123`) - common for API clients/tests
4. **Route parameter** (`/api/v1/workspaces/{workspace}/...`)
5. **Token ability** (`workspace:{id}`) - optional/future-friendly fallback

If a strategy is clearly attempted but the workspace does not exist or is not active, the middleware aborts with `404` (except token strategy, which is optional).

### Workspace Access Verification

By default, resolved workspaces are checked against the authenticated user’s memberships/ownership.

- Config key: `workspace.verify_user_access`
- Env variable: `WORKSPACE_VERIFY_ACCESS=true`

If enabled, requests without access are rejected with `403`.

## Core Building Blocks

### `CurrentWorkspace` Service

The resolved workspace is stored in `App\Services\CurrentWorkspace` and bound into the Laravel container for the current request.

Typical usage patterns in app code:

- `current_workspace()` helper (returns service instance)
- `current_workspace_id()` helper (tenant key for queries/creates)

### `BelongsToWorkspace` Trait

Models that are tenant-owned should use `App\Models\Concerns\BelongsToWorkspace`.

What it gives you:

- automatic global query scope (`WorkspaceScope`)
- automatic `workspace_id` assignment on create (from current context)
- immutable `workspace_id` on update
- escape hatch scopes for admin/global flows

### `WorkspaceScope`

`App\Scopes\WorkspaceScope` automatically adds tenant filtering when a workspace context exists:

- no context -> no scope filter (useful for some bootstrap/admin scenarios)
- context exists -> `WHERE <table>.workspace_id = current_workspace_id()`

## Building a New Tenant-Scoped Resource

Use this checklist when introducing a new model/API endpoint.

### 1. Model

- Add `workspace_id` column (indexed, foreign key if applicable)
- Use `BelongsToWorkspace` trait
- Keep `workspace_id` guarded against unsafe mass-assignment patterns (if custom fillable logic exists)

### 2. Routes

Place tenant routes behind:

- `auth:sanctum`
- `SetCurrentWorkspace`

Example pattern from existing API groups:

```php
Route::middleware(['auth:sanctum', SetCurrentWorkspace::class])->group(function () {
    Route::apiResource('projects', ProjectController::class);
});
```

### 3. Policies / Middleware

- Enforce workspace ownership / membership checks in policies
- Use role/permission middleware where business rules require it
- Avoid relying on query scope alone for authorization decisions

### 4. Tests (Required)

Add tests for:

- happy path access in the correct workspace
- cross-tenant read denial
- cross-tenant update/delete denial
- forged `workspace_id` create attempts

The helper trait in `tests/Feature/Security/AdversarialTestCase.php` is designed for this.

## Approved Escape Hatches (Use Sparingly)

SaaSForge intentionally supports controlled scope bypasses for admin operations.

### High-Level Escape Hatch (Preferred)

- `Model::forAllWorkspaces()`
- Alias for `acrossWorkspaces()`
- Improves readability for explicitly global admin use cases

### Low-Level Escape Hatch (Use Only When Needed)

- `withoutGlobalScope(WorkspaceScope::class)`

### Rules for Any Escape Hatch

- Must be inside an approved admin/global use case
- Must be authorized (gate/policy/middleware)
- Should be audited when action is sensitive
- Must be covered by tests
- Must not be used to shortcut missing tenant route protection

## Route Validation Guardrail

Use the built-in command to detect tenant-sensitive routes missing workspace middleware:

```bash
php artisan workspace:validate-routes
```

You can also validate a subset:

```bash
php artisan workspace:validate-routes --prefix=api/v1/projects
```

## Common Pitfalls

- Forgetting `SetCurrentWorkspace` on authenticated tenant routes
- Using global queries in controllers without explicit authorization
- Assuming route model binding alone enforces tenant ownership
- Introducing new writes that allow `workspace_id` mutation
- Writing tests that pass without asserting tenant boundaries

## Practical Testing Pattern

For API tests, use the workspace header helper / pattern:

```php
$this->withHeaders(['X-Workspace-ID' => $workspace->id]);
```

This mirrors real API client behavior and exercises the middleware path.

## Related Documents

- [`ARCHITECTURE.md`](ARCHITECTURE.md)
- [`SECURITY.md`](SECURITY.md)
- [`TESTING.md`](TESTING.md)
