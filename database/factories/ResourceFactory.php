<?php

namespace Database\Factories;

use App\Models\Resource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<resource>
 */
class ResourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The default factory produces a non-premium, active PDF resource
     * with a small fake body. Use the `premium()` state for paid
     * resources (which set is_premium and a non-zero price) and
     * `inactive()` to retire a file without deleting the row.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'slug' => fake()->unique()->slug(3),
            'description' => fake()->sentence(),
            'file_path' => 'resources/'.fake()->uuid().'.pdf',
            'file_size_bytes' => fake()->numberBetween(50_000, 5_000_000),
            'mime_type' => 'application/pdf',
            'is_premium' => false,
            'price_cents' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Mark the resource as premium (paid) with a random price.
     */
    public function premium(?int $priceCents = null): static
    {
        return $this->state(fn (): array => [
            'is_premium' => true,
            'price_cents' => $priceCents ?? fake()->numberBetween(500, 10_000),
        ]);
    }

    /**
     * Mark the resource as inactive (retired, no longer visible
     * in the catalog, but kept for entitlement history).
     */
    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * Pin the file_path and mime_type to specific values. Useful in
     * download tests that need to put a real file on the fake
     * storage disk and assert the stream is delivered.
     */
    public function withFile(string $path, string $mimeType, int $sizeBytes): static
    {
        return $this->state([
            'file_path' => $path,
            'mime_type' => $mimeType,
            'file_size_bytes' => $sizeBytes,
        ]);
    }
}
