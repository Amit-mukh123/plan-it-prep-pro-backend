<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_diet_plans', function (Blueprint $table) {

            // UUID primary key (PostgreSQL)
            if (DB::getDriverName() === 'sqlite') {
                $table->uuid('id')->primary();
            } else {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            }

            // Foreign key to users table
            $table->foreignUuid('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();

            // Store date as string (as per your requirement)
            $table->string('date'); // format: YYYY-MM-DD

            // Enum for meal type
            $table->enum('meal_type', ['breakfast', 'lunch', 'dinner', 'snacks']);

            // Flags
            $table->boolean('is_active')->default(true);
            $table->boolean('is_favourite')->default(false);

            // GPT response (JSON is better than text)
            $table->json('response')->nullable();

            // Timestamps
            $table->timestamps();

            // Index for faster queries
            $table->index(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_diet_plans');
    }
};