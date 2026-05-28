<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('otp_verifications', function (Blueprint $table) {

            // Add new columns (PostgreSQL safe)
            $table->string('channel', 10)->default('sms');
            $table->integer('attempt_count')->default(0);

            // Add index
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('otp_verifications', function (Blueprint $table) {
        $table->string('channel', 10)->default('sms');
        $table->integer('attempt_count')->default(0);

        $table->index('user_id');
    });

    }
};