<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginAttempt extends Model
{
    protected $fillable = [
        'user_id',
        'phone_number',
        'ip_address',
        'user_agent',
        'is_success',
        'failure_reason'
    ];

    protected $keyType = 'string';
    public $incrementing = false;
}