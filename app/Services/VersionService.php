<?php

namespace App\Services;

use App\Models\AppVersion;
use Illuminate\Support\Facades\DB;

/**
 * VersionService
 *
 * Ports the business logic from versions.service.ts.
 * All DB mutations that touch is_latest use a transaction to prevent
 * race conditions (matching the Prisma $transaction in the Node reference).
 */
class VersionService
{
    /**
     * Insert a new app version.
     *
     * Business rules (mirrors insertNewVersion in versions.service.ts):
     *  1. Throw a 400-level error if the (version, platform) pair already exists.
     *  2. Set all existing records for the same platform to is_latest = false.
     *  3. Insert the new record with is_latest = true by default.
     *
     * @param  array $data  Validated data from StoreAppVersionRequest
     * @return AppVersion
     *
     * @throws \Illuminate\Validation\ValidationException  (via abort 400)
     */
    public function insertNewVersion(array $data): AppVersion
    {
        return DB::transaction(function () use ($data) {
            // Step 1 — uniqueness check (DB constraint also enforces this, but we want a clean 400)
            $existing = AppVersion::where('platform', $data['platform'])
                ->where('version', $data['version'])
                ->first();

            if ($existing) {
                abort(400, 'Version already exists for this platform.');
            }

            // Step 2 — demote any current "latest" for this platform
            AppVersion::where('platform', $data['platform'])
                ->where('is_latest', true)
                ->update(['is_latest' => false]);

            // Step 3 — create the new record (is_latest defaults to true via migration)
            return AppVersion::create([
                'platform'       => $data['platform'],
                'version'        => $data['version'],
                'is_latest'      => true,
                'is_stable'      => $data['is_stable'] ?? true,
                'release_notes'  => $data['release_notes'] ?? null,
                'update_message' => $data['update_message'] ?? null,
                'is_force_update'=> false,
            ]);
        });
    }

    /**
     * Set the is_force_update flag to true for a given (platform, version) pair.
     *
     * Mirrors makeVersionForceUpdate in versions.service.ts.
     * The Node reference is one-directional (only ever sets true).
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException  (404 if not found)
     */
    public function makeVersionForceUpdate(string $platform, string $version): AppVersion
    {
        $record = AppVersion::where('platform', $platform)
            ->where('version', $version)
            ->first();

        if (!$record) {
            abort(404, 'Version not found.');
        }

        $record->update(['is_force_update' => true]);

        return $record->fresh();
    }

    /**
     * Get the latest version for a given platform (or any platform if null).
     *
     * Mirrors getLatestVersion in versions.service.ts / versions.repository.ts.
     * Used by the public GET /v2/versions/latest endpoint.
     *
     * @param  string|null $platform  'android' | 'ios' | null
     * @return AppVersion|null
     */
    public function getLatestVersion(?string $platform): ?AppVersion
    {
        $query = AppVersion::where('is_latest', true);

        if ($platform) {
            $query->where('platform', $platform);
        }

        return $query->first();
    }

    /**
     * Get the full version list, optionally filtered by platform.
     *
     * Mirrors getVersionList in versions.service.ts / versions.repository.ts.
     * Used by the superadmin GET /v2/versions/list endpoint.
     *
     * @param  string|null $platform  'android' | 'ios' | null
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getVersionList(?string $platform)
    {
        $query = AppVersion::orderBy('created_at', 'desc');

        if ($platform) {
            $query->where('platform', $platform);
        }

        return $query->get();
    }

    /**
     * Look up a (platform, version) record and return it.
     * Used exclusively by ForceUpdateMiddleware.
     *
     * Returns null  → version is not registered   → treat as force-update required.
     * Returns model → check is_force_update field.
     *
     * @param  string $platform
     * @param  string $version
     * @return AppVersion|null
     */
    public function isForceUpdate(string $platform, string $version): ?AppVersion
    {
        return AppVersion::where('platform', $platform)
            ->where('version', $version)
            ->first();
    }
}
