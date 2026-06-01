# Dodo Duo

[![CI](https://github.com/mickaellherminez/dodo-duo/actions/workflows/ci.yml/badge.svg)](https://github.com/mickaellherminez/dodo-duo/actions/workflows/ci.yml)
[![PHPStan Level 8](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg)](https://phpstan.org/)
[![Laravel Pint](https://img.shields.io/badge/code%20style-pint-orange.svg)](https://laravel.com/docs/pint)

Multi-tenant Laravel SaaS built on top of SaaSForge — workspace isolation, secure defaults, and fast API-first delivery.

A production-minded Laravel 11 application with authentication, RBAC, audit logging, tests, and deployment tooling already in place.

## 🎯 What is Dodo Duo?

Dodo Duo is a backend-first SaaS application built on SaaSForge, providing the infrastructure most products need before feature work begins.

- **Multi-tenancy**: Workspace-scoped data model, middleware-based tenant resolution, and automatic query scoping
- **Authentication**: Register/login/logout, password reset, email verification, OAuth (Google/GitHub)
- **RBAC**: Workspace roles, role checks, permissions middleware, policy-based authorization
- **Security**: Immutable `workspace_id`, adversarial isolation tests, explicit escape hatches, audit logging
- **Testing**: Pest test suite, parallel/coverage scripts, security test helpers, CI support
- **Deployment**: Shared hosting optimization, production env template, `deploy:prod` command, deployment docs
- **API docs & DX**: Postman collection, API testing guides, quick start API docs, and OpenAPI/Swagger interactive documentation

## 🚀 Quick Start (< 5 minutes)

### Prerequisites

- PHP 8.2+
- Composer 2.5+
- SQLite (fastest local setup) or MySQL 8+
- Node.js 18+ (optional for frontend assets)

### 8-Step Installation

1. **Clone the repository**

```bash
git clone <your-repo-url> saas-forge
cd saas-forge
```

2. **Install PHP dependencies**

```bash
composer install
```

3. **Create your environment file**

```bash
cp .env.example .env
```

4. **Prepare a local database (SQLite quick path)**

```bash
touch database/database.sqlite
```

5. **Configure `.env` for SQLite (or keep MySQL if preferred)**

```dotenv
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/saas-forge/database/database.sqlite
```

6. **Generate the application key**

```bash
php artisan key:generate
```

7. **Run migrations**

```bash
php artisan migrate
```

8. **Start the app and verify health**

```bash
php artisan serve
# In another terminal:
curl http://127.0.0.1:8000/api/health
```

### First Steps

- Run the full test suite: `./vendor/bin/pest --compact`
- Check code quality: `composer quality`
- Explore API routes under `/api/v1/*` (auth, workspaces, projects, invitations, members, audit logs)
- Generate and browse OpenAPI docs: `php artisan l5-swagger:generate` then open `/api/documentation`
- Import the Postman collection from `docs/postman/`

## 📚 Documentation

### Core Guides

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) - Multi-tenancy architecture deep dive
- [`docs/MULTI_TENANCY.md`](docs/MULTI_TENANCY.md) - Workspace isolation guide for developers
- [`docs/TESTING.md`](docs/TESTING.md) - Testing strategy, commands, suites, and patterns
- [`docs/SECURITY.md`](docs/SECURITY.md) - Security model, adversarial testing, and escape hatches
- [`docs/CORE.md`](docs/CORE.md) - Core project concepts and conventions
- [`docs/VISION.md`](docs/VISION.md) - Product vision and positioning
- [`docs/ROADMAP.md`](docs/ROADMAP.md) - Roadmap and backlog planning

### API & Developer Experience

- [`docs/API.md`](docs/API.md) - Practical API usage guide with curl examples, client snippets (TS/PHP), error handling, and rate limiting notes
- [`docs/OPENAPI_SWAGGER.md`](docs/OPENAPI_SWAGGER.md) - OpenAPI/Swagger generation, UI usage, and annotation maintenance guide
- [`docs/QUICK_START_API.md`](docs/QUICK_START_API.md) - API quick start walkthrough
- [`docs/API_TESTING_GUIDE.md`](docs/API_TESTING_GUIDE.md) - API testing workflow
- [`docs/API_TESTING_TROUBLESHOOTING.md`](docs/API_TESTING_TROUBLESHOOTING.md) - API testing troubleshooting
- [`docs/postman/SaaSForge-API-v1.postman_collection.json`](docs/postman/SaaSForge-API-v1.postman_collection.json) - Postman collection
- [`docs/postman/SaaSForge-Local.postman_environment.json`](docs/postman/SaaSForge-Local.postman_environment.json) - Local Postman environment
- [`docs/postman/run-postman.sh`](docs/postman/run-postman.sh) - Newman/Postman runner script

### Deployment Docs

- [`docs/deployment/shared-hosting.md`](docs/deployment/shared-hosting.md) - Shared hosting deployment strategy
- [`docs/deployment/o2switch.md`](docs/deployment/o2switch.md) - o2switch-specific deployment notes
- [`docs/deployment/checklist.md`](docs/deployment/checklist.md) - Production deployment checklist
- [`docs/deployment/rollback.md`](docs/deployment/rollback.md) - Rollback procedure

## 🏗️ Architecture

### High-Level Data Flow

```text
User (auth:sanctum)
   |
   v
SetCurrentWorkspace middleware
   |
   +--> CurrentWorkspace service (request-scoped tenant context)
   |
   v
Workspace-scoped domain resources
(Projects, Members, Invitations, Audit Logs, ...)
```

```text
User -> Workspace -> Resources
```

### Key Concepts

- **Workspace context is request-scoped** and resolved by subdomain, custom domain, `X-Workspace-ID`, route parameter, or token ability
- **Tenant isolation is enforced in layers**: middleware + policies + model trait + global scope + tests
- **`BelongsToWorkspace` trait** auto-fills `workspace_id`, applies `WorkspaceScope`, and prevents `workspace_id` mutation
- **Explicit global access is opt-in** through approved escape hatches (for admin-only endpoints)
- **Audit logs** capture sensitive cross-workspace actions for traceability

## 🔐 Security Features

- **Immutable `workspace_id`** on tenant-scoped models to prevent cross-tenant reassignment
- **Automatic query scoping** via `WorkspaceScope` on models using `BelongsToWorkspace`
- **Adversarial isolation tests** (`tests/Feature/Security`) covering read/update/delete/create injection attempts
- **Audit logging** for membership changes and admin escape-hatch actions
- **Defense in depth** with middleware, policies, gates, and route validation (`php artisan workspace:validate-routes`)
- **Documented escape hatches** (e.g. `forAllWorkspaces()` / `withoutGlobalScope`) restricted to approved admin flows

## 🧪 Testing

### Common Commands

```bash
./vendor/bin/pest --compact
composer test
composer test:parallel
composer test:coverage
composer quality
```

### Test Structure

- `tests/Feature/Api/V1` - API endpoint behavior and authorization
- `tests/Feature/Workspace` - workspace lifecycle, membership, RBAC, context handling
- `tests/Feature/Security` - adversarial cross-tenant isolation tests
- `tests/Unit/*` - unit tests for rules, services, traits

### Coverage Target

- `composer test:coverage` enforces a minimum coverage threshold of **80%**
- CI should stay green on regressions before shipping new stories

## 🎨 Code Quality

- **PHPStan (Level 8)** for static analysis: `composer analyse`
- **Laravel Pint** for formatting: `composer format` / `composer format:test`
- **Combined gate**: `composer quality` (analysis + formatting check + tests)

## 📦 What's Included

- **Auth**: Registration, login/logout, password reset, email verification, OAuth social login
- **Workspaces**: CRUD, membership, switching, invitations, current workspace resolution
- **Resources**: Tenant-scoped projects CRUD + pagination/filter/search foundations
- **API**: Versioned API routes (`/api/v1`), Sanctum auth, health check endpoint, Postman assets
- **DevOps**: Shared-hosting deployment command, benchmark command, queue monitor, health endpoint

## 🌍 Deployment

Shared hosting is a first-class target for this template.

### Highlights

- `php artisan deploy:prod` for cache clear/rebuild + migrations + storage link
- `.env.production.example` with production-oriented defaults
- `deploy.sh` helper for permissions and deployment tasks
- Shared hosting documentation under [`docs/deployment/`](docs/deployment/shared-hosting.md)

### Production Command

```bash
php artisan deploy:prod
```

## 🎯 Roadmap

### v0.1 MVP (Delivered / In Progress)

- Core Laravel foundation + workspace ownership model
- Multi-tenant workspace system and switching
- Auth flows (password + OAuth) and email verification
- RBAC, workspace-aware policies, and membership management
- Tenant-scoped projects API with integrity constraints
- Security hardening (audit logs, adversarial tests, documented escape hatches)
- Testing/QA infrastructure (Pest, coverage, static analysis, CI/CD)
- Shared hosting deployment and performance benchmarking
- Developer documentation baseline (README + architecture/security/testing guides)

### v1.0 (Planned)

- CLI tooling & automation epic (deferred from v0.1)
- Expanded API documentation (OpenAPI/Swagger generation and examples)
- Additional DX automation around validation and scaffolding
- Broader production observability and operational tooling

## 🤝 Contributing

- Create a feature branch from your working branch
- Run `composer quality` before opening a PR
- Add or update tests for any code behavior changes
- Prefer tenant-safe patterns documented in [`docs/MULTI_TENANCY.md`](docs/MULTI_TENANCY.md)

## 📄 License

This project is distributed under the **MIT** license (see `composer.json` license metadata).

## 🙏 Credits

- [Laravel](https://laravel.com/)
- [Pest PHP](https://pestphp.com/)
- [Laravel Sanctum](https://laravel.com/docs/sanctum)
- [Laravel Socialite](https://laravel.com/docs/socialite)
- [Laravel Pint](https://laravel.com/docs/pint)
- [PHPStan](https://phpstan.org/)

## 💬 Support

- Review the core documentation starting with [`docs/CORE.md`](docs/CORE.md)
- Use the Postman collection in [`docs/postman/`](docs/postman/SaaSForge-API-v1.postman_collection.json)
- Open an issue or internal ticket with:
  - Laravel/PHP versions
  - failing command/request
  - expected vs actual behavior
  - logs or stack trace (sanitized)
