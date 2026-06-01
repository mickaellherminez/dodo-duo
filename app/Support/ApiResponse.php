<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    /**
     * @param  array<string, string>  $headers
     */
    public static function success(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return response()->json([
            'data' => $data,
        ], $status, $headers);
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     * @param  array<string, string>  $headers
     */
    public static function error(
        string $message,
        int $status,
        array $errors = [],
        ?string $debugError = null,
        array $headers = []
    ): JsonResponse {
        $payload = ['message' => $message];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        if ($debugError !== null && $debugError !== '') {
            $payload['error'] = $debugError;
        }

        return response()->json($payload, $status, $headers);
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $links
     * @param  array<string, string>  $headers
     */
    public static function paginated(
        array $data,
        array $meta,
        array $links = [],
        int $status = 200,
        array $headers = []
    ): JsonResponse {
        $payload = [
            'data' => $data,
            'meta' => $meta,
            'links' => $links,
        ];

        return response()->json($payload, $status, $headers);
    }
}
