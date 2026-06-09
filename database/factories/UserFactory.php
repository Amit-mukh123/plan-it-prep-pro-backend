<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'phone_number' => fake()->unique()->numerify('##########'),
            'country_code' => '+91',
            'password' => static::$password ??= Hash::make('password'),
            'is_verified' => true,
            'is_active' => true,
        ];
    }
}
