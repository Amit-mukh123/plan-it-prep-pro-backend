<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                if (DB::getDriverName() === 'sqlite') {
                    $table->uuid('id')->primary();
                } else {
                    $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                }
                $table->string('phone_number')->nullable();
                $table->string('country_code')->default('+91');
                $table->boolean('is_verified')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_login_at')->nullable();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->string('email')->nullable();
                $table->string('password')->nullable();
                $table->boolean('email_verified')->default(false);
                $table->softDeletes();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
