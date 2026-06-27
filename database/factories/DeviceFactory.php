<?php

namespace Database\Factories;

use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'token' => Str::random(64),
            'android_device_id' => Str::random(16),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function withoutHeartbeat(): static
    {
        return $this->state([
            'last_heartbeat_at' => null,
            'last_heartbeat_ip' => null,
        ]);
    }

    public function stale(int $minutesAgo = 60): static
    {
        return $this->state([
            'last_heartbeat_at' => now()->subMinutes($minutesAgo),
        ]);
    }
}
