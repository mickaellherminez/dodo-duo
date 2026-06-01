# Testing Guide

## Overview

SaaSForge uses Pest as the primary testing interface on top of PHPUnit. The project includes feature, unit, and adversarial security tests to protect multi-tenant behavior.

## Tooling

- **Pest** for expressive tests
- **PHPUnit** runtime under the hood
- **Laravel testing helpers** for HTTP/database assertions
- **Sanctum** helpers for authenticated API tests
- **PHPStan/Pint** for non-runtime quality gates (run alongside tests in CI)

## Test Commands

### Fast Local Feedback

```bash
./vendor/bin/pest --compact
```

### Composer Scripts

```bash
composer test          # Full Pest suite
composer test:parallel # Parallelized suite
composer test:coverage # Coverage report with minimum 80%
composer quality       # PHPStan + Pint check + tests
```

### Additional Quality Checks

```bash
composer analyse
composer format:test
```

## Current Suite Structure

```text
tests/
├── Feature/
│   ├── Api/V1/
│   ├── Auth/
│   ├── Authorization/
│   ├── Resources/
│   ├── Security/
│   └── Workspace/
└── Unit/
    ├── Rules/
    ├── Services/
    └── Traits/
```

## Pest Project Setup

`tests/Pest.php` configures:

- `Tests\TestCase` for `Feature` and `Unit`
- `Tests\Feature\Security\AdversarialTestCase` extension for `Feature/Security`
- custom expectations (UUID, workspace ID, workspace role)
- shared datasets (workspace roles, project statuses)
- helper function `workspace_header(...)`

## Multi-Tenant Testing Patterns

### Workspace-Aware API Requests

Most tenant API tests should authenticate a user and provide a workspace context.

Common pattern:

```php
Sanctum::actingAs($user);
$this->withHeaders(['X-Workspace-ID' => $workspace->id])->getJson('/api/v1/projects');
```

### Adversarial Isolation Tests (Critical)

The security test helper trait (`tests/Feature/Security/AdversarialTestCase.php`) provides reusable patterns for validating:

- cross-tenant read denial
- cross-tenant update denial
- cross-tenant delete denial
- forged `workspace_id` create injection attempts

This is a core regression guard for multi-tenant safety.

## What to Test for New Features

When adding a story that changes behavior, include tests for:

- happy path behavior
- authorization rules (roles/permissions)
- tenant isolation (if tenant-scoped)
- validation errors and edge cases
- audit logging side effects (when security-sensitive)

## Documentation-Only Stories

For documentation-only stories (like README/docs updates):

- no new PHP tests are usually required
- you still run regression checks to confirm no project-level breakage
- you still run code quality checks if the story definition requires them

## Coverage Expectations

- `composer test:coverage` enforces a minimum **80%** coverage threshold
- Treat coverage as a guardrail, not a substitute for targeted assertions
- Prioritize high-value tests around authorization, tenancy, and security boundaries

## CI Expectations

The CI workflow should remain green before a story is considered review-ready.

Recommended pre-review local sequence:

1. `./vendor/bin/pest --compact`
2. `composer format:test`
3. `composer analyse`

## Troubleshooting

### Tests fail due to missing DB setup

- Ensure `.env.testing` / test DB configuration is valid
- For local SQLite workflows, verify database file exists if your setup expects one

### Tenant tests fail unexpectedly (403/404 mismatch)

- Check `SetCurrentWorkspace` middleware resolution path
- Confirm the authenticated user belongs to the workspace under test
- Confirm route is using tenant middleware and not an admin escape-hatch path

### Security tests passing without real assertions

- Verify tests assert exact status codes (`403` vs `404`) where intended
- Use adversarial helpers instead of duplicating brittle setup logic

## Related Documents

- [`SECURITY.md`](SECURITY.md)
- [`MULTI_TENANCY.md`](MULTI_TENANCY.md)
- [`API_TESTING_GUIDE.md`](API_TESTING_GUIDE.md)
- [`API_TESTING_TROUBLESHOOTING.md`](API_TESTING_TROUBLESHOOTING.md)
