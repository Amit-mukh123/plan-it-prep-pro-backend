<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserConfig extends Model
{
    protected $table = 'user_config';

    protected $fillable = [
        'user_id',
        'data'
    ];

    protected $casts = [
        'data' => 'array',
    ];

    public $incrementing = false;
    protected $keyType = 'string';

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
