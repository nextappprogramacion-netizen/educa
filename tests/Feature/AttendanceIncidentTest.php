<?php

use App\Models\AcademicYear;
use App\Models\AttendanceIncident;
use App\Models\AttendanceRecord;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Institution;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentCredential;
use App\Models\User;

beforeEach(function () {
    $this->institution = Institution::create([
        'name' => 'Colegio San Martín Incidents Test',
        'code' => 'INC-SMP',
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

test('un usuario autenticado puede acceder a la bandeja de incidencias conectada a PostgreSQL', function () {
    $response = $this->actingAs($this->admin)
        ->get(route('attendance.incidents'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('attendance/Incidents')
        ->has('incidents')
        ->has('students')
        ->has('stats')
        ->has('filters')
    );
});

test('permite registrar una tardanza con motivo, responsable y fecha en PostgreSQL conservando el registro original', function () {
    $student = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Camila',
        'last_name' => 'Bermúdez',
        'is_active' => true,
    ]);

    $enrollment = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'status' => 'active',
    ]);

    // Registro original de escaneo con tardanza
    $originalRecord = AttendanceRecord::create([
        'institution_id' => $this->institution->id,
        'enrollment_id' => $enrollment->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'date' => '2026-11-16',
        'shift' => 'Mañana',
        'status' => 'late',
        'entry_time' => '08:15:30',
        'registered_by' => $this->admin->id,
        'is_justified' => false,
    ]);

    // Registrar incidencia de tardanza justificada
    $response = $this->actingAs($this->admin)
        ->from(route('attendance.incidents'))
        ->post(route('attendance.incidents.store'), [
            'student_id' => $student->id,
            'type' => 'tardiness',
            'date' => '2026-11-16',
            'time' => '08:15',
            'reason' => 'Demora fortuita por corte de vía en transporte escolar',
            'responsible_name' => 'Rosa Flores',
            'responsible_relationship' => 'Madre',
            'is_justified' => true,
            'justification_reason' => 'Sustento emitido por la empresa de transporte escolar',
        ]);

    $response->assertRedirect(route('attendance.incidents'));
    $response->assertSessionHas('success');

    // Verificar en PostgreSQL la incidencia guardada con motivo, responsable y fecha
    $incident = AttendanceIncident::where('student_id', $student->id)->first();
    expect($incident)->not->toBeNull();
    expect($incident->type)->toBe('tardiness');
    expect($incident->date->format('Y-m-d'))->toBe('2026-11-16');
    expect($incident->reason)->toBe('Demora fortuita por corte de vía en transporte escolar');
    expect($incident->responsible_name)->toBe('Rosa Flores');
    expect($incident->responsible_relationship)->toBe('Madre');
    expect($incident->is_justified)->toBeTrue();
    expect($incident->registered_by)->toBe($this->admin->id);

    // Conservar el registro original de asistencia (hora de ingreso y status original se mantienen intactos)
    $originalRecord->refresh();
    expect($originalRecord->entry_time)->toBe('08:15:30');
    expect($originalRecord->status)->toBe('late');
    expect($originalRecord->is_justified)->toBeTrue();
});

test('permite registrar un olvido de carnet con motivo, responsable y fecha sin alterar credenciales originales', function () {
    $student = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Valeria',
        'last_name' => 'Díaz',
        'is_active' => true,
    ]);

    $enrollment = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'status' => 'active',
    ]);

    // Credencial QR activa del estudiante
    $credential = StudentCredential::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'qr_code' => 'QR-SECURE-ORIGINAL-999',
        'status' => 'active',
        'version' => 1,
        'is_active' => true,
    ]);

    // Registrar incidencia de olvido de carnet en portería
    $response = $this->actingAs($this->admin)
        ->from(route('attendance.incidents'))
        ->post(route('attendance.incidents.store'), [
            'student_id' => $student->id,
            'type' => 'card_forgotten',
            'date' => '2026-11-16',
            'time' => '07:50',
            'reason' => 'Estudiante olvidó el fotocheck físico en casa; verificación manual de DNI',
            'responsible_name' => 'Roberto Gómez',
            'responsible_relationship' => 'Auxiliar',
            'is_justified' => true,
        ]);

    $response->assertRedirect(route('attendance.incidents'));
    $response->assertSessionHas('success');

    // Comprobar incidencia guardada en PostgreSQL
    $incident = AttendanceIncident::where('student_id', $student->id)->first();
    expect($incident)->not->toBeNull();
    expect($incident->type)->toBe('card_forgotten');
    expect($incident->reason)->toBe('Estudiante olvidó el fotocheck físico en casa; verificación manual de DNI');
    expect($incident->responsible_name)->toBe('Roberto Gómez');

    // La credencial QR original NO fue alterada ni invalidada
    $credential->refresh();
    expect($credential->status)->toBe('active');
    expect($credential->qr_code)->toBe('QR-SECURE-ORIGINAL-999');

    // Se registró el acceso manual con fuente correspondiente
    $record = AttendanceRecord::where('enrollment_id', $enrollment->id)->whereDate('date', '2026-11-16')->first();
    expect($record)->not->toBeNull();
    expect($record->source)->toBe('manual_card_forgotten');
});

