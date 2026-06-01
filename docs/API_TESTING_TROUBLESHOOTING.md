# Solutions pour Tester l'API - Problème de Slug Duplicate

## 🔴 Problème : ECONNREFUSED (API injoignable)

**Erreur probable :**
```
connect ECONNREFUSED 127.0.0.1:8000
```

**Solutions :**
- Vérifier que l’API tourne : `php artisan serve`
- Vérifier l’URL utilisée par Newman/Postman :
```bash
POSTMAN_BASE_URL="http://localhost:8000" ./docs/postman/run-postman.sh
```

## 🔴 Problème : tests OAuth “Skipping”

**Message :**
```
Skipping: oauth_google_code or oauth_state not set
```

**Explication :**
Ces tests sont optionnels. Ils passent en mode “skip” tant que vous ne fournissez pas les variables OAuth.

**Solution :**
```bash
POSTMAN_OAUTH_STATE="..." POSTMAN_OAUTH_CODE_VERIFIER="..." \
POSTMAN_OAUTH_GOOGLE_CODE="..." POSTMAN_OAUTH_GITHUB_CODE="..." \
./docs/postman/run-postman.sh
```

## 🔴 Problème : 429 Too Many Requests (rate limiting)

**Message :**
```
429 Too Many Requests
```

**Explication :**
Les endpoints auth sont limités en débit. Les tests acceptent 429 dans certains cas.

**Solutions :**
- Attendre 60s et relancer.
- Éviter de lancer plusieurs collections en parallèle.
- Ajouter un délai entre requêtes Newman (recommandé en rerun) :
```bash
POSTMAN_DELAY_REQUEST_MS=500 ./docs/postman/run-postman.sh
```
- Option locale si vous venez d'enchaîner plusieurs runs :
```bash
php artisan cache:clear
POSTMAN_DELAY_REQUEST_MS=500 ./docs/postman/run-postman.sh
```

## 🔴 Problème : cascade d’erreurs (401 / 404 / 405) après un échec de setup

**Symptômes typiques :**
- `Register Second User` retourne `429`
- puis plusieurs tests tombent en `401`
- des tests "non-member workspace" tombent en `404`
- certains tests members/projets tombent en `405`

**Explication :**
Ce n’est souvent **pas** 20 bugs différents. C’est un échec précoce du `Setup` :
- `api_token_other` non défini (suite à un `429` ou un setup incomplet)
- `non_member_workspace_id` non créé
- IDs/variables non remplies, donc URLs invalides

**Solution (ordre recommandé) :**
```bash
# 1) Attendre ~60s si vous avez déjà lancé Newman récemment

# 2) (Optionnel) Nettoyer le cache local
php artisan cache:clear

# 3) Lancer d'abord le setup
POSTMAN_FOLDER="Setup" POSTMAN_DELAY_REQUEST_MS=500 ./docs/postman/run-postman.sh

# 4) Puis la collection complète
POSTMAN_DELAY_REQUEST_MS=500 ./docs/postman/run-postman.sh
```

## 🔴 Problème : Assertions qui échouent après modification des messages

Si vous modifiez les messages d’erreur dans l’API, il faut adapter les assertions dans la collection Postman.
Les tests comparent souvent des fragments de message (ex: “last owner”).

Note : depuis la standardisation des erreurs API, plusieurs `403` utilisent maintenant le message
`This action is unauthorized.`

**Solution :**
- Mettre à jour la collection `docs/postman/SaaSForge-API-v1.postman_collection.json`.

## 🔴 Problème : 422 “duplicate pending invitation” dans Newman

**Explication :**
Le setup peut créer une invitation pour le second utilisateur. Le test
“Accept Invitation - Success” crée aussi une invitation pour le même email.
Si les deux sont actifs, l’API retourne 422 (invitation déjà en attente).

**Solution :**
- Laisser le setup d’invitation **désactivé par défaut** (comportement courant).
- Si vous devez le réactiver, lancez uniquement le dossier Setup, ou utilisez un email différent.
  Exemple :
  `POSTMAN_SETUP_INVITE_ENABLED=true POSTMAN_FOLDER="Setup" ./docs/postman/run-postman.sh`

## 🔴 Problème

Vous ne pouvez pas recréer un workspace car **le slug existe déjà** dans la base de données.

**Erreur probable :**
```json
{
    "message": "The slug has already been taken.",
    "errors": {
        "slug": ["The slug has already been taken."]
    }
}
```

## ✅ Solutions

### Solution 1 : Changer le slug à chaque test (Recommandé)

Ajoutez un numéro ou timestamp au slug :

