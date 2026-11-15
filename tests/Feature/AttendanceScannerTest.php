<?php

use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSchedule;
use App\Models\AttendanceTerminal;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Institution;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentCredential;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->institution = Institution::create([
        'name' => 'Colegio San Martín Scanner Test',
        'code' => 'SCAN-SMP',
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

    $this->entrySchedule = AttendanceSchedule::create([
        'institution_id' => $this->institution->id,
        'name' => 'Turno Mañana',
        'shift' => 'Mañana',
        'entry_time' => '08:00:00',
        'exit_time' => '13:30:00',
        'tolerance_minutes' => 0, // Cero tolerancia
        'is_active' => true,
    ]);

    $this->entryTerminal = AttendanceTerminal::create([
        'institution_id' => $this->institution->id,
        'name' => 'Torniquete Principal Entrada',
        'code' => 'TERM-ING-01',
        'mode' => 'entry', // Exclusivo Ingreso
        'location' => 'Puerta 1',
        'is_active' => true,
    ]);

    $this->exitTerminal = AttendanceTerminal::create([
        'institution_id' => $this->institution->id,
        'name' => 'Lector Puerta Salida Principal',
        'code' => 'TERM-SAL-01',
        'mode' => 'exit', // Exclusivo Salida
        'location' => 'Puerta Salida',
        'is_active' => true,
    ]);
});

afterEach(function () {
    Carbon::setTestNow(); // Restaurar hora real
});

test('un usuario autenticado puede acceder a la estacion de escaneo QR y ver las terminales de entrada', function () {
    $response = $this->actingAs($this->admin)
        ->get(route('attendance.scanner'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('attendance/Scanner')
        ->has('entry_terminals', 1)
        ->where('entry_terminals.0.code', 'TERM-ING-01')
        ->where('entry_terminals.0.mode', 'entry')
        ->has('recent_scans')
    );
});

test('el escaneo de QR desde una terminal autorizada registra el ingreso puntual antes de la hora oficial', function () {
    $student = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Mateo',
        'last_name' => 'Álvarez',
        'is_active' => true,
    ]);

    $enrollment = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'status' => 'active',
    ]);

    $credential = StudentCredential::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'qr_code' => StudentCredential::generateSecureQrCode(),
        'status' => 'active',
        'issued_at' => now(),
    ]);

    // Fijar hora oficial del servidor en 07:50:00 (antes de las 08:00:00)
    Carbon::setTestNow('2026-11-15 07:50:00');

    $response = $this->actingAs($this->admin)
        ->post(route('attendance.scanner.scan'), [
            'qr_code' => $credential->qr_code,
            'terminal_id' => $this->entryTerminal->id,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    // Comprobar persistencia exacta en PostgreSQL
    $record = AttendanceRecord::where('enrollment_id', $enrollment->id)->first();
    expect($record)->not->toBeNull();
    expect($record->institution_id)->toBe($this->institution->id);
    expect($record->shift)->toBe('Mañana');
    expect($record->entry_time)->toBe('07:50:00');
    expect($record->status)->toBe('present'); // Puntual
    expect($record->source)->toBe('qr_terminal');
    expect($record->date->format('Y-m-d'))->toBe('2026-11-15');
});

test('el escaneo de QR registra tardanza si la hora del servidor es posterior a la hora oficial sin tolerancia', function () {
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

    $credential = StudentCredential::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'qr_code' => StudentCredential::generateSecureQrCode(),
        'status' => 'active',
        'issued_at' => now(),
    ]);

    // Fijar hora oficial del servidor exactamente 1 segundo después (08:00:01)
    Carbon::setTestNow('2026-11-15 08:00:01');

    $response = $this->actingAs($this->admin)
        ->post(route('attendance.scanner.scan'), [
            'qr_code' => $credential->qr_code,
            'terminal_id' => $this->entryTerminal->id,
        ]);

    $response->assertRedirect();

    // Comprobar clasificación automática como TARDANZA sin tolerancia
    $record = AttendanceRecord::where('enrollment_id', $enrollment->id)->first();
    expect($record)->not->toBeNull();
    expect($record->institution_id)->toBe($this->institution->id);
    expect($record->shift)->toBe('Mañana');
    expect($record->entry_time)->toBe('08:00:01');
    expect($record->status)->toBe('late'); // Tardío sin tolerancia
    expect($record->date->format('Y-m-d'))->toBe('2026-11-15');
});

