<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Meal extends Model
{
    use SoftDeletes;

    protected $table = 'meals';

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'meal_type',
        'diet_preference',
        'meal_mode',
        'prep_time_min',
        'cook_time_min',
        'servings',
        'image_url',
        'calories',
        'protein_g',
        'carbs_g',
        'fat_g',
        'fiber_g',
        'source',
        'meal_date',
        'prep_steps',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'prep_steps' => 'array',
        'metadata' => 'array',
    ];
}