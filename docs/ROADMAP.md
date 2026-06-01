# SaaSForge — Roadmap & Milestones

> Plan d'exécution détaillé : MVP v0 → v1 B2B Multi-Tenant → v2 Ecosystem

---

## 🎯 Overview

| Version | Timeline | Focus | Status |
|---------|----------|-------|--------|
| **v0.1 — MVP Starter** | 2-3 semaines | Core Foundation + Golden Path | 🔜 Next |
| **v0.5 — Alpha Release** | +2 semaines | Bug fixes + Documentation | 📝 Planned |
| **v1.0 — B2B Multi-Tenant** | +4-6 semaines | Production-Ready + Security Hardening | 📝 Planned |
| **v1.5 — DX Enhancement** | +3 semaines | Generators + CLI + Presets | 📝 Planned |
| **v2.0 — Ecosystem** | +8-12 semaines | Advanced Features + Bundles | 💭 Future |

---

## 🔴 v0.1 — MVP Starter Kit (2-3 semaines)

**Goal:** Permettre de cloner SaaSForge et lancer un SaaS simple en 48-72h.

### Core Features (14 items)

#### 1. Architecture Ownership & Tenancy

- [x] **#6** Migration: `workspaces` table (tenant root)
- [x] **#6** Migration: `workspace_members` table (memberships + roles)
- [x] **#19** Migration: `projects` table (example tenant-owned resource)
- [ ] **#8** Trait `BelongsToWorkspace` (auto-scope + auto-fill workspace_id)
- [ ] **#8** Global Scope `WorkspaceScope` (filter by workspace_id)
- [ ] **#20** Guard: Immutable `workspace_id` (prevent accidental changes)
- [ ] **#21** Convention: Tenant-first indexes `(workspace_id, ...)`

#### 2. Middleware & Context Management

- [ ] **#31** Middleware `SetCurrentWorkspace` (multi-strategy resolution)
  - [ ] Subdomain resolution (`acme.saasforge.app`)
  - [ ] Custom domain resolution (`app.acme.com`)
  - [ ] Header resolution (`X-Workspace-ID`)
  - [ ] Route parameter resolution (`/workspaces/{workspace}`)
  - [ ] Token claim resolution (JWT)
- [ ] **#15** Service `CurrentWorkspace` (container singleton)
- [ ] **#15** Helper `current_workspace()` / `current_workspace_id()`
- [ ] **#28** Middleware `TenantContextAssertion` (fail-fast if no context)

#### 3. Security & Isolation

- [ ] **#11** Route-level ownership enforcement (middleware on routes)
- [ ] Policy template workspace-aware (before hook checks membership)
- [ ] **#13** Feature test: Workspace isolation (cannot see other workspace data)
- [ ] **#13** Feature test: Cannot modify other workspace resources
- [ ] **#13** Feature test: Global scope filtering works correctly
- [ ] **#20** Feature test: Immutable workspace_id (update throws exception)

#### 4. Observability

- [ ] **#4** Structured logging JSON format (Monolog)
- [ ] **#4** Request ID correlation (every log has request_id)
- [ ] **#4** Tenant ID in logs (every log has workspace_id)
- [ ] Basic error tracking (Sentry integration optional but documented)

#### 5. Example CRUD & Documentation

- [ ] **Projects API v1** full CRUD (index, store, show, update, destroy)
  - [ ] Controller workspace-scoped
  - [ ] Form requests with validation (unique slug per workspace)
  - [ ] Resource transformation
  - [ ] Policy authorization
  - [ ] Feature tests complete
- [ ] **#35** CLI Command `saas-forge new {name}` (scaffold base project)
- [ ] **#35** Preset `--preset=mvp-fast` (minimal opinions, ship fast)

#### 6. Documentation

- [ ] README.md (Quick Start, Installation, First Steps)
- [ ] docs/GOLDEN-PATH.md (Conventions détaillées)
- [ ] docs/ARCHITECTURE.md (Ownership patterns explained)
- [ ] docs/API.md (Example API endpoints + OpenAPI spec)
- [ ] docs/TESTING.md (How to test multi-tenant apps)
- [ ] docs/DEPLOYMENT.md (Deploy to o2switch, Forge, etc.)