test('se evitan registros duplicados de ingreso para el mismo estudiante en el mismo dia', function () {
    $student = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Lucas',
        'last_name' => 'Espinoza',
        'is_active' => true,
    ]);

    $enrollment = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'status' => 'active',
    ]);

    $credential = StudentCredential::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'qr_code' => StudentCredential::generateSecureQrCode(),
        'status' => 'active',
        'issued_at' => now(),
    ]);

    Carbon::setTestNow('2026-11-15 07:45:00');

    // 1er escaneo exitoso
    $this->actingAs($this->admin)
        ->post(route('attendance.scanner.scan'), [
            'qr_code' => $credential->qr_code,
            'terminal_id' => $this->entryTerminal->id,
        ]);

    expect(AttendanceRecord::where('enrollment_id', $enrollment->id)->count())->toBe(1);

    // 2do escaneo duplicado 5 minutos después
    Carbon::setTestNow('2026-11-15 07:50:00');

    $response = $this->actingAs($this->admin)
        ->post(route('attendance.scanner.scan'), [
            'qr_code' => $credential->qr_code,
            'terminal_id' => $this->entryTerminal->id,
        ]);

    $response->assertSessionHasErrors('qr_code');

    // Verificar que NO se creó un segundo registro en PostgreSQL
    expect(AttendanceRecord::where('enrollment_id', $enrollment->id)->count())->toBe(1);
    $record = AttendanceRecord::where('enrollment_id', $enrollment->id)->first();
    expect($record->entry_time)->toBe('07:45:00');
});

test('no se permite registrar ingreso desde una terminal configurada para Salida', function () {
    $student = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'is_active' => true,
    ]);

    $credential = StudentCredential::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'qr_code' => StudentCredential::generateSecureQrCode(),
        'status' => 'active',
        'issued_at' => now(),
    ]);

    $response = $this->actingAs($this->admin)
        ->post(route('attendance.scanner.entry'), [
            'qr_code' => $credential->qr_code,
            'terminal_id' => $this->exitTerminal->id,
        ]);

    $response->assertSessionHasErrors('qr_code');
    $this->assertDatabaseMissing('attendance_records', [
        'institution_id' => $this->institution->id,
    ]);
});

test('no se permite registrar ingreso desde una terminal inhabilitada', function () {
    $disabledTerminal = AttendanceTerminal::create([
        'institution_id' => $this->institution->id,
        'name' => 'Terminal Inhabilitada',
        'code' => 'TERM-DIS-01',
        'mode' => 'entry',
        'is_active' => false, // Inhabilitada
    ]);

    $student = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'is_active' => true,
    ]);

    $credential = StudentCredential::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'qr_code' => StudentCredential::generateSecureQrCode(),
        'status' => 'active',
        'issued_at' => now(),
    ]);

    $response = $this->actingAs($this->admin)
        ->post(route('attendance.scanner.scan'), [
            'qr_code' => $credential->qr_code,
            'terminal_id' => $disabledTerminal->id,
        ]);

    $response->assertSessionHasErrors('qr_code');
});

test('no se permite registrar ingreso con una credencial bloqueada o invalidada', function () {
    $student = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'is_active' => true,
    ]);

    $blockedCredential = StudentCredential::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'qr_code' => StudentCredential::generateSecureQrCode(),
        'status' => 'blocked', // Bloqueada
        'issued_at' => now(),
    ]);

    $response = $this->actingAs($this->admin)
        ->post(route('attendance.scanner.scan'), [
            'qr_code' => $blockedCredential->qr_code,
            'terminal_id' => $this->entryTerminal->id,
        ]);

    $response->assertSessionHasErrors('qr_code');
});

