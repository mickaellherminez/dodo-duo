# Guide de Test API - Stories 2.1, 2.2 & 2.3

## Prérequis

1. **Serveur Laravel lancé**
```bash
php artisan serve
# Serveur disponible sur http://localhost:8000
```

2. **Base de données prête**
```bash
php artisan migrate:fresh --seed
```
> Si vous utilisez une base existante (sans `migrate:fresh`), exécutez au minimum `php artisan migrate` pour appliquer les dernières migrations (ex: `workspace_invitations`).

## Étape 1 : Créer un utilisateur de test

### Via le Seeder (Recommandé)

```bash
php artisan db:seed --class=TestUserSeeder
```

Cela crée un utilisateur avec :
- **Email**: test@example.com
- **Password**: password123

### Ou via Tinker (Manuel)

```bash
php artisan tinker
```

```php
User::factory()->create([
    'name' => 'Test User',
    'email' => 'test@example.com',
    'password' => bcrypt('password123')
]);
```

## Étape 2 : Générer un token API

```bash
php artisan tinker
```

```php
User::where('email', 'test@example.com')->first()->createToken('test')->plainTextToken
```

**💡 Copiez ce token** (commence par `1|...`)

> **Note**: L'authentification complète (login/register endpoints) sera implémentée dans Epic 3.

## Étape 3 : Tester les endpoints avec Postman

### Configuration Postman

1. **Créer une nouvelle collection** : "SaaSForge API"
2. **Ajouter une variable d'environnement** :
    - `base_url` : `http://localhost:8000`
    - `api_token` : `[VOTRE_TOKEN_ICI]`
    - `api_token_other` : `[TOKEN_D_UN_AUTRE_UTILISATEUR]` (pour les tests non-member)

3. **Configurer l'authentification pour toutes les requêtes** :
   - Type : Bearer Token
   - Token : `{{api_token}}`

### Tests automatisés (Newman)

Vous pouvez exécuter toute la collection avec le script :

```bash
./docs/postman/run-postman.sh
```

**Pré-requis :**
- API démarrée (`php artisan serve`)
- Base de données prête (migrations appliquées)
- Node.js installé (pour `npx`/`newman`)

**Variables optionnelles (si besoin) :**

```bash
# Changer l'URL de base
POSTMAN_BASE_URL="http://localhost:8000" ./docs/postman/run-postman.sh

# Forcer un token si vous ne voulez pas passer par l'étape d'inscription
POSTMAN_API_TOKEN="1|..." POSTMAN_API_TOKEN_OTHER="2|..." ./docs/postman/run-postman.sh

# OAuth (si vous voulez tester les callbacks réels)
POSTMAN_OAUTH_STATE="..." POSTMAN_OAUTH_CODE_VERIFIER="..." \
POSTMAN_OAUTH_GOOGLE_CODE="..." POSTMAN_OAUTH_GITHUB_CODE="..." \
./docs/postman/run-postman.sh

# Activer l'invitation/acceptation automatique du 2e utilisateur (setup)
# Par défaut, ce setup est désactivé pour éviter les invitations en double.
POSTMAN_SETUP_INVITE_ENABLED=true ./docs/postman/run-postman.sh

# Certificats locaux auto-signés (https)
POSTMAN_INSECURE=1 ./docs/postman/run-postman.sh

# Ajouter un délai entre requêtes (utile si vous relancez souvent et rencontrez des 429)
POSTMAN_DELAY_REQUEST_MS=500 ./docs/postman/run-postman.sh
```

**Astuce :** vous pouvez aussi cibler un dossier spécifique :
```bash
POSTMAN_FOLDER="Workspace Members" ./docs/postman/run-postman.sh
```

### Process Newman/Postman recommandé (fiable)

Quand vous lancez la collection complète, suivez cet ordre pour éviter les erreurs en cascade :

1. Démarrer l'API (`php artisan serve`)
2. Vérifier la base (migrations appliquées)
3. Lancer le dossier `Setup` seul (optionnel mais recommandé si environnement instable)
4. Lancer la collection complète

Exemple :

```bash
# 1) Préparer les variables/setup (crée user 2, workspace de test, etc.)
POSTMAN_FOLDER="Setup" POSTMAN_DELAY_REQUEST_MS=500 ./docs/postman/run-postman.sh

# 2) Lancer la collection complète
POSTMAN_DELAY_REQUEST_MS=500 ./docs/postman/run-postman.sh
```

### Relancer après un run (important)

Si vous exécutez Newman 2 fois de suite, vous pouvez voir des `429 Too Many Requests` sur les endpoints `auth/*` (rate limiting).

