<?php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids;

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

    public function sessions()
    {
        return $this->hasMany(UserSession::class, 'user_id');
    }
}

