<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_config', function (Blueprint $table) {

            // UUID auto-generated from PostgreSQL
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // Explicit foreign key
            $table->uuid('user_id');

            $table->foreign('user_id')
                  ->references('id')   // explicitly referencing users.id
                  ->on('users')
                  ->cascadeOnDelete();

            $table->json('data')->nullable();
            $table->timestamps();

            $table->unique('user_id'); // optional
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_config');
    }
};