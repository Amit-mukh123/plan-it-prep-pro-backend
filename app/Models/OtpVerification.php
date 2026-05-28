<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class OtpVerification extends Model
{
    protected $table = 'otp_verifications';

    protected $fillable = [
    'user_id',
    'otp_code',
    'channel',
    'expires_at',
    'is_used',
    'attempt_count'
    ];

    protected $keyType = 'string';
    public $incrementing = false;
}