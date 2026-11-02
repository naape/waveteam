<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'user_type' => UserType::Applicant,
            'status' => AccountStatus::Active,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function applicant(): static
    {
        return $this->state(['user_type' => UserType::Applicant]);
    }

    public function orgContact(): static
    {
        return $this->state(['user_type' => UserType::OrgContact]);
    }

    public function staff(): static
    {
        return $this->state(['user_type' => UserType::Staff]);
    }

    public function deactivated(): static
    {
        return $this->state(['status' => AccountStatus::Deactivated, 'deactivated_at' => now()]);
    }

    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }
}
