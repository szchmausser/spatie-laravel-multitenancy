<?php

namespace Database\Factories;

use App\Models\DeviceInviteCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceInviteCode>
 */
class DeviceInviteCodeFactory extends Factory
{
    protected $model = DeviceInviteCode::class;

    public function definition(): array
    {
        return [
            'code' => DeviceInviteCode::generate(),
            'used_at' => null,
            'expires_at' => null,
        ];
    }

    /**
     * Mark the code as already consumed.
     */
    public function used(): static
    {
        return $this->state(['used_at' => now()]);
    }

    /**
     * Set an expiration date in the past (expired code).
     */
    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }

    /**
     * Set an expiration date in the future (still valid).
     */
    public function expiresIn(int $days = 7): static
    {
        return $this->state(['expires_at' => now()->addDays($days)]);
    }
}