### Deliverables v0.1

- ✅ Repo GitHub public `saas-forge`
- ✅ Laravel 11.x base configured
- ✅ Conventions DB (migrations + seeders + factories)
- ✅ Middleware stack (workspace resolution + context)
- ✅ Traits & Scopes (BelongsToWorkspace ecosystem)
- ✅ Policies template (workspace membership checks)
- ✅ Tests isolation (comprehensive test suite)
- ✅ CLI tool `saas-forge new`
- ✅ Documentation complete (README + 5 docs/)
- ✅ Example: Projects CRUD API v1

### Success Metrics v0.1

- **Time to deploy:** Un dev peut cloner + customiser + deploy en **48-72h**
- **Test coverage:** >70% sur features core
- **Performance:** Queries <100ms avec 10k projects × 100 workspaces
- **Documentation:** Chaque feature documented avec examples

### Timeline v0.1

| Semaine | Focus | Deliverables |
|---------|-------|--------------|
| **W1** | Architecture + Migrations + Middleware | Fondations techniques |
| **W2** | Tests + Example CRUD | Quality + template réutilisable |
| **W3** | CLI + Documentation + Polish | DX + release readiness |

**Target:** End of week 3 → Tag v0.1.0

---

## 🟠 v0.5 — Alpha Release (2 semaines post-v0.1)

**Goal:** Stabilisation, bug fixes, documentation refinement.

### Focus Areas

#### 1. Community Feedback Integration

- [ ] Alpha testers: 5-10 early adopters build real projects
- [ ] Collect feedback (GitHub Issues, Discord)
- [ ] Fix critical bugs (P0: blockers, P1: major issues)
- [ ] Improve DX pain points

#### 2. Documentation Enhancement

- [ ] Video: Quick Start tutorial (15 min)
- [ ] Guide: "Build a Todo SaaS in 1 hour"
- [ ] Guide: "Deploy to Production Step-by-Step"
- [ ] Troubleshooting section (common errors + solutions)
- [ ] FAQ (frequently asked questions)

#### 3. Testing & Quality

- [ ] Increase test coverage to >80%
- [ ] Add integration tests (full request lifecycle)
- [ ] Performance benchmarks documented
- [ ] Security audit (basic checklist)

#### 4. Developer Experience

- [ ] Improved error messages (helpful hints)
- [ ] Better CLI output (colors, progress bars)
- [ ] Setup wizard (`saas-forge setup`)
- [ ] Health check command (`saas-forge doctor`)

### Deliverables v0.5

- ✅ Bug fixes (all P0/P1 resolved)
- ✅ Enhanced documentation (videos + guides)
- ✅ Test coverage >80%
- ✅ Community channels (Discord, GitHub Discussions)

### Success Metrics v0.5

- **Adoption:** 10+ projects deployed in production
- **Quality:** Zero critical bugs reported
- **Community:** 50+ GitHub stars, 10+ contributors
- **Documentation:** <5min to understand, <1h to deploy

**Target:** End of week 5 → Tag v0.5.0

---

## 🟢 v1.0 — B2B Multi-Tenant Complete (4-6 semaines post-v0.5)

**Goal:** Production-ready starter kit for serious B2B SaaS.

### Added Features (16 items)

#### 1. Advanced DX & Generators

- [ ] **#30** Command `saas-forge make:migration --tenant-scoped`
- [ ] **#34** Command `saas-forge make:crud {model} --workspace-aware`
  - [ ] Generates: migration, model, controller, requests, tests, factory
  - [ ] Respects: tenant-scoping, indexes, policies
- [ ] **#40** Command `saas-forge add {module}` (post-scaffold module addition)
  - [ ] `saas-forge add billing --provider=stripe`
  - [ ] `saas-forge add storage --provider=s3`
  - [ ] Updates: migrations, config, routes, README

#### 2. Security Hardening

- [ ] **#7** Audited scope bypass (log every `withoutGlobalScope()`)
- [ ] **#12** Ownership firewall (middleware validates response data)
- [ ] **#14** Command `saas-forge lint:isolation` (static analysis)
- [ ] **#23** Tenant boundary validator (query listener checks)
- [ ] **#24** Cross-tenant FK blocker (validation/trigger)
- [ ] **#32** Scope bypass audit trail (table + dashboard)
- [ ] **#33** Command `saas-forge generate:tests` (auto isolation tests)