Recommandation :

```bash
# Attendre ~60s puis relancer (le plus simple)
POSTMAN_DELAY_REQUEST_MS=500 ./docs/postman/run-postman.sh
```

Option locale utile :

```bash
php artisan cache:clear
POSTMAN_DELAY_REQUEST_MS=500 ./docs/postman/run-postman.sh
```

### Comprendre les erreurs en cascade (401 / 404 / 405)

Un échec précoce dans le `Setup` peut provoquer beaucoup d'erreurs "fausses" ensuite :

- `Register Second User` en `429` -> `api_token_other` non défini -> plusieurs requêtes en `401`
- `Create Non-Member Workspace` non exécuté -> `non_member_workspace_id` absent -> tests en `404`
- variables d'ID non définies -> URL incomplètes -> parfois `405`

La collection a été durcie pour réduire ces cascades (fallback login + `skipRequest()` sur variables manquantes), mais si vous voyez beaucoup d'échecs à la fois, vérifiez d'abord le dossier `Setup`.

### Notes de compatibilité avec les messages d'erreur API

- Depuis la standardisation des erreurs API, les `403` peuvent retourner le message :
  - `This action is unauthorized.`
- Certains endpoints métier peuvent encore retourner un message métier spécifique selon le contrôleur.

Si le contrat d'erreur évolue, mettez à jour les assertions de la collection (`docs/postman/SaaSForge-API-v1.postman_collection.json`).

### 1. POST - Créer un workspace

```
POST {{base_url}}/api/v1/workspaces
```

**Headers:**
```
Authorization: Bearer {{api_token}}
Content-Type: application/json
```

**Body (JSON):**
```json
{
    "name": "Mon Premier Workspace",
    "slug": "mon-premier-workspace",
    "domain": "premier.example.com",
    "settings": {
        "theme": "dark",
        "timezone": "Europe/Paris"
    }
}
```

**Réponse attendue (201 Created):**
```json
{
    "data": {
        "id": 4,
        "name": "Mon Premier Workspace",
        "slug": "mon-premier-workspace",
        "domain": "premier.example.com",
        "status": "active",
        "settings": {
            "theme": "dark",
            "timezone": "Europe/Paris"
        },
        "owner": {
            "id": 1,
            "name": "Test User",
            "email": "test@example.com"
        },
        "member_count": 1,
        "created_at": "2026-02-14T13:30:00.000000Z",
        "updated_at": "2026-02-14T13:30:00.000000Z"
    }
}
```

### 2. GET - Lister tous les workspaces

```
GET {{base_url}}/api/v1/workspaces
```

**Headers:**
```
Authorization: Bearer {{api_token}}
```

**Réponse attendue (200 OK):**
```json
{
    "data": [
        {
            "id": 1,
            "name": "ACME Corporation",
            "slug": "acme-corporation",
            "domain": null,
            "status": "active",
            "settings": [],
            "owner": {
                "id": 1,
                "name": "Test User",
                "email": "test@example.com"
            },
            "member_count": 1,
            "created_at": "2026-02-14T12:00:00.000000Z",
            "updated_at": "2026-02-14T12:00:00.000000Z"
        }
    ]
}
```

### 3. GET - Lister MES workspaces (Story 2.2)

```
GET {{base_url}}/api/v1/my/workspaces
```

**Headers:**
```
Authorization: Bearer {{api_token}}
```

**Description:**
Retourne uniquement les workspaces où l'utilisateur authentifié est membre (owner, admin ou member).

**Réponse attendue (200 OK):**
```json
{
    "data": [
        {
            "id": 4,
            "name": "Mon Premier Workspace",
            "slug": "mon-premier-workspace",
            "domain": "premier.example.com",
            "status": "active",
            "settings": {
                "theme": "dark",
                "timezone": "Europe/Paris"
            },
            "owner": {
                "id": 1,
                "name": "Test User",
                "email": "test@example.com"
            },
            "member_count": 1,
            "created_at": "2026-02-14T13:30:00.000000Z",
            "updated_at": "2026-02-14T13:30:00.000000Z"
        }
    ]
}
```

**Points clés :**
- ✅ Retourne seulement les workspaces où je suis membre
- ✅ Inclut `owner` et `member_count`
- ✅ Triés par date de membership (plus récent en premier)
- ✅ Requiert authentification (401 sans token)

### 4. GET - Voir un workspace spécifique

```
GET {{base_url}}/api/v1/workspaces/1
```

**Headers:**
```
Authorization: Bearer {{api_token}}
```

