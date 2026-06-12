<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Mirrors the Prisma `appVersion` model (@@map("app_versions")).
     *
     * Columns:
     *  - platform        : 'android' | 'ios'
     *  - version         : semantic version string e.g. "1.2.3"
     *  - is_latest       : only ONE record per platform should be true at a time
     *  - is_stable       : false for beta / pre-release builds
     *  - is_force_update : clients running this version MUST update before continuing
     *  - release_notes   : human-readable changelog
     *  - update_message  : message shown in the force-update prompt
     */
    public function up(): void
    {
        Schema::create('app_versions', function (Blueprint $table) {
            if (DB::getDriverName() === 'sqlite') {
                $table->uuid('id')->primary();
            } else {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            }

            $table->string('platform');          // 'android' | 'ios'
            $table->string('version');
            $table->boolean('is_latest')->default(true);
            $table->boolean('is_stable')->default(true);
            $table->boolean('is_force_update')->default(false);
            $table->text('release_notes')->nullable();
            $table->text('update_message')->nullable();
            $table->timestamps();

            // Matches: @@unique([version, platform], name: "uq_app_version_platform")
            $table->unique(['version', 'platform'], 'uq_app_version_platform');

            // Matches: @@index([version, isLatest, isForceUpdate, isStable])
            $table->index(['version', 'is_latest', 'is_force_update', 'is_stable'], 'idx_app_versions_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_versions');
    }
};
