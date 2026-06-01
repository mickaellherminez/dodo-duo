# SaaSForge — CORE v0.1 Checklist

> Checklist exécution pour sortir le MVP Starter Kit en 2-3 semaines.

---

## 📋 Phase 0: Project Setup (Jour 1)

### Repository & Base

- [ ] Créer repo GitHub `mickael-lherminez/saas-forge`
- [ ] Initialiser Laravel 11.x (`composer create-project laravel/laravel saas-forge`)
- [ ] Configure `.env` local (MySQL database)
- [ ] Configure Pest PHP (`composer require pestphp/pest --dev`)
- [ ] Setup GitHub Actions (CI/CD basic)
  - [ ] Run tests on PR
  - [ ] Code style check (Pint)
  - [ ] Static analysis (PHPStan level 5)

### Documentation Structure

- [ ] Create `docs/` folder
- [ ] Create placeholder files:
  - [ ] `docs/README.md`
  - [ ] `docs/GOLDEN-PATH.md`
  - [ ] `docs/ARCHITECTURE.md`
  - [ ] `docs/API.md`
  - [ ] `docs/TESTING.md`
  - [ ] `docs/DEPLOYMENT.md`

### Git Setup

- [ ] `.gitignore` updated (IDE files, .env, node_modules)
- [ ] Initial commit
- [ ] Create branch `develop`
- [ ] Create branch `feature/core-foundation`

---

## 🏗️ Phase 1: Core Foundation (Semaine 1)

### Day 1-2: Database Architecture

#### Migrations

