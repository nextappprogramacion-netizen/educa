<?php

use App\Models\AcademicYear;
use App\Models\AttendanceIncident;
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
    $this->institution = Institution::factory()->create([
        'name' => 'Colegio San Martin Educa',
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
        'name' => '3° Grado de Secundaria',
        'level' => 'Secundaria',
        'stage' => 'VI',
        'order' => 3,
        'is_active' => true,
    ]);

    $this->section = Section::create([
        'institution_id' => $this->institution->id,
        'grade_id' => $this->grade->id,
        'name' => 'Sección A',
        'shift' => 'Mañana',
        'capacity' => 35,
    ]);

    // Horario oficial de la jornada
    $this->schedule = AttendanceSchedule::create([
        'institution_id' => $this->institution->id,
        'shift' => 'Mañana',
        'name' => 'Turno Mañana Regular',
        'entry_time' => '08:00:00',
        'exit_time' => '13:00:00',
        'tolerance_minutes' => 0,
        'late_cutoff_time' => '08:45:00',
        'is_active' => true,
    ]);

    // Terminal de Ingreso
    $this->entryTerminal = AttendanceTerminal::create([
        'institution_id' => $this->institution->id,
        'name' => 'Torniquete Entrada 1',
        'code' => 'TERM-IN-01',
        'mode' => 'entry',
        'is_active' => true,
    ]);

    // Terminal de Salida
    $this->exitTerminal = AttendanceTerminal::create([
        'institution_id' => $this->institution->id,
        'name' => 'Torniquete Salida 1',
        'code' => 'TERM-OUT-01',
        'mode' => 'exit',
        'is_active' => true,
    ]);
});

