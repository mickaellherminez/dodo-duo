# SaaSForge — Vision & Principes Fondateurs

> **Mission:** Transformer l'idée SaaS en production de 6 mois en 48-72h grâce à un starter kit Laravel industriel, opinionné, et réutilisable.

---

## 🎯 Positionnement

**SaaSForge est un starter-kit backend API Laravel** conçu pour permettre la création rapide, fiable et industrialisée de projets SaaS.

### Qui & Pourquoi

| Cible | Besoin | Solution SaaSForge |
|-------|--------|-------------------|
| **Développeurs indie** | Lancer un side-project SaaS rapidement | Preset MVP Fast → ship en 48h |
| **Freelances** | Livrer des projets SaaS clients solides | Conventions éprouvées, qualité garantie |
| **Petites équipes** | Construire plusieurs SaaS sans repartir de zéro | Architecture réutilisable, modules pluggables |
| **Startups early-stage** | Valider product-market fit vite | Focus sur le métier, pas sur la plomberie |

### Différenciation

**Ce que SaaSForge N'EST PAS:**
- ❌ Un boilerplate générique Laravel
- ❌ Un framework propriétaire avec lock-in
- ❌ Une solution no-code/low-code
- ❌ Un SaaS-as-a-Service hosted

**Ce que SaaSForge EST:**
- ✅ Un **template Laravel opinionné** avec conventions fortes
- ✅ Une **architecture d'ownership** pensée pour multi-tenancy dès le jour 1
- ✅ Un **golden path** exécutable : migrations + middleware + scopes + tests
- ✅ Des **presets modulaires** (MVP Fast, B2B, Creator, Enterprise)
- ✅ Un **système évolutif** : commence simple, scale vers complexe

---

## 🏛️ Vérités Fondamentales

**Les 10 Lois Invariantes d'un SaaS en Production:**

Tout module de SaaSForge existe pour servir une de ces vérités :

1. **Identité & Contrôle d'accès** — Un SaaS doit identifier les acteurs et déterminer leurs droits
2. **Isolation et Ownership** — Les données doivent être cloisonnées avec ownership explicite
3. **Persistance fiable** — L'état doit être durable, cohérent, migrable
4. **Résilience** — Le système doit accepter l'échec et continuer de fonctionner
5. **Traçabilité** — Il faut pouvoir répondre "que s'est-il passé ?"
6. **Évolution continue** — Déployer sans casser l'expérience utilisateur
7. **Boucle de valeur** — Acquisition → Activation → Rétention
8. **Monétisation** — Permission économique d'exister (même gratuit au début)
9. **Protection contre abus** — Limites pour éviter la consommation incontrôlée
10. **Interface contractuelle** — API/flux/comportements stables

### Formule Synthétique

> **Un SaaS en production = Identité + Données + Contrôle + Résilience + Observabilité + Évolution + Économie**

Chaque feature de SaaSForge doit mapper à au moins une de ces 7 dimensions.

---

## 🧬 Architecture Philosophy

### 1. **Ownership-First Design**

L'ownership n'est pas un module optionnel — c'est **la fondation architecturale**.

**Principes:**
- Chaque ressource appartient à un `workspace_id`
- Les scopes globaux Eloquent filtrent automatiquement
- Les middlewares vérifient ownership au routing
- Les tests garantissent l'isolation tenant par tenant

**Conséquence:** La sécurité multi-tenant n'est pas ajoutée après-coup, elle est **structurelle**.

### 2. **Convention over Configuration**

**Opinion forte = DX supérieur.**

Au lieu de 50 choix de config, SaaSForge impose :
- DB partagée + scopes globaux (évolutif vers isolation si besoin)
- Index composites tenant-first
- `tenant_id` immutable
- Validation ownership automatique

**Trade-off conscient:** Moins de flexibilité → plus de productivité et de sécurité.

### 3. **Golden Path Exécutable**

Pas de documentation abstraite. SaaSForge fournit :
- **Migrations concrètes** (workspaces, members, projects)
- **Middleware exécutable** (SetCurrentWorkspace)
- **Traits réutilisables** (BelongsToWorkspace)
- **Tests patterns** (WorkspaceIsolationTest)

**Objectif:** Copier-coller et ça marche. Pas de "figure it out yourself".

### 4. **Defense in Depth**

La sécurité multi-tenant est trop critique pour une seule couche.

**Layers:**
1. **Routing** — Middleware vérifie workspace avant logique métier
2. **ORM** — Global scopes filtrent automatiquement
3. **Validation** — Policies vérifient ownership
4. **Runtime** — Firewall détecte fuites de données en sortie
5. **Static** — Linter détecte queries sans scope
6. **Testing** — Tests isolation auto-générés

**Philosophie:** Si une couche échoue, les autres protègent.

### 5. **Évolution Progressive**

**Pas de big bang architecture.**

SaaSForge permet :
- Commencer **MVP Fast** (single tenant)
- Évoluer vers **B2B Multi-Tenant** (shared DB)
- Migrer vers **Hybrid** (gros tenants isolés)
- Ajouter modules post-scaffold (`saas-forge add billing`)

