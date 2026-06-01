<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class MeasureResponseTime
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        $response = $next($request);

        $duration = (microtime(true) - $start) * 1000;

        $response->headers->set('X-Response-Time', number_format($duration, 2).'ms');

        if (app()->isProduction() && $duration > 500) {
            Log::warning('Slow API request', [
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'duration' => $duration,
                'user' => $request->user()?->id,
            ]);
        }

        return $response;
    }
}
