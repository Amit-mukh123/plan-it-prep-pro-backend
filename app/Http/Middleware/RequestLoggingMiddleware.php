<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RequestLoggingMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        $context = [
            'method' => $request->method(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'user_id' => $request->user()?->id,
            'route_name' => $request->route()?->getName(),
        ];

        Log::info('API request started', $context);

        try {
            $response = $next($request);

            $context['status'] = $response->getStatusCode();
            $context['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

            Log::info('API request completed', $context);

            return $response;
        } catch (\Throwable $e) {
            $context['status'] = 500;
            $context['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
            $context['exception'] = $e::class;
            $context['message'] = $e->getMessage();

            Log::error('API request failed', $context);

            throw $e;
        }
    }
}