```json
{
    "name": "Test Workspace 2",
    "slug": "test-workspace-2",
    "domain": "test2.example.com"
}
```

Ou utilisez un timestamp :
```json
{
    "name": "Test Workspace",
    "slug": "test-workspace-1739537421",
    "domain": "test.example.com"
}
```

### Solution 2 : Supprimer le workspace avant de recréer

**Via Postman :**
1. Copier l'ID du workspace créé (ex: `4`)
2. Utiliser la requête "Delete Workspace"
3. Remplacer `:id` par l'ID copié
4. Send (retourne 204 No Content)
5. Vous pouvez maintenant recréer avec le même slug

**Via cURL :**
```bash
curl -X DELETE http://localhost:8000/api/v1/workspaces/4 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Solution 3 : Réinitialiser complètement la base (Méthode rapide)

**✅ Recommandé - Utilise une commande Artisan**

```bash
# Supprimer tous les workspaces et tokens de test
php artisan api:reset-test-data

# Ou sans confirmation
php artisan api:reset-test-data --force
```

Cette commande :
- ✓ Supprime tous les workspaces (y compris soft-deleted)
- ✓ Supprime tous les workspace_members
- ✓ Révoque tous les tokens du user de test
- ✓ Vous donne la commande pour régénérer un token

**Après reset, régénérez un token :**
```bash
php artisan tinker
>>> User::find(1)->createToken('test')->plainTextToken
```

### Solution 4 : Réinitialiser avec migration (Reset complet)

**⚠️ Supprime TOUTES les données !**

```bash
# Réinitialiser la base de données
php artisan migrate:fresh --seed

# Recréer votre utilisateur de test
php artisan db:seed --class=TestUserSeeder

# Régénérer un token
php artisan tinker
>>> User::where('email', 'test@example.com')->first()->createToken('test')->plainTextToken
```

### Solution 5 : Voir les workspaces existants

**Via Tinker :**
```bash
php artisan tinker
```

```php
// Voir tous les workspaces (même soft-deleted)
Workspace::withTrashed()->get(['id', 'name', 'slug', 'deleted_at']);

// Supprimer un workspace spécifique
Workspace::find(4)->delete();

// Ou forcer la suppression permanente
Workspace::withTrashed()->find(4)->forceDelete();
```

**Via API (Postman) :**
```
GET http://localhost:8000/api/v1/workspaces
```
Notez les IDs retournés.

## 🎯 Workflow Recommandé pour Tester

### 1. Créer avec un slug unique
```json
POST /api/v1/workspaces
{
    "name": "Test 1",
    "slug": "test-1"
}
```

### 2. Tester l'update
```json
PATCH /api/v1/workspaces/4
{
    "name": "Test 1 Updated"
}
```

### 3. Tester les validations
```json
POST /api/v1/workspaces
{
    "name": "Test Duplicate",
    "slug": "test-1"  // ❌ Doit échouer - slug déjà pris
}
```

### 4. Nettoyer
```
DELETE /api/v1/workspaces/4
```

### 5. Recréer si besoin
```json
POST /api/v1/workspaces
{
    "name": "Test 2",
    "slug": "test-2"  // ✅ Nouveau slug
}
```

## 💡 Astuce Postman

Créez une **variable d'environnement dynamique** pour le slug :

1. Dans Postman, onglet "Pre-request Script" :
```javascript
pm.environment.set("random_slug", "test-" + Date.now());
```

2. Dans votre requête :
```json
{
    "name": "Test Workspace",
    "slug": "{{random_slug}}"
}
```

Chaque requête aura un slug unique automatiquement !

## 🔍 Vérifier l'état de la base

```bash
# Via SQL direct
php artisan tinker
>>> DB::table('workspaces')->select('id', 'slug', 'deleted_at')->get();

# Compter les workspaces
>>> Workspace::count();           // Actifs
>>> Workspace::withTrashed()->count();  // Total (y compris deleted)
```

## 📝 Checklist de Test Complète

- [ ] **Créer** un workspace avec slug unique
- [ ] **Lister** tous les workspaces
- [ ] **Voir** le workspace créé (GET /workspaces/{id})
- [ ] **Modifier** le nom du workspace
- [ ] **Tester validation** : slug invalide (`Test Slug!`)
- [ ] **Tester validation** : slug duplicate (réutiliser un slug existant)
- [ ] **Tester validation** : tenter de modifier le slug (doit échouer)
- [ ] **Supprimer** le workspace
- [ ] **Recréer** avec le même slug (doit fonctionner maintenant)

---

**Bon test ! 🚀**
