<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('maintenances', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('start_time');
            $table->timestamp('end_time');
            
            // Enums
            $table->enum('priority', ['low', 'high', 'critical']);
            $table->enum('app_type', ['planeventz', 'planeventz_vendor'])->nullable();
            $table->enum('scope', ['global', 'api_only', 'feature'])->default('global');
            
            $table->json('affected_services')->nullable();
            $table->boolean('allow_whitelist')->default(false);
            $table->uuid('created_by'); // Assuming this links to a User UUID
            
            $table->integer('notify_before_minutes')->default(30);
            $table->integer('grace_period_minutes')->default(5);
            $table->boolean('is_emergency')->default(false);
            $table->boolean('notify')->default(true);
            
            $table->timestamps(); // Creates created_at and updated_at
            $table->softDeletes(); // Creates a 'deleted_at' column (replaces isDeleted)
            
            // Indexes
            $table->index(['start_time', 'end_time', 'notify', 'deleted_at']);
        });
    }
};