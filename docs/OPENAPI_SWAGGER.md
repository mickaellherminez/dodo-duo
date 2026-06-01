# OpenAPI / Swagger Guide

## Purpose

SaaSForge exposes an interactive Swagger UI powered by L5-Swagger and `swagger-php` annotations.

Use it to:

- discover available endpoints
- inspect request/response schemas
- test authenticated endpoints without leaving the browser

## Quick Start

### 1. Generate the OpenAPI spec

```bash
php artisan l5-swagger:generate
```

This generates:

- `storage/api-docs/api-docs.json`

### 2. Start the application

```bash
php artisan serve
```

### 3. Open Swagger UI

Open:

- `http://127.0.0.1:8000/api/documentation`

## Using Authenticated Endpoints (Sanctum Bearer Token)

For protected endpoints:

1. Call `POST /api/v1/auth/login` (or create a token using your usual local workflow).
2. Copy the returned token.
3. Click `Authorize` in Swagger UI.
4. Paste the token as a bearer token and confirm.

After authorization, you can execute protected requests directly from the UI.

## When You Add or Change an Endpoint

1. Add or update `@OA` annotations in the relevant controller.
2. Regenerate the spec:

```bash
php artisan l5-swagger:generate
```

3. Refresh `/api/documentation`.

## Where the OpenAPI Metadata Lives

- Global API metadata (title, server, security scheme, tags):
  - `app/Http/Controllers/Controller.php`
- Endpoint annotations:
  - `app/Http/Controllers/Api/V1/*.php`
- L5-Swagger config:
  - `config/l5-swagger.php`
- Generated spec output:
  - `storage/api-docs/api-docs.json`

## Implementation Note (Important)

This project uses PHPDoc `@OA` annotations and a small runtime integration so L5-Swagger can scan both:

- PHP attributes
- PHPDoc annotations

Key files:

- `app/Providers/AppServiceProvider.php`
- `app/Swagger/DocblockAwareL5SwaggerGenerator.php`
- `app/Swagger/DocblockAwareL5SwaggerGeneratorFactory.php`

This keeps `php artisan config:cache` compatible while still supporting `@OA` annotations.

## Troubleshooting

### Spec generation fails

Run:

```bash
php artisan l5-swagger:generate
```

Then use the lower-level parser for clearer annotation errors:

```bash
./vendor/bin/openapi app --format json
```

### Swagger UI does not show your latest changes

- Regenerate the spec (`php artisan l5-swagger:generate`)
- Refresh the page
- Confirm `storage/api-docs/api-docs.json` was updated

### Config cache issues after Swagger changes

Verify config caching still works:

```bash
php artisan config:cache
php artisan config:clear
```
