<?php

use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\AttendanceTerminal;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Institution;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->institution = Institution::create([
        'name' => 'Colegio San Martín Overview Test',
        'code' => 'OVR-SMP',
        'timezone' => 'America/Lima',
        'is_active' => true,
    ]);

    $this->admin = User::factory()->create([
        'institution_id' => $this->institution->id,
        'role' => 'admin',
    ]);

    $this->academicYear = AcademicYear::create([
        'institution_id' => $this->institution->id,
        'name' => 'Año Escolar 2026',
        'year' => 2026,
        'start_date' => '2026-03-01',
        'end_date' => '2026-12-22',
        'is_active' => true,
    ]);

    $this->grade = Grade::create([
        'institution_id' => $this->institution->id,
        'name' => '1° Primaria',
        'level' => 'Primaria',
        'order_index' => 1,
    ]);

    $this->section = Section::create([
        'institution_id' => $this->institution->id,
        'grade_id' => $this->grade->id,
        'name' => 'A',
        'shift' => 'Mañana',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('un usuario autenticado puede acceder al panel general de asistencia conectado a PostgreSQL', function () {
    $response = $this->actingAs($this->admin)
        ->get(route('attendance.overview'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('attendance/Overview')
        ->has('stats')
        ->has('chart')
        ->has('grades')
        ->has('sections')
        ->has('terminals')
        ->has('recent_scans')
        ->has('filters')
    );
});

test('el panel general calcula con precision matematica y en tiempo real las cifras reales desde PostgreSQL', function () {
    Carbon::setTestNow('2026-11-16 11:00:00'); // Lunes lectivo
    $today = '2026-11-16';

    // 5 estudiantes matriculados
    $students = [];
    $enrollments = [];
    for ($i = 1; $i <= 5; $i++) {
        $student = Student::factory()->create([
            'institution_id' => $this->institution->id,
            'first_name' => "Alumno {$i}",
            'last_name' => 'Prueba',
            'is_active' => true,
        ]);
        $enrollment = Enrollment::create([
            'institution_id' => $this->institution->id,
            'student_id' => $student->id,
            'academic_year_id' => $this->academicYear->id,
            'section_id' => $this->section->id,
            'status' => 'active',
        ]);
        $students[$i] = $student;
        $enrollments[$i] = $enrollment;
    }

    // Alumno 1: Ingreso puntual, dentro del colegio (exit_time = null)
    AttendanceRecord::create([
        'institution_id' => $this->institution->id,
        'enrollment_id' => $enrollments[1]->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'date' => $today,
        'shift' => 'Mañana',
        'status' => 'present',
        'entry_time' => '07:45:00',
        'exit_time' => null,
        'registered_by' => $this->admin->id,
    ]);

    // Alumno 2: Ingreso tardío, dentro del colegio (exit_time = null)
    AttendanceRecord::create([
        'institution_id' => $this->institution->id,
        'enrollment_id' => $enrollments[2]->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'date' => $today,
        'shift' => 'Mañana',
        'status' => 'late',
        'entry_time' => '08:20:00',
        'exit_time' => null,
        'registered_by' => $this->admin->id,
    ]);

    // Alumno 3: Ingreso puntual y salida registrada (ya salió, no está dentro)
    AttendanceRecord::create([
        'institution_id' => $this->institution->id,
        'enrollment_id' => $enrollments[3]->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'date' => $today,
        'shift' => 'Mañana',
        'status' => 'present',
        'entry_time' => '07:40:00',
        'exit_time' => '10:30:00',
        'exit_type' => 'early',
        'is_early_exit' => true,
        'early_exit_reason' => 'Permiso médico',
        'registered_by' => $this->admin->id,
    ]);

    // Alumno 4: Falta / Ausencia registrada
    AttendanceRecord::create([
        'institution_id' => $this->institution->id,
        'enrollment_id' => $enrollments[4]->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'date' => $today,
        'shift' => 'Mañana',
        'status' => 'absent',
        'entry_time' => null,
        'exit_time' => null,
        'registered_by' => $this->admin->id,
    ]);

    // Alumno 5: Sin registro de asistencia hoy (pendiente)

    $response = $this->actingAs($this->admin)
        ->get(route('attendance.overview'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('attendance/Overview')
        ->where('stats.inside_school', 2) // Alumno 1 y Alumno 2
        ->where('stats.total_entries', 3) // Alumno 1, 2 y 3
        ->where('stats.total_exits', 1) // Alumno 3
        ->where('stats.punctual', 2) // Alumno 1 y Alumno 3
        ->where('stats.late', 1) // Alumno 2
        ->where('stats.absent', 1) // Alumno 4
        ->where('stats.pending_records', 1) // Alumno 5
        ->where('stats.total_enrolled', 5)
        ->has('recent_scans', 4)
    );
});

test('el panel general muestra las terminales autorizadas y los movimientos en vivo desde PostgreSQL', function () {
    AttendanceTerminal::create([
        'institution_id' => $this->institution->id,
        'name' => 'Puerta Principal A1',
        'code' => 'TERM-IN-01',
        'mode' => 'entry',
        'location' => 'Puerta Principal',
        'is_active' => true,
    ]);

    AttendanceTerminal::create([
        'institution_id' => $this->institution->id,
        'name' => 'Puerta Salida B1',
        'code' => 'TERM-OUT-01',
        'mode' => 'exit',
        'location' => 'Puerta Salida',
        'is_active' => true,
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('attendance.overview'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('attendance/Overview')
        ->has('terminals', 2)
        ->where('terminals.0.code', 'TERM-IN-01')
        ->where('terminals.0.mode_label', 'Entrada')
        ->where('terminals.1.code', 'TERM-OUT-01')
        ->where('terminals.1.mode_label', 'Salida')
    );
});

test('el panel general genera graficos de asistencia diaria con datos reales de PostgreSQL', function () {
    Carbon::setTestNow('2026-11-16 12:00:00');
    $today = '2026-11-16';

    $student = Student::factory()->create(['institution_id' => $this->institution->id]);
    $enrollment = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'status' => 'active',
    ]);

    AttendanceRecord::create([
        'institution_id' => $this->institution->id,
        'enrollment_id' => $enrollment->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'date' => $today,
        'shift' => 'Mañana',
        'status' => 'present',
        'entry_time' => '07:45:00',
        'registered_by' => $this->admin->id,
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('attendance.overview', ['period' => 'daily']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('attendance/Overview')
        ->where('chart.period', 'daily')
        ->has('chart.series', 7) // 7 días
        ->where('chart.summary.total_punctual', 1)
        ->where('chart.summary.overall_rate', 100)
    );
});

test('el panel general genera graficos de asistencia semanal con datos reales de PostgreSQL', function () {
    Carbon::setTestNow('2026-11-16 12:00:00'); // Semana actual

    $student = Student::factory()->create(['institution_id' => $this->institution->id]);
    $enrollment = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'status' => 'active',
    ]);

    // Registro esta semana
    AttendanceRecord::create([
        'institution_id' => $this->institution->id,
        'enrollment_id' => $enrollment->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'date' => '2026-11-16',
        'shift' => 'Mañana',
        'status' => 'late',
        'entry_time' => '08:20:00',
        'registered_by' => $this->admin->id,
    ]);

    // Registro semana anterior
    AttendanceRecord::create([
        'institution_id' => $this->institution->id,
        'enrollment_id' => $enrollment->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'date' => '2026-11-09',
        'shift' => 'Mañana',
        'status' => 'present',
        'entry_time' => '07:50:00',
        'registered_by' => $this->admin->id,
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('attendance.overview', ['period' => 'weekly']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('attendance/Overview')
        ->where('chart.period', 'weekly')
        ->has('chart.series', 6) // 6 semanas
        ->where('chart.summary.total_punctual', 1)
        ->where('chart.summary.total_late', 1)
    );
});

test('el panel general genera graficos de asistencia mensual con datos reales de PostgreSQL', function () {
    Carbon::setTestNow('2026-11-16 12:00:00'); // Mes de Noviembre 2026

    $student = Student::factory()->create(['institution_id' => $this->institution->id]);
    $enrollment = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'status' => 'active',
    ]);

    AttendanceRecord::create([
        'institution_id' => $this->institution->id,
        'enrollment_id' => $enrollment->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'date' => '2026-11-16',
        'shift' => 'Mañana',
        'status' => 'present',
        'entry_time' => '07:55:00',
        'registered_by' => $this->admin->id,
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('attendance.overview', ['period' => 'monthly']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('attendance/Overview')
        ->where('chart.period', 'monthly')
        ->has('chart.series', 6) // 6 meses
        ->where('chart.summary.total_punctual', 1)
    );
});

test('el panel general permite filtrar los graficos y metricas por grado y seccion', function () {
    Carbon::setTestNow('2026-11-16 12:00:00');

    // Grado 2 y Sección B
    $grade2 = Grade::create([
        'institution_id' => $this->institution->id,
        'name' => '2° Primaria',
        'level' => 'Primaria',
        'order_index' => 2,
    ]);

    $section2B = Section::create([
        'institution_id' => $this->institution->id,
        'grade_id' => $grade2->id,
        'name' => 'B',
        'shift' => 'Tarde',
    ]);

    $student1A = Student::factory()->create(['institution_id' => $this->institution->id]);
    $student2B = Student::factory()->create(['institution_id' => $this->institution->id]);

    $enr1A = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student1A->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id, // 1° Primaria - A
        'status' => 'active',
    ]);

    $enr2B = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student2B->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $section2B->id, // 2° Primaria - B
        'status' => 'active',
    ]);

    // Asistencia para 1° Primaria (Puntual)
    AttendanceRecord::create([
        'institution_id' => $this->institution->id,
        'enrollment_id' => $enr1A->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'date' => '2026-11-16',
        'shift' => 'Mañana',
        'status' => 'present',
        'entry_time' => '07:50:00',
        'registered_by' => $this->admin->id,
    ]);

    // Asistencia para 2° Primaria (Tardanza)
    AttendanceRecord::create([
        'institution_id' => $this->institution->id,
        'enrollment_id' => $enr2B->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $section2B->id,
        'date' => '2026-11-16',
        'shift' => 'Tarde',
        'status' => 'late',
        'entry_time' => '13:45:00',
        'registered_by' => $this->admin->id,
    ]);

    // Filtrar exclusivamente por Grado 2 y Sección 2B
    $response = $this->actingAs($this->admin)
        ->get(route('attendance.overview', [
            'grade_id' => $grade2->id,
            'section_id' => $section2B->id,
        ]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('attendance/Overview')
        ->where('filters.grade_id', $grade2->id)
        ->where('filters.section_id', $section2B->id)
        ->where('stats.late', 1)
        ->where('stats.punctual', 0)
        ->where('chart.summary.total_late', 1)
        ->where('chart.summary.total_punctual', 0)
    );
});
