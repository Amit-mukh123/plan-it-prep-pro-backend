<?php

namespace App\Http\Middleware;

use App\Services\VersionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ForceUpdateMiddleware
 *
 * Ports forceUpdate.middileware.ts from the Node reference exactly.
 *
 * Reads the client's platform and app version from request headers,
 * then checks the app_versions table to decide if the client must update.
 *
 * Header resolution order (same as Node reference):
 *   Platform : x-platform → x-device-platform → platform → device
 *   Version  : x-app-version → app-version → x-version → version
 *
 * Outcomes:
 *   Missing headers  → 400 APP_VERSION_HEADERS_REQUIRED
 *   Invalid platform → 400 INVALID_PLATFORM
 *   Version not in DB OR is_force_update=true → 426 APP_UPDATE_REQUIRED
 *   Otherwise        → pass through
 *
 * This middleware should run AFTER MaintenanceCheckMiddleware and BEFORE auth:sanctum
 * so stale-version clients get a clear 426, not a 401.
 */
class ForceUpdateMiddleware
{
    /** Headers tried in order to find the platform value. */
    private const PLATFORM_HEADERS = ['x-platform', 'x-device-platform', 'platform', 'device'];

    /** Headers tried in order to find the app version value. */
    private const VERSION_HEADERS = ['x-app-version', 'app-version', 'x-version', 'version'];

    /** Platforms recognised by the system (mirrors Prisma Device enum). */
    private const SUPPORTED_PLATFORMS = ['android', 'ios'];

    public function __construct(
        protected VersionService $versionService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $rawPlatform = $this->resolveHeader($request, self::PLATFORM_HEADERS);
        $appVersion  = $this->resolveHeader($request, self::VERSION_HEADERS);

        // ── Guard 1: required headers must be present ──────────────────────
        if (!$rawPlatform || !$appVersion) {
            return response()->json([
                'code'    => 'APP_VERSION_HEADERS_REQUIRED',
                'message' => 'Missing required headers: x-platform and x-app-version',
            ], Response::HTTP_BAD_REQUEST); // 400
        }

        $platform = strtolower($rawPlatform);

        // ── Guard 2: platform must be a known value ─────────────────────────
        if (!in_array($platform, self::SUPPORTED_PLATFORMS, true)) {
            return response()->json([
                'code'    => 'INVALID_PLATFORM',
                'message' => "Invalid platform. Allowed values are 'android' and 'ios'.",
            ], Response::HTTP_BAD_REQUEST); // 400
        }

        // ── Guard 3: version must exist and not require a force update ───────
        $record = $this->versionService->isForceUpdate($platform, $appVersion);

        // null  → version not registered → treated as force-update required
        // model → check is_force_update flag
        if (!$record || $record->is_force_update) {
            return response()->json([
                'code'    => 'APP_UPDATE_REQUIRED',
                'message' => $record?->update_message
                             ?? 'A mandatory app update is required to continue.',
                'result'  => [
                    'platform' => $platform,
                    'version'  => $appVersion,
                ],
            ], 426); // 426 Upgrade Required
        }

        return $next($request);
    }

    /**
     * Iterate over an ordered list of header names and return the first
     * non-empty string value found, or null if none match.
     */
    private function resolveHeader(Request $request, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $request->header($key);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }
        return null;
    }
}
