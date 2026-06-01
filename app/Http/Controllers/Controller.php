<?php

namespace App\Http\Controllers;

use OpenApi\Annotations as OA;

/**
 * @OA\Info(
 *     version="v1",
 *     title="SaaSForge API",
 *     description="OpenAPI documentation for the SaaSForge multi-tenant API."
 * )
 *
 * @OA\Server(
 *     url="/",
 *     description="Current application host"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="sanctum",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="Sanctum",
 *     description="Paste a Sanctum bearer token in the Authorization header."
 * )
 *
 * @OA\Tag(
 *     name="Authentication",
 *     description="Registration, login, logout, and password reset flows"
 * )
 * @OA\Tag(
 *     name="Workspaces",
 *     description="Workspace CRUD and workspace-scoped collaboration resources"
 * )
 * @OA\Tag(
 *     name="Projects",
 *     description="Tenant-scoped project resource management"
 * )
 * @OA\Tag(
 *     name="Team",
 *     description="Workspace members and invitations"
 * )
 */
abstract class Controller
{
    //
}
