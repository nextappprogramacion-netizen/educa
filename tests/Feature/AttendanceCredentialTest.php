<?php

use App\Models\Institution;
use App\Models\Student;
use App\Models\StudentCredential;
use App\Models\User;

beforeEach(function () {
    $this->institution = Institution::create([
        'name' => 'Colegio San Martín Credenciales Test',
        'code' => 'CRED-SMP',
        'timezone' => 'America/Lima',
        'is_active' => true,
    ]);

    $this->admin = User::factory()->create([
        'institution_id' => $this->institution->id,
        'role' => 'admin',
    ]);
});

test('un usuario autenticado puede consultar el listado de credenciales en PostgreSQL', function () {
    $activeStudent = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Mateo',
        'last_name' => 'Álvarez',
        'document_number' => '78451230',
        'code' => 'EST-1001',
        'is_active' => true,
    ]);

    StudentCredential::create([
        'institution_id' => $this->institution->id,
        'student_id' => $activeStudent->id,
        'qr_code' => StudentCredential::generateSecureQrCode(),
        'status' => 'active',
        'issued_at' => now(),
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('attendance.credentials'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('attendance/Credentials')
        ->has('students', 1)
        ->has('metrics')
        ->where('metrics.total_students', 1)
        ->where('metrics.active_credentials', 1)
        ->where('metrics.pending_credentials', 0)
        ->where('students.0.first_name', 'Mateo')
        ->where('students.0.credential.is_active', true)
    );
});

test('se puede generar un código QR único y seguro para un estudiante activo y se guarda en PostgreSQL', function () {
    $student = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Camila',
        'last_name' => 'Bermúdez',
        'document_number' => '71234567',
        'code' => 'EST-1002',
        'is_active' => true,
    ]);

    $response = $this->actingAs($this->admin)
        ->post(route('attendance.credentials.generate', $student));

    $response->assertRedirect();

    $this->assertDatabaseHas('student_credentials', [
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'status' => 'active',
    ]);

    $credential = StudentCredential::where('student_id', $student->id)->first();
    expect($credential)->not->toBeNull();
    expect($credential->qr_code)->toStartWith('EDUCA-SEC-');
});

test('el código QR generado NO contiene información personal del estudiante', function () {
    $student = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Sebastián',
        'last_name' => 'Gonzales',
        'document_number' => '78965412',
        'code' => 'EST-2026-X',
        'email' => 'sebastian.gonzales@colegio.edu.pe',
        'is_active' => true,
    ]);

    $this->actingAs($this->admin)
        ->post(route('attendance.credentials.generate', $student));

    $credential = StudentCredential::where('student_id', $student->id)->first();

    // Comprobar rigurosamente que el QR no expone datos personales
    expect($credential->qr_code)->not->toContain($student->first_name);
    expect($credential->qr_code)->not->toContain($student->last_name);
    expect($credential->qr_code)->not->toContain($student->document_number);
    expect($credential->qr_code)->not->toContain($student->code);
    expect($credential->qr_code)->not->toContain($student->email);
    expect($credential->qr_code)->not->toContain('Sebastián');
    expect($credential->qr_code)->not->toContain('Gonzales');
    expect($credential->qr_code)->not->toContain('78965412');
});

test('no se puede generar credencial QR para un estudiante inactivo', function () {
    $inactiveStudent = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Alonso',
        'last_name' => 'Ramos',
        'is_active' => false,
    ]);

    $response = $this->actingAs($this->admin)
        ->post(route('attendance.credentials.generate', $inactiveStudent));

    $response->assertSessionHasErrors('student');

    $this->assertDatabaseMissing('student_credentials', [
        'student_id' => $inactiveStudent->id,
    ]);
});

test('se pueden generar códigos QR en lote para todos los estudiantes activos sin credencial', function () {
    $student1 = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'is_active' => true,
    ]);

    $student2 = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'is_active' => true,
    ]);

    $inactiveStudent = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'is_active' => false,
    ]);

    $response = $this->actingAs($this->admin)
        ->post(route('attendance.credentials.generate-batch'));

    $response->assertRedirect();

    $this->assertDatabaseHas('student_credentials', ['student_id' => $student1->id]);
    $this->assertDatabaseHas('student_credentials', ['student_id' => $student2->id]);
    $this->assertDatabaseMissing('student_credentials', ['student_id' => $inactiveStudent->id]);

    $cred1 = StudentCredential::where('student_id', $student1->id)->first();
    $cred2 = StudentCredential::where('student_id', $student2->id)->first();

    // Verificar que los códigos QR generados son únicos e independientes
    expect($cred1->qr_code)->not->toBe($cred2->qr_code);
});

