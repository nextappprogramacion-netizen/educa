<?php

namespace Database\Factories;

use App\Models\AttendanceTerminal;
use App\Models\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceTerminal>
 */
class AttendanceTerminalFactory extends Factory
{
    protected $model = AttendanceTerminal::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'institution_id' => Institution::factory(),
            'name' => 'Lector QR '.fake()->streetName(),
            'code' => 'TERM-'.fake()->unique()->numerify('###'),
            'mode' => fake()->randomElement(['entry', 'exit']),
            'location' => 'Puerta '.fake()->randomElement(['Principal', 'Secundaria', 'Norte', 'Sur']),
            'device_identifier' => 'DEV-'.fake()->uuid(),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }

    public function entry(): static
    {
        return $this->state(fn (array $attributes) => [
            'mode' => 'entry',
        ]);
    }

    public function exit(): static
    {
        return $this->state(fn (array $attributes) => [
            'mode' => 'exit',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
