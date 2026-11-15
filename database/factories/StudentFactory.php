<?php

namespace Database\Factories;

use App\Models\Institution;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    protected $model = Student::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'institution_id' => Institution::factory(),
            'code' => 'EST-'.fake()->unique()->numerify('#####'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName().' '.fake()->lastName(),
            'document_type' => 'DNI',
            'document_number' => fake()->unique()->numerify('########'),
            'gender' => fake()->randomElement(['M', 'F']),
            'birth_date' => fake()->date('Y-m-d', '-10 years'),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('9########'),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
