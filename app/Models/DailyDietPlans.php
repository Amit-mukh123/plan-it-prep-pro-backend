<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DailyDietPlans extends Model
{
    protected $table = 'daily_diet_plans';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'date',
        'meal_type',
        'is_active',
        'is_favourite',
        'response',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_favourite' => 'boolean',
        'response' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }
}