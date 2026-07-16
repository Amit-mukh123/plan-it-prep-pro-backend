<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class UserSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'refresh_token',
        'device_platform',
        'device_name',
        'ip_address',
        'user_agent',
        'expires_at',
        'is_active',
    ];

    protected $keyType = 'string';
    public $incrementing = false;
}