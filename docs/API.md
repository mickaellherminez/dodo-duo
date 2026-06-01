# API Usage Guide

This guide provides practical examples for common SaaSForge API workflows using `curl`, JavaScript/TypeScript, and PHP.

## Quick Links

- Interactive Swagger UI: `/api/documentation`
- OpenAPI/Swagger guide: [`docs/OPENAPI_SWAGGER.md`](./OPENAPI_SWAGGER.md)
- Postman collection: [`docs/postman/SaaSForge-API-v1.postman_collection.json`](./postman/SaaSForge-API-v1.postman_collection.json)
- Local Postman environment: [`docs/postman/SaaSForge-Local.postman_environment.json`](./postman/SaaSForge-Local.postman_environment.json)
- Newman runner: [`docs/postman/run-postman.sh`](./postman/run-postman.sh)
- API testing workflow: [`docs/API_TESTING_GUIDE.md`](./API_TESTING_GUIDE.md)

## Base URL

Default local base URL:

```bash
export BASE_URL="http://127.0.0.1:8000"
```

All API routes are versioned under `/api/v1`.

Examples in this document assume:

```bash
export API_BASE="$BASE_URL/api/v1"
```

Health check (public):

```bash
curl "$BASE_URL/api/health"
```

## Authentication

### Register

```bash
curl -X POST "$API_BASE/auth/register" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Jane Doe",
    "email": "jane@example.com",
    "password": "Secret123!",
    "password_confirmation": "Secret123!"
  }'
```

Typical success response (`201`):

```json
{
  "data": {
    "user": {
      "id": 1,
      "name": "Jane Doe",
      "email": "jane@example.com"
    },
    "token": "1|..."
  }
}
```

### Login

```bash
curl -X POST "$API_BASE/auth/login" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "jane@example.com",
    "password": "Secret123!"
  }'
```

Typical success response (`200`):

```json
{
  "data": {
    "user": {
      "id": 1,
      "name": "Jane Doe",
      "email": "jane@example.com"
    },
    "token": "2|..."
  }
}
```

### Token Usage (Bearer)

Store the returned Sanctum token and use it in `Authorization` headers:

```bash
export API_TOKEN="2|..."

curl "$API_BASE/workspaces" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Accept: application/json"
```

## Workspace Context (`X-Workspace-ID`)

Many endpoints (notably Projects) require a resolved workspace context. The API can resolve context from multiple sources (route parameter, token ability, header, etc.), but `X-Workspace-ID` is the most explicit option for client integrations.

```bash
export WORKSPACE_ID=1

curl "$API_BASE/projects" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "X-Workspace-ID: $WORKSPACE_ID" \
  -H "Accept: application/json"
```

### Optional: Switch Workspace and Use a Workspace-Scoped Token

`POST /api/v1/my/workspaces/{workspace}/switch` returns a new token scoped to that workspace.

```bash
curl -X POST "$API_BASE/my/workspaces/$WORKSPACE_ID/switch" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Accept: application/json"
```

The response includes a new `token`. You can use that token for subsequent requests (and still provide `X-Workspace-ID` when useful for clarity).

## Common Operations (`curl`)

### 1. Create a Workspace

```bash
curl -X POST "$API_BASE/workspaces" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Acme Studio",
    "slug": "acme-studio",
    "domain": "acme.local.test"
  }'
```

### 2. List Workspaces

List workspaces accessible to the authenticated user:

```bash
curl "$API_BASE/workspaces?per_page=15" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Accept: application/json"
```

List only "my workspaces" membership view:

```bash
curl "$API_BASE/my/workspaces" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Accept: application/json"
```

### 3. Create a Project

```bash
curl -X POST "$API_BASE/projects" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "X-Workspace-ID: $WORKSPACE_ID" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Marketing Website",
    "description": "Landing page redesign",
    "status": "active"
  }'
```

### 4. List / Filter / Paginate Projects

Filter by status, creator, search term, sort order, and pagination:

