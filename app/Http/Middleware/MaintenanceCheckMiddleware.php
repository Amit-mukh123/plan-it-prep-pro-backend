<?php

namespace App\Http\Middleware;

use App\Services\MaintenanceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MaintenanceCheckMiddleware
 *
 * Ports maintenance.middileware.ts from the Node reference.
 *
 * Checks the cached maintenance state via MaintenanceService.
 * If the status is 'active', the request is blocked with a 503 and a
 * structured JSON body matching the Node reference payload exactly.
 *
 * Node reference response:
 *   HTTP 503
 *   {
 *     "code":    "MAINTENANCE_MODE",
 *     "title":   "<maintenance event title>",
 *     "message": "<description OR default string>"
 *   }
 *
 * This middleware should be applied BEFORE auth:sanctum so that
 * unauthenticated clients also receive the correct maintenance response.
 */
class MaintenanceCheckMiddleware
{
    public function __construct(
        protected MaintenanceService $maintenanceService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $maintenance = $this->maintenanceService->getMaintenanceState();

        if (is_array($maintenance) && ($maintenance['status'] ?? null) === 'active') {
            return response()->json([
                'code'    => 'MAINTENANCE_MODE',
                'title'   => $maintenance['title'] ?? 'Maintenance',
                'message' => $maintenance['description']
                             ?? $maintenance['message']
                             ?? 'The system is currently under maintenance. Please try again later.',
            ], Response::HTTP_SERVICE_UNAVAILABLE); // 503
        }

        return $next($request);
    }
}
