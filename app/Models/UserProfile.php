<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    protected $table = 'user_profiles';

    // UUID primary key
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'full_name',
        'age',
        'gender',
        'height_cm',
        'weight_kg',
        'target_weight_kg',
        'diet_preference',
        'avatar_url',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'height_cm' => 'float',
        'weight_kg' => 'float',
        'target_weight_kg' => 'float',
        'age' => 'integer'
    ];

    /**
     * Relationship with User
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}