test('flujo integral completo: registro estudiante, credencial QR, escaneo ingreso puntual, salida anticipada autorizada, historial, renovacion y reportes', function () {
    // ==========================================
    // PASO 1: Registro de un nuevo estudiante
    // ==========================================
    $studentData = [
        'first_name' => 'Mateo',
        'last_name' => 'Vargas Quispe',
        'document_type' => 'DNI',
        'document_number' => '78945612',
        'gender' => 'M',
        'birth_date' => '2012-05-14',
        'section_id' => $this->section->id,
    ];

    $responseCreateStudent = $this->actingAs($this->admin)->post(
        route('attendance.students.store'),
        $studentData
    );

    $responseCreateStudent->assertRedirect();

    $student = Student::where('document_number', '78945612')->first();
    expect($student)->not->toBeNull();
    expect($student->is_active)->toBeTrue();

    $enrollment = Enrollment::where('student_id', $student->id)->first();
    expect($enrollment)->not->toBeNull();
    expect($enrollment->section_id)->toBe($this->section->id);

    // ==========================================
    // PASO 2: Emisión de Credencial QR
    // ==========================================
    $responseGenQr = $this->actingAs($this->admin)->post(
        route('attendance.credentials.generate', ['student' => $student->id])
    );
    $responseGenQr->assertRedirect();

    $credential1 = StudentCredential::where('student_id', $student->id)
        ->where('status', 'active')
        ->first();

    expect($credential1)->not->toBeNull();
    expect($credential1->qr_code)->not->toBeEmpty();
    expect($credential1->status)->toBe('active');

    // ==========================================
    // PASO 3: Escaneo de Ingreso en Terminal de Entrada (07:50:00 -> Puntual)
    // ==========================================
    Carbon::setTestNow('2026-11-16 07:50:00');

    $responseEntry = $this->actingAs($this->admin)->post(
        route('attendance.scanner.scan'),
        [
            'qr_code' => $credential1->qr_code,
            'terminal_id' => $this->entryTerminal->id,
        ]
    );

    $responseEntry->assertRedirect();
    $responseEntry->assertRedirect();
    $responseEntry->assertSessionHas('last_scan');

    $record = AttendanceRecord::where('enrollment_id', $enrollment->id)
        ->whereDate('date', '2026-11-16')
        ->first();

    expect($record)->not->toBeNull();
    expect($record->status)->toBe('present'); // Puntual (antes de 08:00)
    expect($record->entry_time)->toBe('07:50:00');

    // ==========================================
    // PASO 4: Intento de Escaneo Duplicado de Ingreso
    // ==========================================
    Carbon::setTestNow('2026-11-16 07:55:00');

    $responseEntryDup = $this->actingAs($this->admin)->post(
        route('attendance.scanner.scan'),
        [
            'qr_code' => $credential1->qr_code,
            'terminal_id' => $this->entryTerminal->id,
        ]
    );

    $responseEntryDup->assertSessionHasErrors(['qr_code']);
    $responseEntryDup->assertSessionHas('scan_error_detail');
    // No debe haber creado un segundo registro en PostgreSQL
    expect(AttendanceRecord::where('enrollment_id', $enrollment->id)->whereDate('date', '2026-11-16')->count())->toBe(1);

    // ==========================================
    // PASO 5: Escaneo de Salida Anticipada (11:30:00 < 13:00:00) sin autorizar
    // ==========================================
    Carbon::setTestNow('2026-11-16 11:30:00');

    $responseExitPrompt = $this->actingAs($this->admin)->post(
        route('attendance.scanner.scan'),
        [
            'qr_code' => $credential1->qr_code,
            'terminal_id' => $this->exitTerminal->id,
        ]
    );

    $responseExitPrompt->assertSessionHas('early_exit_prompt');

    // ==========================================
    // PASO 6: Autorización y Registro de Salida Anticipada
    // ==========================================
    $responseExitAuth = $this->actingAs($this->admin)->post(
        route('attendance.scanner.scan'),
        [
            'qr_code' => $credential1->qr_code,
            'terminal_id' => $this->exitTerminal->id,
            'authorized' => true,
            'authorization_reason' => 'Retiro médico con apoderado',
        ]
    );

    $responseExitAuth->assertRedirect();
    $record->refresh();

    expect($record->exit_time)->toBe('11:30:00');
    expect($record->is_early_exit)->toBeTrue();
    expect($record->early_exit_reason)->toBe('Retiro médico con apoderado');
    expect($record->early_exit_authorized_by)->toBe($this->admin->id);

    // ==========================================
    // PASO 7: Consulta en Historial de Asistencia
    // ==========================================
    $responseHistory = $this->actingAs($this->admin)->get(
        route('attendance.history', ['date' => '2026-11-16'])
    );

    $responseHistory->assertOk();
    $responseHistory->assertInertia(fn ($page) => $page
        ->component('attendance/History')
        ->where('stats.punctual', 1)
        ->where('stats.early_exit', 1)
        ->has('records', 1)
        ->where('records.0.student.full_name', 'Vargas Quispe, Mateo')
        ->where('records.0.entry_time', '07:50')
        ->where('records.0.exit_time', '11:30')
        ->where('records.0.is_early_exit', true)
    );

    // ==========================================
    // PASO 8: Renovación de Credencial QR (Invalida la anterior)
    // ==========================================
    $responseRenew = $this->actingAs($this->admin)->post(
        route('attendance.credentials.renew', ['student' => $student->id])
    );

    $responseRenew->assertRedirect();

    $credential1->refresh();
    expect($credential1->status)->toBe('invalidated');

    $credential2 = StudentCredential::where('student_id', $student->id)
        ->where('status', 'active')
        ->first();

    expect($credential2)->not->toBeNull();
    expect($credential2->id)->not->toBe($credential1->id);

    // Intentar escanear con la credencial invalidada debe ser rechazado
    $responseInvalidScan = $this->actingAs($this->admin)->post(
        route('attendance.scanner.scan'),
        [
            'qr_code' => $credential1->qr_code,
            'terminal_id' => $this->entryTerminal->id,
        ]
    );
    $responseInvalidScan->assertSessionHasErrors();

    // ==========================================
    // PASO 9: Bloqueo de Credencial QR
    // ==========================================
    $responseBlock = $this->actingAs($this->admin)->post(
        route('attendance.credentials.block', ['student' => $student->id])
    );
    $responseBlock->assertRedirect();

    $credential2->refresh();
    expect($credential2->status)->toBe('blocked');

    // Intentar escanear con credencial bloqueada debe ser rechazado
    $responseBlockedScan = $this->actingAs($this->admin)->post(
        route('attendance.scanner.scan'),
        [
            'qr_code' => $credential2->qr_code,
            'terminal_id' => $this->entryTerminal->id,
        ]
    );
    $responseBlockedScan->assertSessionHasErrors();

    // Desbloquear credencial
    $this->actingAs($this->admin)->post(
        route('attendance.credentials.unblock', ['student' => $student->id])
    );
    $credential2->refresh();
    expect($credential2->status)->toBe('active');

    // ==========================================
    // PASO 10: Registro y Justificación de Incidencia
    // ==========================================
    $responseIncident = $this->actingAs($this->admin)->post(
        route('attendance.incidents.store'),
        [
            'student_id' => $student->id,
            'type' => 'early_exit',
            'date' => '2026-11-16',
            'time' => '11:30',
            'reason' => 'Salida anticipada por cita odontológica',
            'responsible_name' => 'Rosa Quispe (Madre)',
            'responsible_relationship' => 'Madre',
            'is_justified' => false,
        ]
    );
    $responseIncident->assertRedirect();

    $incident = AttendanceIncident::where('student_id', $student->id)->first();
    expect($incident)->not->toBeNull();
    expect($incident->is_justified)->toBeFalse();

    // Justificar incidencia
    $responseJustify = $this->actingAs($this->admin)->patch(
        route('attendance.incidents.justify', ['incident' => $incident->id]),
        [
            'justification_reason' => 'Se presentó constancia de atención odontológica',
        ]
    );
    $responseJustify->assertRedirect();
    $incident->refresh();
    expect($incident->is_justified)->toBeTrue();

    // ==========================================
    // PASO 11: Panel General (Overview) con datos reales de PostgreSQL
    // ==========================================
    $responseOverview = $this->actingAs($this->admin)->get(
        route('attendance.overview', ['period' => 'daily'])
    );
    $responseOverview->assertOk();
    $responseOverview->assertInertia(fn ($page) => $page
        ->component('attendance/Overview')
        ->where('stats.punctual', 1)
        ->where('stats.total_entries', 1)
        ->where('stats.total_exits', 1)
        ->where('stats.early_exits', 1)
        ->has('chart.series', 7)
    );

    // ==========================================
    // PASO 12: Reportes de Asistencia y Exportaciones PDF / Excel
    // ==========================================
    $responseReports = $this->actingAs($this->admin)->get(
        route('attendance.reports', [
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
        ])
    );
    $responseReports->assertOk();
    $responseReports->assertInertia(fn ($page) => $page
        ->component('attendance/Reports')
        ->where('summary.total_evaluated', 1)
        ->where('summary.total_punctual', 1)
        ->where('summary.total_exits', 1)
        ->where('summary.total_early_exits', 1)
        ->has('records', 1)
    );

    // Exportar PDF
    $responsePdf = $this->actingAs($this->admin)->get(
        route('attendance.reports.export.pdf', [
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
        ])
    );
    $responsePdf->assertOk();
    $responsePdf->assertHeader('Content-Type', 'application/pdf');
    expect((string) $responsePdf->getContent())->toStartWith('%PDF-1.4');

    // Exportar Excel
    $responseExcel = $this->actingAs($this->admin)->get(
        route('attendance.reports.export.excel', [
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
        ])
    );
    $responseExcel->assertOk();
    $responseExcel->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect((string) $responseExcel->getContent())->toStartWith("PK\x03\x04");
});

