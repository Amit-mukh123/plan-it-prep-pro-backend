<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('maintenances', function (Blueprint $table) {
            if (DB::getDriverName() === 'sqlite') {
                $table->uuid('id')->primary();
            } else {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            }
            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->string('priority')->default('low');
            $table->string('app_type')->nullable(); 
            $table->string('scope')->nullable();
            $table->json('affected_services')->nullable();
            $table->boolean('allow_whitelist')->default(false);
            $table->uuid('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->integer('notify_before_minutes')->default(30);
            $table->integer('grace_period_minutes')->default(0);
            $table->boolean('is_emergency')->default(false);
            $table->boolean('notify')->default(false);
            $table->boolean('is_deleted')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('maintenances');
    }
};
