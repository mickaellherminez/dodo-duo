# Security Model & Practices

## Security Goals

SaaSForge is designed to make multi-tenant API applications secure by default, with an emphasis on tenant isolation and auditable exceptions.

Primary goals:

- prevent cross-tenant data leakage
- prevent unauthorized tenant access
- make privileged global access explicit and traceable
- provide regression tests for common attack paths

## Threat Model (Practical)

This project actively guards against:

- **Cross-tenant data access** by authenticated users targeting another workspace
- **Workspace ID injection** in create/update payloads
- **Missing tenant middleware** on authenticated API routes
- **Silent global queries** introduced during feature development
- **Untracked admin bypasses** of tenant scoping

## Core Security Controls

### 1. Tenant Context Resolution + Access Verification

`SetCurrentWorkspace` resolves the active workspace and (by default) verifies that the authenticated user has access.

Controls:

- multiple resolution strategies (subdomain/domain/header/route/token)
- inactive or missing workspace handling
- `403` on resolved-but-forbidden workspace access
- request-scoped binding of `CurrentWorkspace`

### 2. Automatic Query Scoping

Models using `BelongsToWorkspace` receive `WorkspaceScope` automatically.

Security impact:

- reduces risk of accidental cross-tenant reads
- applies guardrails at the model/query layer, not just controller code

### 3. Immutable `workspace_id`

`BelongsToWorkspace` prevents changing `workspace_id` after creation.

Why it matters:

- blocks resource reassignment between tenants after a record exists
- protects integrity even if an update path is incorrectly exposed

### 4. Authorization (Gates / Policies / Middleware)

Security is enforced in layers:

- Sanctum auth for protected API endpoints
- role/permission middleware for workspace actions
- policies for model-level access decisions
- super-admin-only middleware for approved global endpoints

## Adversarial Security Testing

The project includes a dedicated adversarial test helper for multi-tenant attack simulation:

- file: `tests/Feature/Security/AdversarialTestCase.php`
- target: cross-tenant isolation regressions

Scenarios covered by reusable helpers include:

- READ another tenant’s resource
- UPDATE another tenant’s resource
- DELETE another tenant’s resource
- CREATE resource while forging `workspace_id`

This suite is one of the strongest protections against subtle isolation regressions.

## Audit Logging & Traceability

### What Gets Audited

The project includes audit logging for security-sensitive actions, including:

- membership lifecycle events (add/change role/remove)
- approved admin actions (example: admin dashboard access)

### Why This Matters

Audit logs provide:

- traceability for privileged operations
- evidence of intentional scope bypass usage
- support for incident review and operational debugging

### Maintenance Command

Use the prune command to clean old logs:

```bash
php artisan audit:prune --days=90
```

## Documented Escape Hatches (Story 6.4)

SaaSForge allows controlled scope bypasses for legitimate admin/reporting features.

### Approved Patterns

- `Model::forAllWorkspaces()` (preferred readability alias)
- `Model::acrossWorkspaces()`
- `withoutGlobalScope(WorkspaceScope::class)` (low-level fallback)

### Required Conditions

Before using an escape hatch:

- authorize the action explicitly (gate/policy/middleware)
- restrict it to an approved route/use case (e.g. admin namespace)
- audit the action when it is sensitive
- cover it with tests
- document why the bypass is necessary

## Route Protection Validation

Use the built-in guardrail command to catch authenticated tenant-sensitive routes that are missing workspace middleware:

```bash
php artisan workspace:validate-routes
```

This command also recognizes approved super-admin escape-hatch routes (admin namespace) and prevents them from masking tenant route mistakes elsewhere.

## Operational Security Checklist

Before shipping a feature that touches tenant data:

1. Route uses `auth:sanctum` and tenant middleware where appropriate
2. Policy/middleware authorization paths are tested
3. Tenant-scoped models use `BelongsToWorkspace`
4. No unintended `withoutGlobalScope(...)` usage was introduced
5. Adversarial isolation tests exist or were updated
6. `composer analyse`, `composer format:test`, and test suite pass

## Security Notes for Developers

- Prefer explicit, boring code over magic when tenant boundaries are involved
- Treat any global query as a security-sensitive operation
- If you need a bypass, document and audit it instead of hiding it

## Related Documents

- [`MULTI_TENANCY.md`](MULTI_TENANCY.md)
- [`ARCHITECTURE.md`](ARCHITECTURE.md)
- [`TESTING.md`](TESTING.md)