test('el escaneo de QR desde una terminal autorizada de salida registra la salida regular a partir de la hora oficial', function () {
    $student = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Renato',
        'last_name' => 'Paredes',
        'is_active' => true,
    ]);

    $enrollment = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'status' => 'active',
    ]);

    $credential = StudentCredential::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'qr_code' => StudentCredential::generateSecureQrCode(),
        'status' => 'active',
        'issued_at' => now(),
    ]);

    // Fijar hora oficial del servidor en 13:35:00 (hora oficial de salida configurada: 13:30:00)
    Carbon::setTestNow('2026-11-15 13:35:00');

    $response = $this->actingAs($this->admin)
        ->post(route('attendance.scanner.scan'), [
            'qr_code' => $credential->qr_code,
            'terminal_id' => $this->exitTerminal->id,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $record = AttendanceRecord::where('enrollment_id', $enrollment->id)->first();
    expect($record)->not->toBeNull();
    expect($record->institution_id)->toBe($this->institution->id);
    expect($record->exit_time)->toBe('13:35:00');
    expect($record->exit_type)->toBe('regular');
    expect($record->is_early_exit)->toBeFalse();
    expect($record->early_exit_reason)->toBeNull();
    expect($record->exit_registered_by)->toBe($this->admin->id);
    expect($record->date->format('Y-m-d'))->toBe('2026-11-15');
});

test('el escaneo de QR antes de la hora oficial detecta salida anticipada y solicita autorizacion obligatoria con motivo', function () {
    $student = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Luciana',
        'last_name' => 'Salazar',
        'is_active' => true,
    ]);

    $enrollment = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'status' => 'active',
    ]);

    $credential = StudentCredential::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'qr_code' => StudentCredential::generateSecureQrCode(),
        'status' => 'active',
        'issued_at' => now(),
    ]);

    // Fijar hora del servidor en 11:30:00 (antes de las 13:30:00) sin autorizar
    Carbon::setTestNow('2026-11-15 11:30:00');

    $response = $this->actingAs($this->admin)
        ->post(route('attendance.scanner.scan'), [
            'qr_code' => $credential->qr_code,
            'terminal_id' => $this->exitTerminal->id,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('early_exit_prompt');
    $response->assertSessionHasErrors('early_exit_required');

    $prompt = session('early_exit_prompt');
    expect($prompt)->not->toBeNull();
    expect($prompt['requires_authorization'])->toBeTrue();
    expect($prompt['student']['id'])->toBe($student->id);
    expect($prompt['server_time'])->toBe('11:30:00');
    expect($prompt['official_exit_time'])->toBe('13:30:00');

    // No debe haberse guardado registro de salida no autorizada
    $record = AttendanceRecord::where('enrollment_id', $enrollment->id)->first();
    expect($record)->toBeNull();
});

test('el escaneo de QR con autorizacion y motivo registra la salida anticipada en PostgreSQL', function () {
    $student = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Luciana',
        'last_name' => 'Salazar',
        'is_active' => true,
    ]);

    $enrollment = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'status' => 'active',
    ]);

    $credential = StudentCredential::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'qr_code' => StudentCredential::generateSecureQrCode(),
        'status' => 'active',
        'issued_at' => now(),
    ]);

    // Fijar hora del servidor en 11:30:00 con autorizacion y motivo
    Carbon::setTestNow('2026-11-15 11:30:00');

    $response = $this->actingAs($this->admin)
        ->post(route('attendance.scanner.scan'), [
            'qr_code' => $credential->qr_code,
            'terminal_id' => $this->exitTerminal->id,
            'authorized' => true,
            'authorization_reason' => 'Cita médica urgente coordinada con apoderado',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $record = AttendanceRecord::where('enrollment_id', $enrollment->id)->first();
    expect($record)->not->toBeNull();
    expect($record->exit_time)->toBe('11:30:00');
    expect($record->exit_type)->toBe('early');
    expect($record->is_early_exit)->toBeTrue();
    expect($record->early_exit_reason)->toBe('Cita médica urgente coordinada con apoderado');
    expect($record->early_exit_authorized_by)->toBe($this->admin->id);
    expect($record->exit_registered_by)->toBe($this->admin->id);
});