```bash
curl "$API_BASE/projects?status=active,archived&created_by=me&search=marketing&sort=-created_at,name&per_page=10&page=1" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "X-Workspace-ID: $WORKSPACE_ID" \
  -H "Accept: application/json"
```

Typical project collection shape:

```json
{
  "data": [
    {
      "id": 10,
      "workspace_id": 1,
      "name": "Marketing Website",
      "status": "active"
    }
  ],
  "meta": {
    "total": 1,
    "per_page": 10,
    "current_page": 1,
    "last_page": 1
  }
}
```

### 5. Update a Project

```bash
export PROJECT_ID=10

curl -X PATCH "$API_BASE/projects/$PROJECT_ID" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "X-Workspace-ID: $WORKSPACE_ID" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "status": "archived",
    "description": "Archived after launch"
  }'
```

### 6. Delete a Project (Soft Delete)

```bash
curl -X DELETE "$API_BASE/projects/$PROJECT_ID" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "X-Workspace-ID: $WORKSPACE_ID" \
  -H "Accept: application/json" \
  -i
```

Expected success status: `204 No Content`.

### 7. Invite a Workspace Member

```bash
curl -X POST "$API_BASE/workspaces/$WORKSPACE_ID/invitations" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "teammate@example.com",
    "role": "member"
  }'
```

Successful invitation creation returns `201` and includes an `accept_token` in the response payload (useful for local testing).

### 8. List Workspace Members

```bash
curl "$API_BASE/workspaces/$WORKSPACE_ID/members?role=member&search=alice&per_page=20" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Accept: application/json"
```

## JavaScript / TypeScript Client Example (`SaaSForgeClient`)

```ts
type JsonValue = Record<string, unknown>;

class ApiError extends Error {
  constructor(
    public status: number,
    public body: JsonValue | null,
    message = "API request failed"
  ) {
    super(message);
  }
}

export class SaaSForgeClient {
  private token: string | null = null;
  private workspaceId: number | null = null;

  constructor(private readonly baseUrl: string) {}

  setToken(token: string): void {
    this.token = token;
  }

  setWorkspace(workspaceId: number | null): void {
    this.workspaceId = workspaceId;
  }

  async register(input: {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
  }): Promise<{ user: JsonValue; token: string }> {
    const response = await this.request<{ data: { user: JsonValue; token: string } }>(
      "POST",
      "/api/v1/auth/register",
      input
    );

    this.token = response.data.token;
    return response.data;
  }

  async login(email: string, password: string): Promise<{ user: JsonValue; token: string }> {
    const response = await this.request<{ data: { user: JsonValue; token: string } }>(
      "POST",
      "/api/v1/auth/login",
      { email, password }
    );

    this.token = response.data.token;
    return response.data;
  }

  async createWorkspace(input: {
    name: string;
    slug: string;
    domain?: string | null;
  }): Promise<JsonValue> {
    const response = await this.request<{ data: JsonValue }>("POST", "/api/v1/workspaces", input);
    return response.data;
  }

  async listProjects(params: Record<string, string | number> = {}): Promise<JsonValue> {
    const query = new URLSearchParams(
      Object.entries(params).map(([k, v]) => [k, String(v)])
    ).toString();

    return this.request<JsonValue>("GET", `/api/v1/projects${query ? `?${query}` : ""}`);
  }

  async createProject(input: {
    name: string;
    status: "active" | "archived" | "completed";
    description?: string | null;
  }): Promise<JsonValue> {
    const response = await this.request<{ data: JsonValue }>("POST", "/api/v1/projects", input);
    return response.data;
  }

  private async request<T>(method: string, path: string, body?: JsonValue): Promise<T> {
    const headers: Record<string, string> = {
      Accept: "application/json",
      "Content-Type": "application/json",
    };

    if (this.token) {
      headers.Authorization = `Bearer ${this.token}`;
    }

    if (this.workspaceId !== null) {
      headers["X-Workspace-ID"] = String(this.workspaceId);
    }

    const res = await fetch(`${this.baseUrl}${path}`, {
      method,
      headers,
      body: body ? JSON.stringify(body) : undefined,
    });

    const isJson = (res.headers.get("content-type") || "").includes("application/json");
    const parsed = isJson ? ((await res.json()) as JsonValue) : null;

    if (!res.ok) {
      throw new ApiError(res.status, parsed, (parsed as any)?.message ?? "API request failed");
    }

    return parsed as T;
  }
}
```