- [ ] **Migration `create_workspaces_table`** (#6)
  ```bash
  php artisan make:migration create_workspaces_table
  ```
  - [ ] Fields: `id`, `name`, `slug` (unique), `domain` (nullable, unique), `status` (enum), `owner_id`, `settings` (json), `trial_ends_at`, timestamps, soft_deletes
  - [ ] Indexes: `slug`, `domain`, `status`, `owner_id`
  - [ ] Foreign key: `owner_id` → `users.id`

- [ ] **Migration `create_workspace_members_table`** (#6)
  ```bash
  php artisan make:migration create_workspace_members_table
  ```
  - [ ] Fields: `id`, `workspace_id`, `user_id`, `role` (default 'member'), `permissions` (json), `invited_at`, `joined_at`, timestamps
  - [ ] Unique constraint: `(workspace_id, user_id)`
  - [ ] Indexes: `(workspace_id, role)`, `(workspace_id, user_id)`, `user_id`
  - [ ] Foreign keys: cascade on delete

- [ ] **Migration `create_projects_table`** (#19, #21, example resource)
  ```bash
  php artisan make:migration create_projects_table
  ```
  - [ ] Fields: `id`, `workspace_id` (FK), `name`, `slug`, `description`, `status` (enum), `settings` (json), `created_by` (FK users), timestamps, soft_deletes
  - [ ] Indexes tenant-first:
    - [ ] `(workspace_id, id)`
    - [ ] `(workspace_id, status)`
    - [ ] `(workspace_id, created_at)`
    - [ ] `(workspace_id, created_by)`
  - [ ] Unique per tenant: `(workspace_id, slug)`

- [ ] Run migrations: `php artisan migrate`

#### Models

- [ ] **Model `Workspace`**
  - [ ] Relations: `owner()`, `members()`, `projects()`
  - [ ] Scopes: `active()`, `suspended()`
  - [ ] Accessors: `isActive()`, `isSuspended()`

- [ ] **Model `WorkspaceMember`**
  - [ ] Relations: `workspace()`, `user()`
  - [ ] Casts: `permissions` → array
  - [ ] Methods: `hasPermission($permission)`, `isOwner()`, `isAdmin()`

- [ ] **Model `Project`** (will use trait later)
  - [ ] Relations: `workspace()`, `creator()`
  - [ ] Casts: `settings` → array
  - [ ] Methods: `isActive()`, `archive()`

#### Factories

- [ ] **Factory `WorkspaceFactory`**
  - [ ] Generate realistic data (Faker)
  - [ ] State: `active()`, `suspended()`

- [ ] **Factory `WorkspaceMemberFactory`**
  - [ ] Attach to workspace + user
  - [ ] State: `owner()`, `admin()`, `member()`

- [ ] **Factory `ProjectFactory`**
  - [ ] Belongs to workspace
  - [ ] Created by user
  - [ ] States: `active()`, `archived()`

#### Seeders

- [ ] **Seeder `DatabaseSeeder`**
  - [ ] Create 1 admin user
  - [ ] Create 3 workspaces
  - [ ] Create 5-10 members per workspace
  - [ ] Create 10-20 projects per workspace

- [ ] Test seeding: `php artisan db:seed`

### Day 3-4: Traits & Scopes

#### Core Traits

- [ ] **Trait `BelongsToWorkspace`** (#8)
  - [ ] Location: `app/Models/Concerns/BelongsToWorkspace.php`
  - [ ] Boot method:
    - [ ] Add global scope `WorkspaceScope`
    - [ ] Auto-set `workspace_id` on creating
    - [ ] Throw exception if no `workspace_id` set
    - [ ] Block `workspace_id` update (#20)
  - [ ] Relation: `workspace()`
  - [ ] Scope: `acrossWorkspaces()`

- [ ] **Global Scope `WorkspaceScope`**
  - [ ] Location: `app/Scopes/WorkspaceScope.php`
  - [ ] Apply filter: `WHERE workspace_id = current_workspace_id()`
  - [ ] Only if `current_workspace_id()` exists

#### Apply Trait to Project Model

- [ ] `Project` model uses `BelongsToWorkspace` trait
- [ ] Remove manual `workspace()` relation (trait provides it)
- [ ] Test: `Project::all()` filters by workspace automatically

### Day 5-7: Middleware & Context

#### Services

- [ ] **Service `CurrentWorkspace`** (#15)
  - [ ] Location: `app/Services/CurrentWorkspace.php`
  - [ ] Constructor: `public readonly Workspace $workspace`
  - [ ] Methods:
    - [ ] `id(): int`
    - [ ] `slug(): string`
    - [ ] `is(Workspace $workspace): bool`
    - [ ] `userRole(): ?string`
    - [ ] `userCan(string $permission): bool`

#### Helpers

- [ ] **Helper functions** (#15)
  - [ ] Location: `app/helpers.php`
  - [ ] Autoload via `composer.json` files
  - [ ] `current_workspace(): ?CurrentWorkspace`
  - [ ] `current_workspace_id(): ?int`

#### Middleware

- [ ] **Middleware `SetCurrentWorkspace`** (#31)
  - [ ] Location: `app/Http/Middleware/SetCurrentWorkspace.php`
  - [ ] Resolution strategies (in order):
    1. [ ] Subdomain (`acme.saasforge.app` → find by `slug`)
    2. [ ] Custom domain (`app.acme.com` → find by `domain`)
    3. [ ] Header `X-Workspace-ID`
    4. [ ] Route parameter `{workspace}`
    5. [ ] JWT token claim `workspace_id` (placeholder)
  - [ ] Verify user has access to workspace
  - [ ] Store in container: `app()->instance(CurrentWorkspace::class, ...)`
  - [ ] Set config: `config(['app.current_workspace_id' => ...])`
  - [ ] Throw 404 if workspace not found
  - [ ] Throw 403 if no access

- [ ] **Middleware `TenantContextAssertion`** (#28)
  - [ ] Location: `app/Http/Middleware/TenantContextAssertion.php`
  - [ ] Check `current_workspace()` is set
  - [ ] If not, throw exception (except whitelisted routes)
  - [ ] Whitelist: `/login`, `/register`, `/public/*`

#### Register Middleware

- [ ] Add to `bootstrap/app.php` or `Kernel.php`:
  - [ ] `SetCurrentWorkspace::class` in route group
  - [ ] `TenantContextAssertion::class` after auth

#### Configuration

- [ ] Create `config/workspace.php`
  - [ ] Resolution strategy priority
  - [ ] App domain (for subdomain extraction)
  - [ ] Context assertion whitelist

---

## 🧪 Phase 2: Testing & Quality (Semaine 2)

### Day 8-9: Feature Tests — Isolation

- [ ] **Test `WorkspaceIsolationTest`** (#13)
  - [ ] Location: `tests/Feature/WorkspaceIsolationTest.php`
  
  - [ ] **Test: `user_cannot_see_projects_from_other_workspace`**
    - [ ] Setup: 2 workspaces, 2 users, 1 project each
    - [ ] UserA tries to access ProjectB via API
    - [ ] Assert: 403 Forbidden

  - [ ] **Test: `global_scope_filters_projects_by_workspace`**
    - [ ] Setup: workspaceA with 5 projects, workspaceB with 3 projects
    - [ ] Set workspace context to A
    - [ ] Query `Project::all()`
    - [ ] Assert: only 5 projects returned, all from workspace A

  - [ ] **Test: `cannot_create_project_without_workspace_context`**
    - [ ] No workspace context set
    - [ ] Try `Project::create(...)`
    - [ ] Assert: RuntimeException thrown

  - [ ] **Test: `cannot_change_workspace_id_after_creation`** (#20)
    - [ ] Create project in workspaceA
    - [ ] Try `$project->update(['workspace_id' => $workspaceBid])`
    - [ ] Assert: RuntimeException thrown

  - [ ] **Test: `scoped_unique_validation_works`**
    - [ ] Create project with slug `my-project` in workspace A
    - [ ] Create project with slug `my-project` in workspace B (should succeed)
    - [ ] Try create another `my-project` in workspace A (should fail unique validation)

### Day 10-11: API Tests — Projects CRUD

- [ ] **Feature Test `ProjectApiTest`**
  - [ ] Location: `tests/Feature/Api/V1/ProjectApiTest.php`

  - [ ] **Test: `can_list_projects_in_workspace`**
    - [ ] Auth as user in workspace
    - [ ] GET `/api/v1/workspaces/{workspace}/projects`
    - [ ] Assert: 200, projects returned

  - [ ] **Test: `can_create_project_in_workspace`**
    - [ ] Auth as member
    - [ ] POST `/api/v1/workspaces/{workspace}/projects`
    - [ ] Assert: 201, project created with correct workspace_id

  - [ ] **Test: `can_update_own_workspace_project`**
    - [ ] Auth as admin
    - [ ] PATCH `/api/v1/workspaces/{workspace}/projects/{project}`
    - [ ] Assert: 200, project updated

  - [ ] **Test: `cannot_update_project_from_other_workspace`**
    - [ ] Auth as user in workspaceA
    - [ ] Try PATCH project from workspaceB
    - [ ] Assert: 403 or 404

  - [ ] **Test: `can_delete_project`**
    - [ ] Auth as admin
    - [ ] DELETE `/api/v1/workspaces/{workspace}/projects/{project}`
    - [ ] Assert: 204, project soft deleted

### Day 12: Observability

- [ ] **Structured Logging** (#4)
  - [ ] Configure `config/logging.php`:
    - [ ] Channel `daily` with JSON formatter (Monolog)
    - [ ] Include: timestamp, level, message, context
  - [ ] Middleware to add context:
    - [ ] `request_id` (generate UUID per request)
    - [ ] `workspace_id` (current workspace)
    - [ ] `user_id` (auth user)
  - [ ] Test: Log entry, verify JSON structure

- [ ] **Error Tracking (Optional Sentry)**
  - [ ] Document Sentry integration
  - [ ] `.env.example` with `SENTRY_LARAVEL_DSN`
  - [ ] Don't require Sentry, but make it easy to add

---

## 📚 Phase 3: Documentation & CLI (Semaine 2-3)

### Day 13-14: Example CRUD Complete

- [ ] **Controller `ProjectController`**
  - [ ] Location: `app/Http/Controllers/Api/V1/ProjectController.php`
  - [ ] Methods: `index`, `store`, `show`, `update`, `destroy`
  - [ ] Use `authorizeResource(Project::class)`

- [ ] **Form Requests**
  - [ ] `StoreProjectRequest`
    - [ ] Rules: name required, slug unique per workspace (#validation)
    - [ ] Authorization: true (policy handles it)
  - [ ] `UpdateProjectRequest`
    - [ ] Rules: similar to store
    - [ ] Slug unique ignore current project

- [ ] **Resource `ProjectResource`**
  - [ ] Location: `app/Http/Resources/ProjectResource.php`
  - [ ] Transform: id, workspace_id, name, slug, description, status, created_at, etc.
  - [ ] Include creator relation (whenLoaded)

- [ ] **Policy `ProjectPolicy`**
  - [ ] Before hook: check workspace membership
  - [ ] `viewAny`: true if member
  - [ ] `view`: workspace_id matches
  - [ ] `create`: member or admin
  - [ ] `update`/`delete`: admin or owner

- [ ] **Routes**
  - [ ] `routes/api.php`:
    ```php
    Route::middleware(['auth:sanctum', SetCurrentWorkspace::class])
        ->prefix('v1/workspaces/{workspace}')
        ->group(function () {
            Route::apiResource('projects', ProjectController::class);
        });
    ```

- [ ] **Test all endpoints work**

### Day 15-16: CLI Tool `saas-forge new`

- [ ] **Artisan Command `NewCommand`** (#35)
  - [ ] Location: `app/Console/Commands/NewCommand.php`
  - [ ] Signature: `saas-forge new {name} {--preset=}`
  - [ ] Steps:
    1. [ ] Create directory `{name}`
    2. [ ] Clone SaaSForge template
    3. [ ] Run `composer install`
    4. [ ] Copy `.env.example` → `.env`
    5. [ ] Generate `APP_KEY`
    6. [ ] Run migrations
    7. [ ] Output success message with next steps

- [ ] **Preset: MVP Fast** (#35)
  - [ ] Flag `--preset=mvp-fast`
  - [ ] Minimal config, no optional features
  - [ ] Single tenant mode (comment out multi-tenant middleware)
  - [ ] README customized for fast shipping

- [ ] **Test command locally**
  ```bash
  php artisan saas-forge new my-test-saas --preset=mvp-fast
  cd my-test-saas
  php artisan serve
  ```

### Day 17-19: Documentation

- [ ] **README.md**
  - [ ] Badges (build status, license, version)
  - [ ] Tagline: "From idea to production SaaS in 48 hours"
  - [ ] Features list (ownership, multi-tenant, security, etc.)
  - [ ] Quick Start (5 steps max)
  - [ ] Links to docs/
  - [ ] Contributing section
  - [ ] License (MIT)

- [ ] **docs/GOLDEN-PATH.md**
  - [ ] Introduction: Why conventions matter
  - [ ] Database conventions:
    - [ ] `workspace_id` on all tenant-scoped tables
    - [ ] Indexes tenant-first
    - [ ] Unique constraints scoped per tenant
  - [ ] Model conventions:
    - [ ] Use `BelongsToWorkspace` trait
    - [ ] Never manually query without scope
  - [ ] Middleware stack explained
  - [ ] Policy patterns
  - [ ] Testing patterns

- [ ] **docs/ARCHITECTURE.md**
  - [ ] Ownership philosophy
  - [ ] Multi-tenancy strategy (shared DB + scopes)
  - [ ] Defense in depth layers
  - [ ] Evolution path (single → multi-tenant → hybrid)
  - [ ] Diagrams (Mermaid):
    - [ ] Data model (workspaces → members → projects)
    - [ ] Request flow (middleware → scope → policy)

- [ ] **docs/API.md**
  - [ ] Authentication (Sanctum tokens)
  - [ ] Workspace resolution (subdomain, header, etc.)
  - [ ] Example endpoints:
    - [ ] `GET /api/v1/workspaces/{workspace}/projects`
    - [ ] `POST /api/v1/workspaces/{workspace}/projects`
  - [ ] Response formats
  - [ ] Error codes
  - [ ] Rate limiting (future)

- [ ] **docs/TESTING.md**
  - [ ] Philosophy: Test isolation first
  - [ ] How to write feature tests
  - [ ] How to test multi-tenant scenarios
  - [ ] Test data setup (factories, seeders)
  - [ ] Running tests: `php artisan test`
  - [ ] Coverage: `php artisan test --coverage`

- [ ] **docs/DEPLOYMENT.md**
  - [ ] Requirements (PHP 8.2+, MySQL 8+)
  - [ ] Shared hosting (o2switch):
    - [ ] Upload via FTP/Git
    - [ ] Configure `.env`
    - [ ] Run migrations
  - [ ] VPS (Forge):
    - [ ] Connect repo
    - [ ] Deploy script
    - [ ] Queue worker setup
  - [ ] Environment variables explained
  - [ ] Performance tips (opcache, query caching)

### Day 20-21: Polish & Final Checks

- [ ] **Code Review**
  - [ ] PSR-12 formatting (Pint)
  - [ ] PHPStan level 5 passes
  - [ ] No debug code (`dd()`, `dump()`)
  - [ ] No hardcoded values (use config)

- [ ] **Tests Green**
  - [ ] All tests pass: `php artisan test`
  - [ ] Coverage >70%: `php artisan test --coverage --min=70`

- [ ] **Performance Check**
  - [ ] Seed 100 workspaces × 1000 projects
  - [ ] Query: `Project::paginate(20)`
  - [ ] Verify: <100ms with proper indexes

- [ ] **Security Audit (Basic)**
  - [ ] Check: No SQL injection vectors
  - [ ] Check: Policies applied on all routes
  - [ ] Check: Workspace isolation tests pass
  - [ ] Check: No sensitive data in logs

- [ ] **README.md Final Review**
  - [ ] Proofread
  - [ ] Links work
  - [ ] Screenshots/GIFs (optional)

---

## 🚀 Phase 4: Release v0.1.0

### Pre-Release

- [ ] Merge `feature/core-foundation` → `develop`
- [ ] Merge `develop` → `main`
- [ ] Tag `v0.1.0`
- [ ] Push tags: `git push origin v0.1.0`

### GitHub Release

- [ ] Create release on GitHub
- [ ] Title: "SaaSForge v0.1.0 — MVP Starter Kit"
- [ ] Release notes:
  - [ ] What's included (features list)
  - [ ] What's next (roadmap preview)
  - [ ] Breaking changes (N/A for v0.1)
  - [ ] Installation instructions

### Communication

- [ ] Tweet announcement (if applicable)
- [ ] Reddit post r/laravel, r/saas
- [ ] Laravel News submission
- [ ] Personal blog post (optional)

### Community Setup

- [ ] GitHub Discussions enabled
- [ ] Discord server created (optional)
- [ ] Email for support (saasforge@...)

---

## 📊 Success Metrics — v0.1 Checklist

Before declaring v0.1 complete, verify:

- [x] **Code Quality**
  - [ ] All tests pass
  - [ ] Coverage >70%
  - [ ] PHPStan level 5 clean
  - [ ] Pint formatting applied

- [x] **Features Complete**
  - [ ] 14 core features implemented
  - [ ] Example CRUD (Projects) works
  - [ ] CLI `saas-forge new` works
  - [ ] Preset MVP Fast scaffolds correctly

- [x] **Documentation**
  - [ ] README.md complete
  - [ ] 5 docs/ guides written
  - [ ] API documented
  - [ ] Testing guide available

- [x] **Performance**
  - [ ] Queries <100ms (100k rows)
  - [ ] Passes load test (100 workspaces)

- [x] **Security**
  - [ ] Isolation tests pass
  - [ ] Policies applied everywhere
  - [ ] No known vulnerabilities

- [x] **Usability**
  - [ ] Can scaffold new project in <5 minutes
  - [ ] Developer can understand codebase in <30 minutes
  - [ ] Can deploy to production in <2 hours

**If all checked ✅ → Ship v0.1.0 🚀**

---

## 🎯 Post-Release Actions

After v0.1.0 is out:

1. [ ] Monitor GitHub issues (respond <24h)
2. [ ] Gather feedback from early adopters
3. [ ] Create issues for bugs/improvements
4. [ ] Plan v0.5 (bug fixes + polish)
5. [ ] Start v1.0 planning (B2B features)

---

**Version:** 1.0  
**Last Updated:** 2026-02-12  
**Maintainer:** Mickael Lherminez
