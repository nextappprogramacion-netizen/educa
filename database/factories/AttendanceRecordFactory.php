<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\Enrollment;
use App\Models\Institution;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceRecord>
 */
class AttendanceRecordFactory extends Factory
{
    protected $model = AttendanceRecord::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'institution_id' => Institution::factory(),
            'enrollment_id' => Enrollment::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'section_id' => Section::factory(),
            'date' => now()->toDateString(),
            'shift' => 'Mañana',
            'session_type' => 'daily',
            'status' => 'present',
            'entry_time' => '07:45:00',
            'observation' => 'Ingreso puntual registrado vía QR',
            'registered_by' => User::factory(),
            'source' => 'qr_terminal',
            'is_justified' => false,
        ];
    }
}
