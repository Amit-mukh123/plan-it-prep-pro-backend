<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserSession extends Model
{
    protected $fillable = [
        'user_id',
        'refresh_token',
        'device_platform',
        'device_name',
        'ip_address',
        'user_agent',
        'expires_at'
    ];

    protected $keyType = 'string';
    public $incrementing = false;
}