**Réponse attendue (200 OK):**
```json
{
    "data": {
        "id": 1,
        "name": "ACME Corporation",
        "slug": "acme-corporation",
        "domain": null,
        "status": "active",
        "settings": [],
        "owner": {
            "id": 1,
            "name": "Test User",
            "email": "test@example.com"
        },
        "member_count": 1,
        "created_at": "2026-02-14T12:00:00.000000Z",
        "updated_at": "2026-02-14T12:00:00.000000Z"
    }
}
```

### 5. PATCH - Mettre à jour un workspace

```
PATCH {{base_url}}/api/v1/workspaces/1
```

**Headers:**
```
Authorization: Bearer {{api_token}}
Content-Type: application/json
```

**Body (JSON):**
```json
{
    "name": "ACME Corporation - Updated",
    "settings": {
        "new_feature": "enabled"
    }
}
```

**Note:** Le slug ne peut PAS être modifié. Les settings sont mergés avec les existants.

**Réponse attendue (200 OK):**
```json
{
    "data": {
        "id": 1,
        "name": "ACME Corporation - Updated",
        "slug": "acme-corporation",
        "domain": null,
        "status": "active",
        "settings": {
            "new_feature": "enabled"
        },
        "owner": {
            "id": 1,
            "name": "Test User",
            "email": "test@example.com"
        },
        "member_count": 1,
        "created_at": "2026-02-14T12:00:00.000000Z",
        "updated_at": "2026-02-14T13:35:00.000000Z"
    }
}
```

### 6. DELETE - Supprimer un workspace

```
DELETE {{base_url}}/api/v1/workspaces/1
```

**Headers:**
```
Authorization: Bearer {{api_token}}
```

**Réponse attendue (204 No Content):**
```
(Pas de body, juste le code 204)
```

**Note:** Seul le propriétaire (owner) peut supprimer le workspace.

## Étape 4 : Tester avec cURL (alternative à Postman)

### Créer un workspace
```bash
curl -X POST http://localhost:8000/api/v1/workspaces \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Test Workspace",
    "slug": "test-workspace",
    "domain": "test.example.com"
  }'
```

### Lister les workspaces
```bash
curl -X GET http://localhost:8000/api/v1/workspaces \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

### Voir un workspace
```bash
curl -X GET http://localhost:8000/api/v1/workspaces/1 \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

### Mettre à jour un workspace
```bash
curl -X PATCH http://localhost:8000/api/v1/workspaces/1 \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Updated Name"
  }'
```

### Supprimer un workspace
```bash
curl -X DELETE http://localhost:8000/api/v1/workspaces/1 \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

## Tests de Validation

### 1. Slug invalide (doit échouer)
```json
{
    "name": "Test",
    "slug": "Test Workspace!"  // Espaces et ! non autorisés
}
```
**Erreur attendue (422):**
```json
{
    "message": "The slug may only contain lowercase letters, numbers, and hyphens.",
    "errors": {
        "slug": [
            "The slug may only contain lowercase letters, numbers, and hyphens."
        ]
    }
}
```

### 2. Slug dupliqué (doit échouer)
```json
{
    "name": "Test",
    "slug": "acme-corporation"  // Déjà existant
}
```
**Erreur attendue (422):**
```json
{
    "message": "The slug has already been taken.",
    "errors": {
        "slug": [
            "The slug has already been taken."
        ]
    }
}
```

### 3. Essayer de modifier le slug (doit échouer)
```json
{
    "slug": "new-slug"
}
```
**Erreur attendue (422):**
```json
{
    "message": "The slug cannot be updated.",
    "errors": {
        "slug": [
            "The slug cannot be updated."
        ]
    }
}
```

### 4. Tenter de supprimer en tant que membre (doit échouer)

Dans Tinker, créez un autre utilisateur et ajoutez-le comme membre :
```php
$workspace = App\Models\Workspace::find(1);
$member = App\Models\User::factory()->create();
$workspace->members()->attach($member->id, ['role' => 'member']);

// Créer token pour le membre
$memberToken = $member->createToken('member-token')->plainTextToken;
echo $memberToken;
```

Puis essayez de supprimer avec le token du membre :
**Erreur attendue (403 Forbidden):**
```json
{
    "message": "This action is unauthorized."
}
```

## Tests d'Autorisation

### Accès en tant que propriétaire (owner)
✅ Peut lister, voir, créer, modifier, supprimer

### Accès en tant qu'admin
✅ Peut lister, voir, modifier
❌ Ne peut PAS supprimer

### Accès en tant que membre
✅ Peut lister, voir
❌ Ne peut PAS modifier ni supprimer

### Sans authentification
❌ Toutes les requêtes retournent 401 Unauthorized

## Vérifications en Base de Données

Après avoir créé un workspace, vérifiez :

```bash
php artisan tinker
```

```php
// Vérifier que le workspace existe
$workspace = App\Models\Workspace::latest()->first();
echo $workspace->name;

