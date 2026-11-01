<?php

namespace Database\Factories;

use App\Models\OrganizationMembership;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrganizationMembership>
 */
class OrganizationMembershipFactory extends Factory
{
    /**
     * The model class of the factory.
     *
     * @var class-string<\Illuminate\Database\Eloquent\Model>
     */
    protected $model = OrganizationMembership::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ];
    }

    /**
     * Indicate that the membership should be revoked.
     */
    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrganizationMembership::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);
    }
}
