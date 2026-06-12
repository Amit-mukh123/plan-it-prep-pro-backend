<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForceUpdateAppVersionRequest;
use App\Http\Requests\StoreAppVersionRequest;
use App\Services\VersionService;
use Illuminate\Http\Request;

/**
 * VersionController
 *
 * Ports versionController from versions.controller.ts.
 *
 * Route map (mirrors versions.routes.ts):
 *  POST   /v2/versions/create        → create()              [superadmin]
 *  PATCH  /v2/versions/force-update  → forceUpdate()         [superadmin]
 *  GET    /v2/versions/list          → getAllVersionsList()   [superadmin]
 *  GET    /v2/versions/latest        → getLatestVersion()    [public]
 */
class VersionController extends Controller
{
    public function __construct(
        protected VersionService $versionService
    ) {}

    /**
     * POST /v2/versions/create
     *
     * Insert a new app version. Sets all existing versions for the same platform
     * to is_latest = false and creates the new one with is_latest = true.
     *
     * Response: 201
     * { "success": true, "message": "Version created successfully", "data": { AppVersion } }
     */
    public function create(StoreAppVersionRequest $request)
    {
        $version = $this->versionService->insertNewVersion($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Version created successfully',
            'data'    => $version,
        ], 201);
    }

    /**
     * PATCH /v2/versions/force-update
     *
     * Mark a specific (platform, version) as requiring a mandatory force update.
     * Mirrors versionController.forceUpdate from the Node reference.
     *
     * Response: 200
     * { "success": true, "message": "Version force update flag set successfully", "data": { AppVersion } }
     */
    public function forceUpdate(ForceUpdateAppVersionRequest $request)
    {
        $version = $this->versionService->makeVersionForceUpdate(
            $request->validated()['platform'],
            $request->validated()['version']
        );

        return response()->json([
            'success' => true,
            'message' => 'Version force update flag set successfully',
            'data'    => $version,
        ]);
    }

    /**
     * GET /v2/versions/list?device=android|ios
     *
     * Returns all versions, optionally filtered by platform.
     * Mirrors versionController.getAllVersionsList.
     *
     * Response: 200
     * { "success": true, "message": "Version list fetched successfully", "data": [ AppVersion ] }
     */
    public function getAllVersionsList(Request $request)
    {
        $platform = $request->query('device');

        $versions = $this->versionService->getVersionList($platform ?: null);

        return response()->json([
            'success' => true,
            'message' => 'Version list fetched successfully',
            'data'    => $versions,
        ]);
    }

    /**
     * GET /v2/versions/latest?device=android|ios
     *
     * Public endpoint — returns the latest version for a given platform.
     * Mirrors versionController.getLatestVersion.
     *
     * Response: 200
     * { "success": true, "message": "Latest version fetched successfully", "data": { AppVersion | null } }
     */
    public function getLatestVersion(Request $request)
    {
        $platform = $request->query('device');

        $version = $this->versionService->getLatestVersion($platform ?: null);

        return response()->json([
            'success' => true,
            'message' => 'Latest version fetched successfully',
            'data'    => $version,
        ]);
    }
}