test('el registro de salida actualiza el registro de asistencia del dia si ya existia un ingreso', function () {
    $student = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Fabián',
        'last_name' => 'Gutiérrez',
        'is_active' => true,
    ]);

    $enrollment = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'status' => 'active',
    ]);

    $credential = StudentCredential::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'qr_code' => StudentCredential::generateSecureQrCode(),
        'status' => 'active',
        'issued_at' => now(),
    ]);

    // 1. Ingreso a las 07:48:00
    Carbon::setTestNow('2026-11-15 07:48:00');
    $this->actingAs($this->admin)
        ->post(route('attendance.scanner.scan'), [
            'qr_code' => $credential->qr_code,
            'terminal_id' => $this->entryTerminal->id,
        ]);

    expect(AttendanceRecord::where('enrollment_id', $enrollment->id)->count())->toBe(1);

    // 2. Salida regular a las 13:40:00
    Carbon::setTestNow('2026-11-15 13:40:00');
    $response = $this->actingAs($this->admin)
        ->post(route('attendance.scanner.scan'), [
            'qr_code' => $credential->qr_code,
            'terminal_id' => $this->exitTerminal->id,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    // Comprobar que no hay duplicación, es el mismo registro completado con entrada y salida
    expect(AttendanceRecord::where('enrollment_id', $enrollment->id)->count())->toBe(1);
    $record = AttendanceRecord::where('enrollment_id', $enrollment->id)->first();
    expect($record->entry_time)->toBe('07:48:00');
    expect($record->exit_time)->toBe('13:40:00');
    expect($record->status)->toBe('present');
    expect($record->exit_type)->toBe('regular');
    expect($record->is_early_exit)->toBeFalse();
});

test('se evitan registros duplicados de salida para el mismo estudiante en el mismo dia', function () {
    $student = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Diego',
        'last_name' => 'Ramos',
        'is_active' => true,
    ]);

    $enrollment = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'status' => 'active',
    ]);

    $credential = StudentCredential::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'qr_code' => StudentCredential::generateSecureQrCode(),
        'status' => 'active',
        'issued_at' => now(),
    ]);

    // 1ra salida exitosa
    Carbon::setTestNow('2026-11-15 13:32:00');
    $this->actingAs($this->admin)
        ->post(route('attendance.scanner.scan'), [
            'qr_code' => $credential->qr_code,
            'terminal_id' => $this->exitTerminal->id,
        ]);

    // 2do intento duplicado 5 minutos despues
    Carbon::setTestNow('2026-11-15 13:37:00');
    $response = $this->actingAs($this->admin)
        ->post(route('attendance.scanner.scan'), [
            'qr_code' => $credential->qr_code,
            'terminal_id' => $this->exitTerminal->id,
        ]);

    $response->assertSessionHasErrors('qr_code');

    $record = AttendanceRecord::where('enrollment_id', $enrollment->id)->first();
    expect($record->exit_time)->toBe('13:32:00');
});

test('no se permite registrar salida desde una terminal configurada para Ingreso', function () {
    $student = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'is_active' => true,
    ]);

    $credential = StudentCredential::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'qr_code' => StudentCredential::generateSecureQrCode(),
        'status' => 'active',
        'issued_at' => now(),
    ]);

    $response = $this->actingAs($this->admin)
        ->post(route('attendance.scanner.exit'), [
            'qr_code' => $credential->qr_code,
            'terminal_id' => $this->entryTerminal->id, // Terminal de Ingreso
        ]);

    $response->assertSessionHasErrors('qr_code');
});

