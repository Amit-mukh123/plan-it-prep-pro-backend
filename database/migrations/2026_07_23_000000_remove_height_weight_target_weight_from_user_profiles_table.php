<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('user_profiles')) {
            Schema::table('user_profiles', function (Blueprint $table) {
                $columnsToDrop = [];
                if (Schema::hasColumn('user_profiles', 'height_cm')) {
                    $columnsToDrop[] = 'height_cm';
                }
                if (Schema::hasColumn('user_profiles', 'weight_kg')) {
                    $columnsToDrop[] = 'weight_kg';
                }
                if (Schema::hasColumn('user_profiles', 'target_weight_kg')) {
                    $columnsToDrop[] = 'target_weight_kg';
                }
                if (!empty($columnsToDrop)) {
                    $table->dropColumn($columnsToDrop);
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('user_profiles')) {
            Schema::table('user_profiles', function (Blueprint $table) {
                if (!Schema::hasColumn('user_profiles', 'height_cm')) {
                    $table->float('height_cm')->nullable();
                }
                if (!Schema::hasColumn('user_profiles', 'weight_kg')) {
                    $table->float('weight_kg')->nullable();
                }
                if (!Schema::hasColumn('user_profiles', 'target_weight_kg')) {
                    $table->float('target_weight_kg')->nullable();
                }
            });
        }
    }
};