test('se puede bloquear y reactivar una credencial en PostgreSQL', function () {
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

    // Bloquear credencial
    $response = $this->actingAs($this->admin)
        ->patch(route('attendance.credentials.toggle-status', $student));

    $response->assertRedirect();
    expect($credential->fresh()->status)->toBe('blocked');

    // Reactivar credencial
    $response = $this->actingAs($this->admin)
        ->patch(route('attendance.credentials.toggle-status', $student));

    $response->assertRedirect();
    expect($credential->fresh()->status)->toBe('active');
});

test('se puede bloquear una credencial explícitamente mediante la ruta block', function () {
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
        ->post(route('attendance.credentials.block', $student));

    $response->assertRedirect();
    expect($credential->fresh()->status)->toBe('blocked');
});

test('se puede desbloquear una credencial bloqueada mediante la ruta unblock', function () {
    $student = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'is_active' => true,
    ]);

    $credential = StudentCredential::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'qr_code' => StudentCredential::generateSecureQrCode(),
        'status' => 'blocked',
        'issued_at' => now(),
    ]);

    $response = $this->actingAs($this->admin)
        ->post(route('attendance.credentials.unblock', $student));

    $response->assertRedirect();
    expect($credential->fresh()->status)->toBe('active');
});

test('al renovar un QR, el anterior queda invalidado inmediatamente conservando el historial en PostgreSQL', function () {
    $student = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Renzo',
        'last_name' => 'Paredes',
        'is_active' => true,
    ]);

    $initialCredential = StudentCredential::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'qr_code' => StudentCredential::generateSecureQrCode(),
        'status' => 'active',
        'issued_at' => now()->subDays(30),
    ]);

    $oldQrCode = $initialCredential->qr_code;

    // Renovar la credencial
    $response = $this->actingAs($this->admin)
        ->post(route('attendance.credentials.renew', $student));

    $response->assertRedirect();

    // 1. Verificar que el QR anterior quedó INVALIDADO inmediatamente en PostgreSQL
    $initialCredential->refresh();
    expect($initialCredential->status)->toBe('invalidated');
    expect($initialCredential->invalidated_at)->not->toBeNull();
    expect($initialCredential->qr_code)->toBe($oldQrCode);

    // 2. Verificar que se conserva el historial (ambos registros existen en PostgreSQL)
    expect($student->credentials()->count())->toBe(2);

    // 3. Verificar que la nueva credencial está ACTIVA y tiene un nuevo código QR único
    $newCredential = $student->credential;
    expect($newCredential)->not->toBeNull();
    expect($newCredential->id)->not->toBe($initialCredential->id);
    expect($newCredential->status)->toBe('active');
    expect($newCredential->qr_code)->toStartWith('EDUCA-SEC-');
    expect($newCredential->qr_code)->not->toBe($oldQrCode);

    // 4. Renovar por segunda vez para verificar historial acumulado
    $this->actingAs($this->admin)
        ->post(route('attendance.credentials.renew', $student));

    expect($student->credentials()->count())->toBe(3);
    expect($newCredential->fresh()->status)->toBe('invalidated');

    $latestCredential = $student->fresh()->credential;
    expect($latestCredential->status)->toBe('active');
    expect($latestCredential->id)->not->toBe($newCredential->id);
});

test('al renovar una credencial que estaba bloqueada, queda invalidada y la nueva queda activa', function () {
    $student = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'is_active' => true,
    ]);

    $blockedCredential = StudentCredential::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'qr_code' => StudentCredential::generateSecureQrCode(),
        'status' => 'blocked',
        'issued_at' => now()->subDays(5),
    ]);

    $response = $this->actingAs($this->admin)
        ->post(route('attendance.credentials.renew', $student));

    $response->assertRedirect();

    expect($blockedCredential->fresh()->status)->toBe('invalidated');

    $activeCredential = $student->fresh()->credential;
    expect($activeCredential->status)->toBe('active');
    expect($student->credentials()->count())->toBe(2);
});

test('no se puede generar credencial para un estudiante de otra institución', function () {
    $otherInstitution = Institution::create([
        'name' => 'Otra Institución',
        'code' => 'OTRA-INST',
        'timezone' => 'America/Lima',
        'is_active' => true,
    ]);

    $foreignStudent = Student::factory()->create([
        'institution_id' => $otherInstitution->id,
        'is_active' => true,
    ]);

    $response = $this->actingAs($this->admin)
        ->post(route('attendance.credentials.generate', $foreignStudent));

    $response->assertForbidden();
});
