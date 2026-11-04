<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Configura la fábrica: asigna organization_id directamente (bypass Fillable)
     * porque la columna es NOT NULL en el esquema. Este valor es solo de
     * compatibilidad; no se usa para autorización ni resolución de tenant.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (User $user) {
            if (blank($user->organization_id)) {
                $user->organization_id = Organization::factory()->create()->id;
            }
        });
    }

    /**
     * Asigna la organización de compatibilidad al usuario.
     *
     * No representa una membresía ni define el tenant activo.
     */
    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(function (User $user) use ($organization) {
            $user->organization_id = $organization->id;
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
