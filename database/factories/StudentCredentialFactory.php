<?php

namespace Database\Factories;

use App\Models\Institution;
use App\Models\Student;
use App\Models\StudentCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentCredential>
 */
class StudentCredentialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'institution_id' => Institution::factory(),
            'student_id' => Student::factory(),
            'qr_code' => StudentCredential::generateSecureQrCode(),
            'status' => 'active',
            'issued_at' => now(),
        ];
    }

    public function blocked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'blocked',
        ]);
    }
}
