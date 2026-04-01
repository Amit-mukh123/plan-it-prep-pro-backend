<?php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    protected $fillable = [
        'phone_number',
        'country_code',
        'email',
        'password',
        'is_verified',
        'is_active'
    ];

    protected $hidden = [
        'password'
    ];

    protected $keyType = 'string';
    public $incrementing = false;
}

