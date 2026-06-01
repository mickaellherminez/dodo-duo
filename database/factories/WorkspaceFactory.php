<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Workspace>
 */
class WorkspaceFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\Illuminate\Database\Eloquent\Model>
     */
    protected $model = Workspace::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'domain' => null,
            'status' => 'active',
            'owner_id' => User::factory(),
            'settings' => [
                'theme' => fake()->randomElement(['light', 'dark']),
                'timezone' => fake()->timezone(),
            ],
            'trial_ends_at' => fake()->optional()->dateTimeBetween('now', '+30 days'),
        ];
    }

    /**
     * Indicate that the workspace is suspended.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'suspended',
        ]);
    }

    /**
     * Indicate that the workspace is archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'archived',
        ]);
    }

    /**
     * Indicate that the workspace has a custom domain.
     */
    public function withDomain(?string $domain = null): static
    {
        return $this->state(fn (array $attributes) => [
            'domain' => $domain ?? fake()->domainName(),
        ]);
    }
}