test('flujo de tardanza, salida regular y clasificacion automatica de inasistencia tras fin de jornada', function () {
    // Estudiante 2: Ingreso tardío y salida regular
    $student2 = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Lucia',
        'last_name' => 'Mendoza',
        'is_active' => true,
    ]);
    $enrollment2 = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student2->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'status' => 'active',
    ]);
    $cred2 = StudentCredential::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student2->id,
        'qr_code' => StudentCredential::generateSecureQrCode(),
        'status' => 'active',
        'issued_at' => now(),
    ]);

    // Estudiante 3: Sin registro (para evaluar clasificación de ausencia)
    $student3 = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Diego',
        'last_name' => 'Salazar',
        'is_active' => true,
    ]);
    $enrollment3 = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student3->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->section->id,
        'status' => 'active',
    ]);

    // 1. Escaneo de ingreso tardío (08:15:00 > 08:00:00)
    Carbon::setTestNow('2026-11-17 08:15:00');

    $responseLate = $this->actingAs($this->admin)->post(
        route('attendance.scanner.scan'),
        [
            'qr_code' => $cred2->qr_code,
            'terminal_id' => $this->entryTerminal->id,
        ]
    );
    $responseLate->assertRedirect();

    $record2 = AttendanceRecord::where('enrollment_id', $enrollment2->id)
        ->whereDate('date', '2026-11-17')
        ->first();

    expect($record2)->not->toBeNull();
    expect($record2->status)->toBe('late'); // Tardanza automática sin tolerancia
    expect($record2->entry_time)->toBe('08:15:00');

    // 2. Escaneo de salida regular (13:10:00 > 13:00:00)
    Carbon::setTestNow('2026-11-17 13:10:00');

    $responseRegularExit = $this->actingAs($this->admin)->post(
        route('attendance.scanner.scan'),
        [
            'qr_code' => $cred2->qr_code,
            'terminal_id' => $this->exitTerminal->id,
        ]
    );
    $responseRegularExit->assertRedirect();
    $record2->refresh();

    expect($record2->exit_time)->toBe('13:10:00');
    expect($record2->is_early_exit)->toBeFalse();
    expect($record2->exit_type)->toBe('regular');

    // 3. Estudiante 3: Después del cierre de jornada (13:30:00), clasificar como ausente
    Carbon::setTestNow('2026-11-17 13:30:00');

    $responseClassify = $this->actingAs($this->admin)->post(
        route('attendance.history.classify-absent'),
        [
            'date' => '2026-11-17',
            'enrollment_id' => $enrollment3->id,
        ]
    );
    $responseClassify->assertRedirect();

    $record3 = AttendanceRecord::where('enrollment_id', $enrollment3->id)
        ->whereDate('date', '2026-11-17')
        ->first();

    expect($record3)->not->toBeNull();
    expect($record3->status)->toBe('absent'); // Clasificado como falta
});