// Vérifier que le membership a été créé
$workspace->members()->get();
// Devrait afficher le créateur avec role = 'owner'

// Vérifier la relation owner
$workspace->owner;
// Devrait afficher l'utilisateur authentifié
```

## Story 2.3 - Workspace Switching & Context Management

### 1. POST - Switch to Workspace

Permet à un utilisateur de changer de workspace actif en obtenant un nouveau token avec le contexte du workspace.

```
POST {{base_url}}/api/v1/my/workspaces/{workspace_id}/switch
```

**Headers:**
```
Authorization: Bearer {{api_token}}
Content-Type: application/json
```

**Réponse attendue (200 OK):**
```json
{
    "message": "Workspace switched successfully.",
    "workspace": {
        "id": 1,
        "name": "Mon Workspace",
        "slug": "mon-workspace",
        "domain": null,
        "status": "active",
        "owner_id": 1,
        "created_at": "2026-02-15T10:00:00.000000Z",
        "updated_at": "2026-02-15T10:00:00.000000Z"
    },
    "token": "2|AbCdEfGhIjKlMnOpQrStUvWxYz..."
}
```

**Réponse erreur (403 Forbidden) - Non-membre:**
```json
{
    "message": "You do not have access to this workspace."
}
```

**💡 Important:** 
- Le nouveau token contient les abilities `["workspace:{id}"]`
- Utilisez ce token pour les requêtes suivantes dans ce workspace
- Les anciens tokens restent valides avec leur workspace context original

### 2. GET - Current Workspace

Récupère le workspace actif basé sur le token utilisé.

```
GET {{base_url}}/api/v1/my/current-workspace
```

**Headers:**
```
Authorization: Bearer {{workspace_token}}
```

**Réponse attendue (200 OK):**
```json
{
    "data": {
        "id": 1,
        "name": "Mon Workspace",
        "slug": "mon-workspace",
        "domain": null,
        "status": "active",
        "owner_id": 1,
        "created_at": "2026-02-15T10:00:00.000000Z",
        "updated_at": "2026-02-15T10:00:00.000000Z"
    },
    "user_role": "owner"
}
```

**Réponse erreur (404 Not Found) - Pas de context:**
```json
{
    "message": "No workspace context is currently set."
}
```

### Scénario de test complet

```bash
php artisan tinker
```

```php
// 1. Créer un utilisateur et deux workspaces
$user = User::factory()->create();
$workspaceA = Workspace::factory()->create(['owner_id' => $user->id]);
$workspaceB = Workspace::factory()->create(['owner_id' => $user->id]);

// Ajouter l'utilisateur comme membre
$workspaceA->addMember($user, 'owner');
$workspaceB->addMember($user, 'owner');

// 2. Générer un token initial
$initialToken = $user->createToken('initial')->plainTextToken;
echo "Initial Token: {$initialToken}\n";

// 3. Utiliser ce token pour switcher vers WorkspaceA
// POST /api/v1/my/workspaces/{$workspaceA->id}/switch
// Réponse contient un nouveau token avec abilities: ["workspace:{$workspaceA->id}"]

// 4. Utiliser le nouveau token pour obtenir le workspace actif
// GET /api/v1/my/current-workspace
// Réponse: workspaceA avec user_role: "owner"

// 5. Switcher vers WorkspaceB avec un autre token
// POST /api/v1/my/workspaces/{$workspaceB->id}/switch
// Nouveau token avec abilities: ["workspace:{$workspaceB->id}"]