test('se rechazan codigos QR invalidos o inexistentes con mensaje claro y sin crear movimientos', function () {
    $initialCount = AttendanceRecord::count();

    // 1. Escaneo con código QR que no existe en el sistema
    $response = $this->actingAs($this->admin)
        ->post(route('attendance.scanner.scan'), [
            'qr_code' => 'QR-INEXISTENTE-99999',
            'terminal_id' => $this->entryTerminal->id,
        ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('qr_code');
    $response->assertSessionHas('scan_error_detail', fn ($detail) => $detail['type'] === 'invalid_code' && str_contains($detail['message'], 'Código QR inválido'));

    // 2. Escaneo con código vacío
    $responseEmpty = $this->actingAs($this->admin)
        ->post(route('attendance.scanner.scan'), [
            'qr_code' => '   ',
            'terminal_id' => $this->entryTerminal->id,
        ]);

    $responseEmpty->assertRedirect();
    $responseEmpty->assertSessionHasErrors('qr_code');

    // Verificar que en ningún caso se generó registro en la base de datos
    expect(AttendanceRecord::count())->toBe($initialCount);
});

test('se rechazan credenciales bloqueadas por seguridad con mensaje claro y sin registrar asistencias', function () {
    $student = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Gonzalo',
        'last_name' => 'Navarro',
        'is_active' => true,
    ]);

    $credential = StudentCredential::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'qr_code' => StudentCredential::generateSecureQrCode(),
        'status' => 'blocked', // Bloqueada
        'issued_at' => now(),
    ]);

    $response = $this->actingAs($this->admin)
        ->post(route('attendance.scanner.scan'), [
            'qr_code' => $credential->qr_code,
            'terminal_id' => $this->entryTerminal->id,
        ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('qr_code');
    $response->assertSessionHas('scan_error_detail', function ($detail) use ($student) {
        return $detail['type'] === 'blocked_credential'
            && str_contains($detail['message'], 'BLOQUEADA')
            && str_contains($detail['message'], $student->full_name);
    });

    // Verificar que no se creó ningún movimiento incorrecto
    $this->assertDatabaseMissing('attendance_records', [
        'institution_id' => $this->institution->id,
    ]);
});

test('se rechazan credenciales invalidadas por emision de nuevo carnet con mensaje claro y sin registrar asistencias', function () {
    $student = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Valeria',
        'last_name' => 'Mendoza',
        'is_active' => true,
    ]);

    $oldCredential = StudentCredential::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'qr_code' => StudentCredential::generateSecureQrCode(),
        'status' => 'invalidated', // Invalidada por renovación
        'issued_at' => now()->subMonths(2),
    ]);

    $response = $this->actingAs($this->admin)
        ->post(route('attendance.scanner.scan'), [
            'qr_code' => $oldCredential->qr_code,
            'terminal_id' => $this->entryTerminal->id,
        ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('qr_code');
    $response->assertSessionHas('scan_error_detail', function ($detail) use ($student) {
        return $detail['type'] === 'invalidated_credential'
            && str_contains($detail['message'], 'invalidada')
            && str_contains($detail['message'], $student->full_name);
    });

    // Verificar que no se creó ningún movimiento incorrecto
    $this->assertDatabaseMissing('attendance_records', [
        'institution_id' => $this->institution->id,
    ]);
});

test('se rechazan estudiantes inactivos con mensaje claro y sin generar movimientos de asistencia', function () {
    $student = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Sebastián',
        'last_name' => 'Castro',
        'is_active' => false, // Inactivo / Desactivado
    ]);

    $credential = StudentCredential::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'qr_code' => StudentCredential::generateSecureQrCode(),
        'status' => 'active',
        'issued_at' => now(),
    ]);

    $response = $this->actingAs($this->admin)
        ->post(route('attendance.scanner.scan'), [
            'qr_code' => $credential->qr_code,
            'terminal_id' => $this->entryTerminal->id,
        ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('qr_code');
    $response->assertSessionHas('scan_error_detail', function ($detail) use ($student) {
        return $detail['type'] === 'inactive_student'
            && str_contains($detail['message'], 'inactivo')
            && str_contains($detail['message'], $student->full_name);
    });

    // Verificar que no se creó ningún movimiento incorrecto
    $this->assertDatabaseMissing('attendance_records', [
        'institution_id' => $this->institution->id,
    ]);
});