test('permite registrar una salida anticipada guardando motivo, responsable y fecha conservando el ingreso original', function () {
    $student = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Thiago',
        'last_name' => 'Castillo',
        'is_active' => true,
    ]);

    $enrollment = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'status' => 'active',
    ]);

    // Registro de ingreso regular previo
    $record = AttendanceRecord::create([
        'institution_id' => $this->institution->id,
        'enrollment_id' => $enrollment->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'date' => '2026-11-16',
        'shift' => 'Mañana',
        'status' => 'present',
        'entry_time' => '07:45:10',
        'registered_by' => $this->admin->id,
    ]);

    // Registrar salida anticipada autorizada
    $response = $this->actingAs($this->admin)
        ->from(route('attendance.incidents'))
        ->post(route('attendance.incidents.store'), [
            'student_id' => $student->id,
            'type' => 'early_exit',
            'date' => '2026-11-16',
            'time' => '11:30',
            'reason' => 'Cita médica odontológica programada en policlínico',
            'responsible_name' => 'Carmen Morales',
            'responsible_relationship' => 'Madre',
            'is_justified' => true,
        ]);

    $response->assertRedirect(route('attendance.incidents'));
    $response->assertSessionHas('success');

    // Comprobar persistencia de incidencia
    $incident = AttendanceIncident::where('student_id', $student->id)->first();
    expect($incident)->not->toBeNull();
    expect($incident->type)->toBe('early_exit');
    expect($incident->responsible_name)->toBe('Carmen Morales');

    // Registro de asistencia conserva su hora de ingreso original y actualiza la salida anticipada
    $record->refresh();
    expect($record->entry_time)->toBe('07:45:10');
    expect($record->exit_time)->toBe('11:30:00');
    expect($record->exit_type)->toBe('early');
    expect($record->is_early_exit)->toBeTrue();
    expect($record->early_exit_reason)->toBe('Cita médica odontológica programada en policlínico');
    expect($record->is_justified)->toBeTrue();
});

test('permite justificar una incidencia existente en PostgreSQL guardando el sustento y actualizando el registro original', function () {
    $student = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Luciana',
        'last_name' => 'Ríos',
        'is_active' => true,
    ]);

    $enrollment = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'status' => 'active',
    ]);

    $record = AttendanceRecord::create([
        'institution_id' => $this->institution->id,
        'enrollment_id' => $enrollment->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'date' => '2026-11-16',
        'shift' => 'Mañana',
        'status' => 'late',
        'entry_time' => '08:25:00',
        'registered_by' => $this->admin->id,
        'is_justified' => false,
    ]);

    // Incidencia pendiente de justificación
    $incident = AttendanceIncident::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'enrollment_id' => $enrollment->id,
        'attendance_record_id' => $record->id,
        'type' => 'tardiness',
        'date' => '2026-11-16',
        'time' => '08:25:00',
        'reason' => 'Retraso de ingreso matutino',
        'responsible_name' => 'Pendiente de sustento',
        'registered_by' => $this->admin->id,
        'status' => 'registered',
        'is_justified' => false,
    ]);

    // Justificar incidencia
    $response = $this->actingAs($this->admin)
        ->from(route('attendance.incidents'))
        ->patch(route('attendance.incidents.justify', $incident), [
            'justification_reason' => 'Certificado médico oficial presentado por apoderado',
            'responsible_name' => 'Manuel Ríos (Padre)',
        ]);

    $response->assertRedirect(route('attendance.incidents'));
    $response->assertSessionHas('success');

    // Comprobar actualización en PostgreSQL
    $incident->refresh();
    expect($incident->is_justified)->toBeTrue();
    expect($incident->status)->toBe('justified');
    expect($incident->justification_reason)->toBe('Certificado médico oficial presentado por apoderado');
    expect($incident->justified_by)->toBe($this->admin->id);
    expect($incident->justified_at)->not->toBeNull();

    // Comprobar que el registro original de asistencia ahora está justificado conservando hora y estado
    $record->refresh();
    expect($record->is_justified)->toBeTrue();
    expect($record->entry_time)->toBe('08:25:00');
    expect($record->status)->toBe('late');
});

test('permite filtrar incidencias por tipo y estado de justificacion', function () {
    $student = Student::factory()->create(['institution_id' => $this->institution->id]);

    AttendanceIncident::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'type' => 'tardiness',
        'date' => '2026-11-16',
        'reason' => 'Tardanza 1',
        'responsible_name' => 'Apoderado 1',
        'registered_by' => $this->admin->id,
        'is_justified' => false,
    ]);

    AttendanceIncident::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'type' => 'card_forgotten',
        'date' => '2026-11-16',
        'reason' => 'Olvido de carnet 1',
        'responsible_name' => 'Apoderado 2',
        'registered_by' => $this->admin->id,
        'is_justified' => true,
    ]);

    // Filtrar por tipo card_forgotten
    $response = $this->actingAs($this->admin)
        ->get(route('attendance.incidents', ['type' => 'card_forgotten']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('attendance/Incidents')
        ->has('incidents', 1)
        ->where('incidents.0.type', 'card_forgotten')
        ->where('stats.total', 2)
        ->where('stats.card_forgotten', 1)
    );
});