// Les deux tokens restent valides simultanément!
```

### Token-Based Workspace Resolution

Le middleware `SetCurrentWorkspace` résout le workspace dans cet ordre :

1. **Subdomain** : `acme.saasforge.app` → workspace slug = 'acme'
2. **Custom Domain** : `app.acme.com` → workspace domain
3. **Header** : `X-Workspace-ID: 123` → workspace id = 123
4. **Route Parameter** : `/workspaces/{id}` → workspace id
5. **Token Abilities** : `workspace:123` → workspace id = 123 ✨ (Story 2.3)

La stratégie token permet de maintenir le context workspace sans headers supplémentaires.

## Collection Postman

Une collection Postman prête à l'emploi est disponible :  
📁 `docs/postman/SaaSForge-API-v1.postman_collection.json`

**Import :**
1. Postman → File → Import
2. Sélectionner le fichier JSON
3. Variables → `api_token` → Coller votre token généré
4. Variables → `api_token_other` → Coller un token d'un autre utilisateur

**Note:** Exécutez les requests de setup dans l'ordre, y compris
"Create Non-Member Workspace (Setup - Other User)" pour les tests 403.

**OAuth (Story 3.3)** : les tests Postman couvrent les redirects, l'état invalide,
et les callbacks succès **uniquement si** `oauth_google_code` / `oauth_github_code`
et `oauth_state` sont fournis.

**Pourquoi le `state` peut échouer (422)**  
Le `state` est **unique** et **consommé** dès que le callback est appelé.  
Si le navigateur appelle le callback avant Newman, le `state` disparaît du cache.

**Procédure fiable pour tester le callback succès (Newman)**  
1. Vérifiez que `CACHE_STORE=file`, puis `php artisan config:clear`.
2. Ouvrez `/api/v1/auth/google/redirect` (ou GitHub).
3. Juste avant de valider l’autorisation, **stoppez le serveur** (`Ctrl+C`).
4. Validez l’autorisation : l’URL de callback s’affiche, mais ne se charge pas.
5. Copiez `code` et `state` depuis l’URL.
6. Redémarrez le serveur, puis lancez Newman immédiatement.

**Runner CLI (Newman) :**

```bash
./docs/postman/run-postman.sh

# Overrides optionnels
POSTMAN_BASE_URL=http://localhost:8000 \
POSTMAN_API_TOKEN="1|your-token" \
POSTMAN_API_TOKEN_OTHER="1|other-token" \
./docs/postman/run-postman.sh
```

**Exemple OAuth (Google) :**

```bash
POSTMAN_OAUTH_STATE="LE_STATE" \
POSTMAN_OAUTH_GOOGLE_CODE="LE_CODE_GOOGLE" \
POSTMAN_FOLDER="OAuth Social Auth" \
./docs/postman/run-postman.sh
```

**Variables supportées :**
- `POSTMAN_BASE_URL` : URL de l’API (ex: `http://localhost:8000`)
- `POSTMAN_API_TOKEN` : token principal (Bearer)
- `POSTMAN_API_TOKEN_OTHER` : token secondaire (tests 403)
- `POSTMAN_OAUTH_STATE` : `state` OAuth récupéré après le redirect
- `POSTMAN_OAUTH_GOOGLE_CODE` : `code` OAuth Google (usage unique)
- `POSTMAN_OAUTH_GITHUB_CODE` : `code` OAuth GitHub (usage unique)
- `POSTMAN_EMAIL_VERIFICATION_URL` : URL signée de vérification email (optionnel)
- `POSTMAN_FOLDER` : exécuter un seul dossier de la collection (ex: `OAuth Social Auth`)
- `POSTMAN_FOLDERS` : exécuter plusieurs dossiers (séparés par des virgules)
- `POSTMAN_INSECURE` : ajoute `--insecure` (utile si certificat TLS local)

**Exemples rapides :**
```bash
# Un seul dossier
POSTMAN_FOLDER="OAuth Social Auth" ./docs/postman/run-postman.sh

# Plusieurs dossiers
POSTMAN_FOLDERS="OAuth Social Auth,My Workspaces" ./docs/postman/run-postman.sh
```

## Troubleshooting

### Erreur 401 Unauthorized
- Vérifiez que le token est correct
- Vérifiez que `Authorization: Bearer {token}` est dans les headers
- Vérifiez que Sanctum est configuré (déjà fait dans Story 2.1)

### Erreur 403 Forbidden
- L'utilisateur n'a pas les permissions nécessaires
- Vérifiez le rôle dans `workspace_members` (owner/admin/member)

### Erreur 404 Not Found
- Le workspace n'existe pas ou l'utilisateur n'y a pas accès
- Vérifiez l'ID du workspace

### Erreur 500 Internal Server Error
- Consultez `storage/logs/laravel.log`
- Vérifiez que les migrations sont à jour

## Prochaines étapes

Story 2.4 (à venir) : Workspace Isolation Validation Tests
- Tests de sécurité pour l'isolation entre workspaces
- Validation que les utilisateurs ne peuvent pas accéder aux données d'autres workspaces
- Tests de tous les scénarios de résolution de workspace

Epic 3 (à venir) : User Authentication System
- Endpoints de login/register
- Gestion des mots de passe
- Vérification email

---

**Bon test! 🚀**