#### 3. Workspace Management Features

- [ ] **#9** Ownership transfer protocol (wizard when user leaves)
- [ ] Workspace invitations (email + token)
- [ ] Workspace roles (owner, admin, member, viewer)
- [ ] Workspace settings (customizable per workspace)
- [ ] Member management API (invite, remove, change role)

#### 4. Reliability & Safety

- [ ] **#2** Graceful degradation toolkit
  - [ ] Circuit breakers (external services)
  - [ ] Retry logic with exponential backoff
  - [ ] Fallback strategies documented
  - [ ] Failure playbook generated
- [ ] **#5** Migration safety net
  - [ ] `migrate:safe` command (dry-run + rollback test)
  - [ ] Pre/post validation
  - [ ] Automatic backups before migration

#### 5. Developer Tools

- [ ] **#16** Route `/dev/ownership` (visualize ownership graph)
- [ ] Command `saas-forge stats` (project metrics)
- [ ] Command `saas-forge audit` (security checklist)
- [ ] Performance profiler (identify slow queries)

#### 6. Preset: B2B Multi-Tenant

- [ ] **#36** Preset `--preset=b2b-multi-tenant`
  - [ ] Workspace management complete
  - [ ] Team invitations + roles
  - [ ] Workspace-level billing (prepared)
  - [ ] Usage metrics per workspace
  - [ ] Example workspace dashboard

#### 7. Module System Foundation