test('se rechaza lectura duplicada de ingreso informando hora previa sin alterar registros en PostgreSQL', function () {
    $student = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Alejandro',
        'last_name' => 'Pérez',
        'is_active' => true,
    ]);

    $enrollment = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'status' => 'active',
    ]);

    $credential = StudentCredential::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'qr_code' => StudentCredential::generateSecureQrCode(),
        'status' => 'active',
        'issued_at' => now(),
    ]);

    // 1er ingreso a las 07:52:00
    Carbon::setTestNow('2026-11-15 07:52:00');
    $this->actingAs($this->admin)
        ->post(route('attendance.scanner.scan'), [
            'qr_code' => $credential->qr_code,
            'terminal_id' => $this->entryTerminal->id,
        ]);

    expect(AttendanceRecord::where('enrollment_id', $enrollment->id)->count())->toBe(1);

    // 2do escaneo duplicado a las 07:55:00
    Carbon::setTestNow('2026-11-15 07:55:00');
    $response = $this->actingAs($this->admin)
        ->post(route('attendance.scanner.scan'), [
            'qr_code' => $credential->qr_code,
            'terminal_id' => $this->entryTerminal->id,
        ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('qr_code');
    $response->assertSessionHas('scan_error_detail', function ($detail) use ($student) {
        return $detail['type'] === 'duplicate_scan'
            && str_contains($detail['message'], 'duplicada')
            && str_contains($detail['message'], '07:52')
            && str_contains($detail['message'], $student->full_name);
    });

    // Verificar que solo existe 1 registro y su hora no fue alterada
    expect(AttendanceRecord::where('enrollment_id', $enrollment->id)->count())->toBe(1);
    $record = AttendanceRecord::where('enrollment_id', $enrollment->id)->first();
    expect($record->entry_time)->toBe('07:52:00');
});

test('se rechaza lectura duplicada de salida informando hora previa sin alterar registros en PostgreSQL', function () {
    $student = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Mariana',
        'last_name' => 'Flores',
        'is_active' => true,
    ]);

    $enrollment = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'status' => 'active',
    ]);

    $credential = StudentCredential::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'qr_code' => StudentCredential::generateSecureQrCode(),
        'status' => 'active',
        'issued_at' => now(),
    ]);

    // 1ra salida regular a las 13:35:00
    Carbon::setTestNow('2026-11-15 13:35:00');
    $this->actingAs($this->admin)
        ->post(route('attendance.scanner.scan'), [
            'qr_code' => $credential->qr_code,
            'terminal_id' => $this->exitTerminal->id,
        ]);

    expect(AttendanceRecord::where('enrollment_id', $enrollment->id)->count())->toBe(1);

    // 2do escaneo duplicado de salida a las 13:38:00
    Carbon::setTestNow('2026-11-15 13:38:00');
    $response = $this->actingAs($this->admin)
        ->post(route('attendance.scanner.scan'), [
            'qr_code' => $credential->qr_code,
            'terminal_id' => $this->exitTerminal->id,
        ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('qr_code');
    $response->assertSessionHas('scan_error_detail', function ($detail) use ($student) {
        return $detail['type'] === 'duplicate_scan'
            && str_contains($detail['message'], 'duplicada')
            && str_contains($detail['message'], '13:35')
            && str_contains($detail['message'], $student->full_name);
    });

    // Verificar que solo existe 1 registro y su hora de salida no fue sobreescrita
    expect(AttendanceRecord::where('enrollment_id', $enrollment->id)->count())->toBe(1);
    $record = AttendanceRecord::where('enrollment_id', $enrollment->id)->first();
    expect($record->exit_time)->toBe('13:35:00');
});
