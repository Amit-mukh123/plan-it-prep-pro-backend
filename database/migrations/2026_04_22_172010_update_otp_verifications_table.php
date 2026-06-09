<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('otp_verifications')) {
            Schema::create('otp_verifications', function (Blueprint $table) {
                $table->id();
                $table->uuid('user_id')->nullable();
                $table->string('channel', 10)->default('sms');
                $table->integer('attempt_count')->default(0);
                $table->timestamps();
                $table->index('user_id');
            });
        } else {
            Schema::table('otp_verifications', function (Blueprint $table) {
                if (!Schema::hasColumn('otp_verifications', 'channel')) {
                    $table->string('channel', 10)->default('sms');
                }
                if (!Schema::hasColumn('otp_verifications', 'attempt_count')) {
                    $table->integer('attempt_count')->default(0);
                }
                // In SQLite, adding an index via alter table might throw if already exists
                try {
                    $table->index('user_id');
                } catch (\Exception $e) {
                    // Ignore index if it exists
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_verifications');
    }
};