**Path:** Simple → Complexe, sans refacto majeure.

---

## 🎨 Design Principles

### API-First

SaaSForge est un **backend pur** — frontend découplé.

**Rationale:**
- Flexibilité: Web, mobile, desktop utilisent la même API
- Évolution: Change le front sans toucher au back
- Ecosystem: Tiers peuvent intégrer facilement

### Mutualisé-Friendly

Conçu pour **hébergement mutualisé** (o2switch, shared hosting).

**Contraintes acceptées:**
- MySQL partagé (pas de DB multiples arbitraires)
- Ressources CPU/RAM limitées
- Pas de Kubernetes, Docker Swarm, etc.

**Optimisations:**
- DB partagée + scopes (perf maximale)
- Index tenant-first (queries rapides même avec millions de rows)
- Queues database (zero infra externe)

**Évolution future:** Peut migrer vers VPS/cloud si besoin scale.

### Production-Ready from Day One

**Pas de "TODO: add security later".**

Dès le scaffold :
- Auth moderne (Sanctum tokens + OAuth social)
- Tests isolation automatiques
- Structured logging avec corrélation
- Migration safety nets
- RBAC workspace-level

**Philosophy:** Si c'est dans SaaSForge, c'est production-grade.

---

## 📦 Core vs Optional

### Core (Toujours présent)

| Module | Rationale |
|--------|-----------|
| Auth Token (Sanctum) | Tout SaaS moderne a besoin d'auth API |
| Tenancy Foundation | Même single-tenant utilise le pattern ownership |
| RBAC Simple (Roles) | Minimum owner/member/admin distinction |
| Structured Logs | Debugging et observabilité essentiels |
| REST API | Interface standard moderne |
| Database Queues | Async minimal, zero infra |
| Email Notifications | Transactionnel obligatoire |
| Testing Infrastructure | Quality gate non-négociable |

### Optional (Modules/Presets)

Activés selon use case via presets ou `saas-forge add`:

- Social OAuth (B2C signup friction)
- Stripe Billing (monétisation)
- Advanced RBAC (permissions granulaires)
- Full Observability (Sentry, metrics)
- Redis Queues (heavy workloads)
- S3 Storage (uploads/media)
- Webhooks (intégrations)
- In-App Notifications (engagement)
- Admin Panel (backoffice ops)
- GraphQL (flexible API)
- Media Processing (images/video)
- CI/CD Templates (automation)

---

## 🚀 Success Metrics

### Pour les Utilisateurs

**Time to First Deploy:**
- MVP Fast preset: **48-72h** de zéro à production
- B2B Multi-Tenant: **2-3 semaines** avec features complètes

**Code Quality:**
- Test coverage: **>80%** sur core features
- Security: **Zero data leaks** grâce à defense in depth
- Performance: Queries **<50ms** même avec 100k rows par tenant

### Pour SaaSForge (Product)

**Adoption:**
- 100 projets déployés (6 mois)
- 1000 projets déployés (12 mois)
- 50 contributors actifs

**Quality:**
- Issues critiques résolues en <48h
- Documentation complète (guides + API + troubleshooting)
- Communauté active (Discord, GitHub Discussions)

---

## 🎯 Non-Goals (Scope Boundaries)

**SaaSForge ne fait PAS:**

- ❌ **Frontend** — API pure backend, front découplé
- ❌ **Hosting** — Pas un service managé, juste un template
- ❌ **No-Code** — Code-first, pas de builder visuel
- ❌ **Framework Custom** — Reste 100% Laravel standard
- ❌ **Vendor Lock-in** — Open source, MIT license, fork-friendly
- ❌ **Everything SaaS** — Focus Core, pas chaque niche possible

**Focus strict:** Architecture solide, conventions éprouvées, modules essentiels.

---

## 💡 Tagline & Positioning

**Tagline:**  
> "From idea to production SaaS in 48 hours, not 6 months."

**Elevator Pitch:**  
> SaaSForge est le starter-kit Laravel que j'aurais voulu avoir pour mes 10 derniers projets SaaS. Architecture multi-tenant solide, conventions fortes, sécurité by default. Clone, customise, ship.

**Target Statement:**  
> For developers and small teams who want to build SaaS products fast without compromising on quality, SaaSForge is an opinionated Laravel starter kit that provides production-ready architecture — unlike generic boilerplates that leave critical decisions unresolved.

---

## 🔮 Long-Term Vision (2-3 ans)

1. **SaaSForge devient le standard** pour Laravel SaaS projects
2. **Ecosystem de modules** communautaires (analytics, CRM, marketplace)
3. **AI-Powered Scaffolding** — "Décris ton SaaS" → code généré
4. **SaaSForge Cloud** (optionnel) — Managed hosting pour ceux qui veulent
5. **Case studies** — 50+ SaaS réels construits avec SaaSForge documentés

**North Star:** Réduire le time-to-market SaaS de 80% sans sacrifier la qualité.

---

**Version:** 1.0  
**Last Updated:** 2026-02-12  
**Maintainer:** Mickael Lherminez
