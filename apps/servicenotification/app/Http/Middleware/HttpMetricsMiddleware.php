<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Observability\MetricsRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class HttpMetricsMiddleware
{
    public function __construct(
        private MetricsRegistry $metrics,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);
        $response = $next($request);
        $route = $request->route()?->getName() ?: $request->path();

        $this->metrics->recordHttpRequest(
            method: $request->method(),
            route: $route,
            statusCode: $response->getStatusCode(),
            durationSeconds: microtime(true) - $startedAt,
        );

        return $response;
    }
}
