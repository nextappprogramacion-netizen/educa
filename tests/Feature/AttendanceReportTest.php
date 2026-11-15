<?php

use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Institution;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->institution = Institution::factory()->create([
        'name' => 'Colegio Test Educa',
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
        'end_date' => '2026-12-20',
        'is_active' => true,
    ]);

    $this->grade = Grade::create([
        'institution_id' => $this->institution->id,
        'name' => '1° Grado de Secundaria',
        'level' => 'Secundaria',
        'stage' => 'VI',
        'order' => 1,
        'is_active' => true,
    ]);

    $this->section = Section::create([
        'institution_id' => $this->institution->id,
        'grade_id' => $this->grade->id,
        'name' => 'Sección A',
        'shift' => 'Mañana',
        'capacity' => 30,
    ]);
});

test('el modulo de reportes muestra metricas reales y registros de asistencia desde PostgreSQL', function () {
    Carbon::setTestNow('2026-11-16 12:00:00');

    $student1 = Student::factory()->create(['institution_id' => $this->institution->id, 'first_name' => 'Ana', 'last_name' => 'Gomez']);
    $student2 = Student::factory()->create(['institution_id' => $this->institution->id, 'first_name' => 'Carlos', 'last_name' => 'Perez']);
    $student3 = Student::factory()->create(['institution_id' => $this->institution->id, 'first_name' => 'Elena', 'last_name' => 'Torres']);

    $enrollment1 = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student1->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'status' => 'active',
    ]);

    $enrollment2 = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student2->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'status' => 'active',
    ]);

    $enrollment3 = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student3->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'status' => 'active',
    ]);

    // 1. Puntual con salida regular
    AttendanceRecord::create([
        'institution_id' => $this->institution->id,
        'enrollment_id' => $enrollment1->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'date' => '2026-11-16',
        'shift' => 'Mañana',
        'status' => 'present',
        'entry_time' => '07:45:00',
        'exit_time' => '13:00:00',
        'is_early_exit' => false,
        'registered_by' => $this->admin->id,
    ]);

    // 2. Tardanza con salida anticipada
    AttendanceRecord::create([
        'institution_id' => $this->institution->id,
        'enrollment_id' => $enrollment2->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'date' => '2026-11-16',
        'shift' => 'Mañana',
        'status' => 'late',
        'entry_time' => '08:20:00',
        'exit_time' => '11:30:00',
        'is_early_exit' => true,
        'early_exit_reason' => 'Cita Médica',
        'registered_by' => $this->admin->id,
    ]);

    // 3. Inasistencia
    AttendanceRecord::create([
        'institution_id' => $this->institution->id,
        'enrollment_id' => $enrollment3->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'date' => '2026-11-16',
        'shift' => 'Mañana',
        'status' => 'absent',
        'registered_by' => $this->admin->id,
    ]);

    $response = $this->actingAs($this->admin)->get(route('attendance.reports', [
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-30',
    ]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('attendance/Reports')
        ->where('summary.total_evaluated', 3)
        ->where('summary.total_punctual', 1)
        ->where('summary.total_late', 1)
        ->where('summary.total_absent', 1)
        ->where('summary.total_exits', 2)
        ->where('summary.total_early_exits', 1)
        ->has('records', 3)
        ->has('grades')
        ->has('sections')
        ->has('students')
    );
});

test('los reportes permiten filtrar por rango de fechas, grado, seccion, estudiante y tipo', function () {
    $grade2 = Grade::create([
        'institution_id' => $this->institution->id,
        'name' => '2° Grado de Secundaria',
        'level' => 'Secundaria',
        'stage' => 'VI',
        'order' => 2,
        'is_active' => true,
    ]);

    $section2 = Section::create([
        'institution_id' => $this->institution->id,
        'grade_id' => $grade2->id,
        'name' => 'Sección B',
        'shift' => 'Tarde',
        'capacity' => 25,
    ]);

    $studentA = Student::factory()->create(['institution_id' => $this->institution->id, 'first_name' => 'Maria', 'last_name' => 'Lopez']);
    $studentB = Student::factory()->create(['institution_id' => $this->institution->id, 'first_name' => 'Juan', 'last_name' => 'Rios']);

    $enrollmentA = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $studentA->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'status' => 'active',
    ]);

    $enrollmentB = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $studentB->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $section2->id,
        'status' => 'active',
    ]);

    AttendanceRecord::create([
        'institution_id' => $this->institution->id,
        'enrollment_id' => $enrollmentA->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'date' => '2026-11-10',
        'shift' => 'Mañana',
        'status' => 'present',
        'entry_time' => '07:50:00',
        'registered_by' => $this->admin->id,
    ]);

    AttendanceRecord::create([
        'institution_id' => $this->institution->id,
        'enrollment_id' => $enrollmentB->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $section2->id,
        'date' => '2026-11-15',
        'shift' => 'Tarde',
        'status' => 'late',
        'entry_time' => '13:25:00',
        'registered_by' => $this->admin->id,
    ]);

    // Filtrar por grado 1
    $responseGrade = $this->actingAs($this->admin)->get(route('attendance.reports', [
        'grade_id' => $this->grade->id,
    ]));
    $responseGrade->assertInertia(fn ($page) => $page
        ->has('records', 1)
        ->where('records.0.student_id', $studentA->id)
    );

    // Filtrar por estudiante B
    $responseStudent = $this->actingAs($this->admin)->get(route('attendance.reports', [
        'student_id' => $studentB->id,
    ]));
    $responseStudent->assertInertia(fn ($page) => $page
        ->has('records', 1)
        ->where('records.0.student_id', $studentB->id)
    );

    // Filtrar por tipo 'late'
    $responseType = $this->actingAs($this->admin)->get(route('attendance.reports', [
        'type' => 'late',
    ]));
    $responseType->assertInertia(fn ($page) => $page
        ->has('records', 1)
        ->where('records.0.status', 'late')
    );
});

test('los reportes permiten exportar a PDF con formato oficial y cabeceras correctas', function () {
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
        'entry_time' => '07:45:00',
        'exit_time' => '13:00:00',
        'registered_by' => $this->admin->id,
    ]);

    $response = $this->actingAs($this->admin)->get(route('attendance.reports.export.pdf', [
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-30',
        'grade_id' => $this->grade->id,
    ]));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
    $this->assertStringContainsString('reporte_asistencia_2026-11-01_2026-11-30.pdf', (string) $response->headers->get('Content-Disposition'));
    $this->assertStringStartsWith('%PDF-1.4', (string) $response->getContent());
    $this->assertStringContainsString('%%EOF', (string) $response->getContent());
});

test('los reportes permiten exportar a Excel con formato XLSX y cabeceras correctas', function () {
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
        'status' => 'late',
        'entry_time' => '08:15:00',
        'is_early_exit' => true,
        'early_exit_reason' => 'Permiso familiar',
        'registered_by' => $this->admin->id,
    ]);

    $response = $this->actingAs($this->admin)->get(route('attendance.reports.export.excel', [
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-30',
    ]));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $this->assertStringContainsString('reporte_asistencia_2026-11-01_2026-11-30.xlsx', (string) $response->headers->get('Content-Disposition'));
    $content = (string) $response->getContent();
    // Los archivos ZIP / XLSX siempre inician con los bytes PK\x03\x04
    $this->assertStringStartsWith("PK\x03\x04", $content);
});
