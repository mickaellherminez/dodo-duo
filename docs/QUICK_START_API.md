# Guide de Démarrage Rapide - Test de l'API

## 🚀 3 étapes pour tester l'API en 2 minutes

### 1️⃣ Créer un utilisateur de test

```bash
# Créer l'utilisateur de test (une seule fois)
php artisan db:seed --class=TestUserSeeder
```

**Identifiants créés :**
- Email: `test@example.com`
- Password: `password123`

### 2️⃣ Générer un token API

```bash
php artisan tinker
```

Dans Tinker :
```php
User::where('email', 'test@example.com')->first()->createToken('test')->plainTextToken
```

**Copiez le token affiché** (commence par `1|...`)

### 3️⃣ Tester avec Postman ou cURL

#### Option A : Postman (Recommandé)

1. **Importer** : `docs/postman/SaaSForge-API-v1.postman_collection.json`
2. **Configurer** : Variables → `api_token` → Coller votre token
3. **Tester** : "List All Workspaces" → Send

#### Option B : cURL

```bash
# Remplacez YOUR_TOKEN par le token copié
export API_TOKEN="YOUR_TOKEN"

# Créer un workspace
curl -X POST http://localhost:8000/api/v1/workspaces \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Mon Workspace",
    "slug": "mon-workspace"
  }'

# Lister les workspaces
curl -X GET http://localhost:8000/api/v1/workspaces \
  -H "Authorization: Bearer $API_TOKEN"
```

---

## 🎯 Endpoints disponibles (Story 2.1 & 2.2)

| Méthode | Endpoint | Description |
|---------|----------|-------------|
| GET | `/api/v1/my/workspaces` | Liste MES workspaces (membre) |
| GET | `/api/v1/workspaces` | Liste tous les workspaces |
| POST | `/api/v1/workspaces` | Crée un workspace |
| GET | `/api/v1/workspaces/{id}` | Détails d'un workspace |
| PATCH | `/api/v1/workspaces/{id}` | Met à jour un workspace |
| DELETE | `/api/v1/workspaces/{id}` | Supprime un workspace |

## 📚 Documentation complète

Voir [API_TESTING_GUIDE.md](./API_TESTING_GUIDE.md) pour :
- Tests de validation
- Tests d'autorisation
- Exemples de réponses
- Cas d'erreur

## 🔄 Réinitialiser les données de test

Si vous voulez recommencer à zéro :

```bash
# Supprimer tous les workspaces et tokens
php artisan api:reset-test-data --force

# Régénérer un token
php artisan tinker
>>> User::find(1)->createToken('test')->plainTextToken
```

Voir [API_TESTING_TROUBLESHOOTING.md](./API_TESTING_TROUBLESHOOTING.md) pour plus de solutions.

## ✅ Tests automatisés

```bash
# Lancer tous les tests
./vendor/bin/pest

# Tests API uniquement
./vendor/bin/pest --filter=WorkspaceController
```

**Résultat actuel** : 246 tests passing ✅

---

**Prochaine étape** : Story 2.2 ajoutera `/api/v1/my/workspaces` 🚀
