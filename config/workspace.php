<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Domain
    |--------------------------------------------------------------------------
    |
    | The base domain for your application. This is used to extract workspace
    | slugs from subdomains. For example, if app_domain is 'saasforge.app',
    | then 'acme.saasforge.app' would extract 'acme' as the workspace slug.
    |
    */

    'app_domain' => env('WORKSPACE_APP_DOMAIN', 'localhost'),

    /*
    |--------------------------------------------------------------------------
    | Workspace Resolution Strategy Priority
    |--------------------------------------------------------------------------
    |
    | Defines the order in which the SetCurrentWorkspace middleware attempts
    | to resolve the workspace context. Strategies are tried in order until
    | one succeeds.
    |
    | Available strategies:
    | - 'subdomain': Extract workspace slug from subdomain (e.g., acme.saasforge.app)
    | - 'domain': Match custom domain to workspace (e.g., app.acme.com)
    | - 'header': Read X-Workspace-ID header (API clients)
    | - 'route': Extract {workspace} route parameter
    | - 'token': Extract workspace_id from JWT token (future: OAuth/API tokens)
    |
    */

    'resolution_strategy_priority' => [
        'subdomain',
        'domain',
        'header',
        'route',
        'token', // Extract workspace from Sanctum token abilities (workspace:{id})
    ],

    /*
    |--------------------------------------------------------------------------
    | Context Assertion Whitelist
    |--------------------------------------------------------------------------
    |
    | Routes that do not require workspace context to be set. These routes
    | bypass the TenantContextAssertion middleware. Use wildcards (*) for
    | pattern matching.
    |
    | Examples:
    | - '/login' - Exact match
    | - '/api/public/*' - All routes under /api/public/
    | - 'health' - Health check endpoints
    |
    */

    'context_assertion_whitelist' => [
        '/',
        '/login',
        '/register',
        '/forgot-password',
        '/reset-password',
        '/email/verify',
        '/public/*',
        '/docs',
        '/docs/*',
        '/health',
        '/api/health',
    ],

    /*
    |--------------------------------------------------------------------------
    | Workspace Access Verification
    |--------------------------------------------------------------------------
    |
    | When enabled, the middleware verifies that the authenticated user has
    | access to the resolved workspace. If disabled, any valid workspace
    | will be set as the current context (useful for public workspaces).
    |
    */

    'verify_user_access' => env('WORKSPACE_VERIFY_ACCESS', true),

    /*
    |--------------------------------------------------------------------------
    | Workspace Not Found Behavior
    |--------------------------------------------------------------------------
    |
    | Determines what happens when a workspace cannot be resolved:
    | - 'abort_404': Return 404 Not Found (default, security best practice)
    | - 'abort_403': Return 403 Forbidden
    | - 'null': Continue with null workspace context (use with caution)
    |
    */

    'not_found_behavior' => 'abort_404',

];