- [ ] **#39** Module dependency resolver (guide compatible choices)
- [ ] Module registry (what's available)
- [ ] Module manifest (what's installed)
- [ ] Module hooks (before/after scaffold)

### Deliverables v1.0

- ✅ All v0.5 features stable
- ✅ 16 new features (security, DX, workspace management)
- ✅ Preset B2B Multi-Tenant complete
- ✅ Module system architecture
- ✅ Migration tooling (safety nets)
- ✅ Developer tools (dashboard, linter, audit)
- ✅ Documentation updated (all new features)

### Success Metrics v1.0

- **Production-ready:** Can support 100+ tenants per instance
- **Security:** Zero data leaks in real projects
- **Performance:** Queries <50ms avec 100k rows
- **Community:** 500+ stars, 50+ contributors
- **Adoption:** 100+ projects deployed

**Target:** Week 11 → Tag v1.0.0

---

## 🟡 v1.5 — DX Enhancement (3 semaines post-v1.0)

**Goal:** Make SaaSForge the most productive Laravel SaaS starter.

### Focus Areas

#### 1. Enhanced CLI

- [ ] Interactive mode (`saas-forge new --interactive`)
- [ ] Recipe system (`saas-forge recipe social-oauth`)
- [ ] Plugin marketplace preview
- [ ] Better diagnostics (`saas-forge doctor --verbose`)

#### 2. More Presets

- [ ] Preset `--preset=api-only` (headless backend)
- [ ] Preset `--preset=single-tenant` (no multi-tenancy)
- [ ] Preset `--preset=monolith` (with Inertia.js)

#### 3. Code Quality Tools

- [ ] Pre-commit hooks (Pint, PHPStan)
- [ ] Code generator templates (customizable)
- [ ] Scaffold validation (ensure conventions respected)

#### 4. Documentation Ecosystem

- [ ] Interactive playground (try SaaSForge online)
- [ ] Video course (0 to production in 10 episodes)
- [ ] Case studies (5+ real SaaS projects)

### Deliverables v1.5

- ✅ Enhanced CLI experience
- ✅ 3 additional presets
- ✅ Code quality automation
- ✅ Expanded documentation

**Target:** Week 14 → Tag v1.5.0

---

## 🔵 v2.0 — Advanced Features & Ecosystem (8-12 semaines post-v1.5)

**Goal:** Complete ecosystem with bundles, hybrid tenancy, plugin marketplace.

### Major Features

#### 1. Hybrid Multi-Tenancy

- [ ] **#17** Multi-tenancy evolution path
- [ ] **#18** Tenant density monitor (dashboard)
- [ ] **#25** Command `saas-forge extract:tenant {id}` (move to isolated schema/DB)
- [ ] **#26** Hybrid tenancy router (shared + isolated simultaneous)
- [ ] **#29** Query performance budget per tenant

#### 2. Advanced Bundles

- [ ] **#37** Preset `--preset=creator-saas`
  - [ ] Media upload flows
  - [ ] Image optimization (Intervention)
  - [ ] Video processing (queue jobs)
  - [ ] CDN integration
  - [ ] Usage-based billing ready
- [ ] **#38** Preset `--preset=enterprise-lite`
  - [ ] Audit logs (all sensitive actions)
  - [ ] RGPD tools (export/delete)
  - [ ] Encryption at rest
  - [ ] SOC2-ready logging
  - [ ] 2FA enforcement
  - [ ] IP whitelisting

#### 3. Billing-First Features

- [ ] **#1, #3** Billing-first architecture
  - [ ] Pricing model selection at scaffold
  - [ ] Stripe integration complete (Cashier)
  - [ ] Usage tracking (metered billing)
  - [ ] Quota enforcement
  - [ ] Billing webhooks handler

#### 4. Advanced Architecture

- [ ] **#10** Polymorphic ownership (resource belongs to User/Team/Org)
- [ ] **#22** Tenant partitioning hints (MySQL 8+)
- [ ] **#27** Safe query builder wrapper (TenantQueryBuilder)
- [ ] Event-driven architecture (events + listeners)

#### 5. Plugin Ecosystem

- [ ] Plugin marketplace (community modules)
- [ ] Plugin API (how to build SaaSForge plugins)
- [ ] Official plugins:
  - [ ] Analytics (Plausible/Fathom integration)
  - [ ] CRM (basic customer management)
  - [ ] Helpdesk (support tickets)
  - [ ] Changelog (product updates)
  - [ ] API playground (Swagger UI)

### Deliverables v2.0

- ✅ Hybrid tenancy support
- ✅ 2 advanced bundles (Creator, Enterprise)
- ✅ Billing-first features
- ✅ Plugin ecosystem launched
- ✅ 5+ official plugins

### Success Metrics v2.0

- **Coverage:** 80% of SaaS use cases supported
- **Community:** 2000+ stars, 150+ contributors
- **Adoption:** 500+ projects in production
- **Ecosystem:** 20+ community plugins

**Target:** Week 26 → Tag v2.0.0

---

## 🔮 Beyond v2.0 — Future Vision

### AI-Powered Scaffolding

- Natural language → SaaS scaffold
- "Build me a project management SaaS with workspaces and billing"
- AI generates: schema, API, tests, documentation

### SaaSForge Cloud (Optional Managed Hosting)

- For teams who want managed infrastructure
- One-click deploy from SaaSForge project
- Monitoring, backups, scaling included
- **Note:** Always open-source first, cloud optional

### Enterprise Support & Consulting

- Priority support for companies
- Custom module development
- Architecture review & consulting
- Training & workshops

---

## 📊 Metrics Dashboard (Public)

Track progress publicly on [saasforge.dev/roadmap](https://saasforge.dev/roadmap):

- Features implemented (%)
- Community growth (stars, contributors)
- Projects deployed (estimated)
- Test coverage (%)
- Documentation completeness (%)

---

## 🎯 Prioritization Framework

**How we decide what's next:**

1. **Impact** — Does it unlock significant value for users?
2. **Effort** — Can we ship it in reasonable time?
3. **Foundation** — Is it required for future features?
4. **Demand** — Are users asking for it?
5. **Differentiation** — Does it make SaaSForge unique?

**Priority Levels:**
- 🔴 **P0** — Blocker, must have
- 🟠 **P1** — Very important, should have
- 🟢 **P2** — Nice to have, could have
- 🔵 **P3** — Future, won't have now

---

## 🤝 How to Contribute

See [CONTRIBUTING.md](./CONTRIBUTING.md) for details on:

- How to submit PRs
- Code standards
- Testing requirements
- Documentation expectations

---

**Version:** 1.0  
**Last Updated:** 2026-02-12  
**Maintainer:** Mickael Lherminez
