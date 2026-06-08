<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Maintenance extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'title', 'description', 'start_time', 'end_time', 'priority', 
        'app_type', 'scope', 'affected_services', 'allow_whitelist', 
        'created_by', 'notify_before_minutes', 'grace_period_minutes', 
        'is_emergency', 'notify'
    ];

    // Equivalent to Prisma field typing/casting
    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'affected_services' => 'array',
        'allow_whitelist' => 'boolean',
        'is_emergency' => 'boolean',
        'notify' => 'boolean',
    ];
}