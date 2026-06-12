<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AppVersion extends Model
{
    use HasUuids;

    /**
     * The table associated with the model.
     * Matches the Prisma @@map("app_versions").
     */
    protected $table = 'app_versions';

    protected $fillable = [
        'platform',
        'version',
        'is_latest',
        'is_stable',
        'is_force_update',
        'release_notes',
        'update_message',
    ];

    protected $casts = [
        'is_latest'       => 'boolean',
        'is_stable'       => 'boolean',
        'is_force_update' => 'boolean',
    ];
}