## PHP Client Example (`SaaSForgeClient`)

```php
<?php

declare(strict_types=1);

final class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly ?array $body = null,
        string $message = 'API request failed'
    ) {
        parent::__construct($message, $status);
    }
}

final class SaaSForgeClient
{
    private ?string $token = null;
    private ?int $workspaceId = null;

    public function __construct(private readonly string $baseUrl)
    {
    }

    public function setToken(string $token): void
    {
        $this->token = $token;
    }

    public function setWorkspaceId(?int $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
    }

    public function login(string $email, string $password): array
    {
        $response = $this->request('POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);

        $this->token = $response['data']['token'] ?? null;

        return $response['data'] ?? $response;
    }

    public function createProject(array $payload): array
    {
        $response = $this->request('POST', '/api/v1/projects', $payload);

        return $response['data'] ?? $response;
    }

    public function listProjects(array $query = []): array
    {
        $queryString = $query !== [] ? '?'.http_build_query($query) : '';

        return $this->request('GET', '/api/v1/projects'.$queryString);
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init($this->baseUrl.$path);

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        if ($this->token !== null) {
            $headers[] = 'Authorization: Bearer '.$this->token;
        }

        if ($this->workspaceId !== null) {
            $headers[] = 'X-Workspace-ID: '.$this->workspaceId;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('cURL error: '.$curlError);
        }

        if ($status === 204 || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Invalid JSON response');
        }

        if ($status < 200 || $status >= 300) {
            throw new ApiException(
                status: $status,
                body: $decoded,
                message: (string) ($decoded['message'] ?? 'API request failed')
            );
        }

        return $decoded;
    }
}
```

## Error Handling Patterns

Story 9.3 standardized common API error responses for API requests.

### `422 Unprocessable Entity` (validation)

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "name": [
      "The name field is required."
    ]
  }
}
```

### `401 Unauthenticated`

```json
{
  "message": "Unauthenticated."
}
```

### `403 Forbidden`

```json
{
  "message": "This action is unauthorized."
}
```

### `404 Not Found`

```json
{
  "message": "Resource not found."
}
```

### `500 Server Error`

```json
{
  "message": "Server Error"
}
```

In debug mode only, `500` responses may also include an `error` field.

Note: Some domain-specific endpoints also return additional statuses such as `409 Conflict` / `410 Gone` (for invitation lifecycle cases) with endpoint-specific messages.

## Rate Limiting

- General API traffic is typically protected by the API throttle middleware (commonly `60 requests/minute` in many Laravel setups; confirm in your environment if customized).
- Authentication endpoints use stricter route-level limits in this project:
  - `POST /api/v1/auth/register` -> `10/min`
  - `POST /api/v1/auth/login` -> `5/min`
  - `POST /api/v1/auth/forgot-password` -> `5/min`
  - `POST /api/v1/auth/reset-password` -> `5/min`
  - Verification endpoints (`verify`, `verification-notification`) -> `6/min`
- When throttled, expect `429 Too Many Requests`.

For local Newman/Postman reruns, add a small delay to reduce flakiness:

```bash
POSTMAN_DELAY_REQUEST_MS=500 ./docs/postman/run-postman.sh
```

## Swagger UI (Interactive API Docs)

Generate the OpenAPI spec and open the UI:

```bash
php artisan l5-swagger:generate
php artisan serve
```

Then browse:

- `http://127.0.0.1:8000/api/documentation`

See [`docs/OPENAPI_SWAGGER.md`](./OPENAPI_SWAGGER.md) for annotation maintenance and troubleshooting.

