<?php

use App\Models\AcademicCalendarDay;
use App\Models\AttendanceTerminal;
use App\Models\Institution;
use App\Models\User;

beforeEach(function () {
    $this->institution = Institution::create([
        'name' => 'Colegio San Martín Horarios Test',
        'code' => 'SCHED-SMP',
        'timezone' => 'America/Lima',
        'is_active' => true,
    ]);

    $this->admin = User::factory()->create([
        'institution_id' => $this->institution->id,
        'role' => 'admin',
    ]);
});

test('un usuario autenticado puede consultar los horarios por turno en configuración de asistencia', function () {
    $response = $this->actingAs($this->admin)
        ->get(route('attendance.settings'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('attendance/Settings')
        ->has('schedules', 2)
        ->where('schedules.0.shift', 'Mañana')
        ->where('schedules.0.entry_time', '08:00')
        ->where('schedules.0.exit_time', '13:30')
        ->where('schedules.0.tolerance_minutes', 0)
        ->where('schedules.1.shift', 'Tarde')
        ->where('schedules.1.entry_time', '13:30')
        ->where('schedules.1.exit_time', '18:30')
        ->where('schedules.1.tolerance_minutes', 0)
    );
});

test('se pueden actualizar los horarios de ingreso y salida por turno en PostgreSQL sin tolerancia', function () {
    $payload = [
        'schedules' => [
            [
                'shift' => 'Mañana',
                'name' => 'Turno Mañana Regular',
                'entry_time' => '07:45',
                'exit_time' => '13:15',
            ],
            [
                'shift' => 'Tarde',
                'name' => 'Turno Tarde Regular',
                'entry_time' => '13:45',
                'exit_time' => '19:00',
            ],
        ],
    ];

    $response = $this->actingAs($this->admin)
        ->put(route('attendance.settings.schedules.update'), $payload);

    $response->assertRedirect();

    // Verificar persistencia en base de datos PostgreSQL
    $this->assertDatabaseHas('attendance_schedules', [
        'institution_id' => $this->institution->id,
        'shift' => 'Mañana',
        'entry_time' => '07:45:00',
        'exit_time' => '13:15:00',
        'tolerance_minutes' => 0,
    ]);

    $this->assertDatabaseHas('attendance_schedules', [
        'institution_id' => $this->institution->id,
        'shift' => 'Tarde',
        'entry_time' => '13:45:00',
        'exit_time' => '19:00:00',
        'tolerance_minutes' => 0,
    ]);
});

test('la validación rechaza formatos de hora inválidos en configuración de horarios', function () {
    $payload = [
        'schedules' => [
            [
                'shift' => 'Mañana',
                'entry_time' => 'hora-invalida',
                'exit_time' => '13:30',
            ],
        ],
    ];

    $response = $this->actingAs($this->admin)
        ->put(route('attendance.settings.schedules.update'), $payload);

    $response->assertSessionHasErrors('schedules.0.entry_time');
});

test('un usuario autenticado puede consultar el calendario escolar con sus días lectivos, feriados y suspensiones', function () {
    AcademicCalendarDay::create([
        'institution_id' => $this->institution->id,
        'date' => '2026-05-01',
        'day_type' => 'holiday',
        'title' => 'Día del Trabajo',
        'description' => 'Feriado nacional no laborable',
        'is_attendance_allowed' => false,
    ]);

    AcademicCalendarDay::create([
        'institution_id' => $this->institution->id,
        'date' => '2026-06-15',
        'day_type' => 'suspension',
        'title' => 'Jornada Pedagógica Docente',
        'description' => 'Suspensión de labores escolares',
        'is_attendance_allowed' => false,
    ]);

    AcademicCalendarDay::create([
        'institution_id' => $this->institution->id,
        'date' => '2026-06-20',
        'day_type' => 'school_day',
        'title' => 'Recuperación de Clases',
        'description' => 'Día lectivo extraordinario',
        'is_attendance_allowed' => true,
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('attendance.settings'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('attendance/Settings')
        ->has('calendar_days', 3)
        ->where('calendar_days.0.date', '2026-05-01')
        ->where('calendar_days.0.day_type', 'holiday')
        ->where('calendar_days.0.is_attendance_allowed', false)
        ->where('calendar_days.1.date', '2026-06-15')
        ->where('calendar_days.1.day_type', 'suspension')
        ->where('calendar_days.1.is_attendance_allowed', false)
        ->where('calendar_days.2.date', '2026-06-20')
        ->where('calendar_days.2.day_type', 'school_day')
        ->where('calendar_days.2.is_attendance_allowed', true)
    );
});

test('se puede registrar o actualizar un feriado o suspensión en el calendario escolar de PostgreSQL', function () {
    $payload = [
        'date' => '2026-07-06',
        'day_type' => 'holiday',
        'title' => 'Día del Maestro',
        'description' => 'Homenaje a los docentes peruanos',
    ];

    $response = $this->actingAs($this->admin)
        ->post(route('attendance.settings.calendar-days.store'), $payload);

    $response->assertRedirect();

    $this->assertDatabaseHas('academic_calendar_days', [
        'institution_id' => $this->institution->id,
        'date' => '2026-07-06',
        'day_type' => 'holiday',
        'title' => 'Día del Maestro',
        'is_attendance_allowed' => false,
    ]);
});

test('se puede registrar un día lectivo extraordinario con asistencia habilitada', function () {
    $payload = [
        'date' => '2026-08-01',
        'day_type' => 'school_day',
        'title' => 'Recuperación Sábado',
        'description' => 'Recuperación de horas lectivas',
    ];

    $response = $this->actingAs($this->admin)
        ->post(route('attendance.settings.calendar-days.store'), $payload);

    $response->assertRedirect();

    $this->assertDatabaseHas('academic_calendar_days', [
        'institution_id' => $this->institution->id,
        'date' => '2026-08-01',
        'day_type' => 'school_day',
        'title' => 'Recuperación Sábado',
        'is_attendance_allowed' => true,
    ]);
});

test('se puede eliminar un día del calendario escolar en PostgreSQL', function () {
    $day = AcademicCalendarDay::create([
        'institution_id' => $this->institution->id,
        'date' => '2026-09-23',
        'day_type' => 'suspension',
        'title' => 'Día de la Primavera',
        'is_attendance_allowed' => false,
    ]);

    $response = $this->actingAs($this->admin)
        ->delete(route('attendance.settings.calendar-days.destroy', $day));

    $response->assertRedirect();

    $this->assertDatabaseMissing('academic_calendar_days', [
        'id' => $day->id,
    ]);
});

test('un usuario autenticado puede consultar los terminales QR autorizados en configuración', function () {
    AttendanceTerminal::create([
        'institution_id' => $this->institution->id,
        'name' => 'Torniquete Principal Entrada',
        'code' => 'TERM-ING-01',
        'mode' => 'entry',
        'location' => 'Puerta Principal',
        'is_active' => true,
    ]);

    AttendanceTerminal::create([
        'institution_id' => $this->institution->id,
        'name' => 'Lector Puerta Salida Secundaria',
        'code' => 'TERM-SAL-01',
        'mode' => 'exit',
        'location' => 'Puerta 2',
        'is_active' => true,
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('attendance.settings'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('attendance/Settings')
        ->has('terminals', 2)
        ->where('terminals.0.code', 'TERM-SAL-01')
        ->where('terminals.0.mode', 'exit')
        ->where('terminals.0.mode_label', 'Salida')
        ->where('terminals.1.code', 'TERM-ING-01')
        ->where('terminals.1.mode', 'entry')
        ->where('terminals.1.mode_label', 'Ingreso')
    );
});

test('se puede registrar un terminal QR autorizado con modo exclusivo de Ingreso', function () {
    $payload = [
        'name' => 'Lector Facial y QR Puerta 1',
        'code' => 'TERM-ENT-01',
        'mode' => 'entry',
        'location' => 'Pabellón Principal',
        'device_identifier' => 'MAC-AA:BB:CC:DD:EE:FF',
        'description' => 'Lector exclusivo para ingresos matutinos',
        'is_active' => true,
    ];

    $response = $this->actingAs($this->admin)
        ->post(route('attendance.settings.terminals.store'), $payload);

    $response->assertRedirect();

    $this->assertDatabaseHas('attendance_terminals', [
        'institution_id' => $this->institution->id,
        'code' => 'TERM-ENT-01',
        'mode' => 'entry',
        'is_active' => true,
    ]);
});

test('se puede registrar un terminal QR autorizado con modo exclusivo de Salida', function () {
    $payload = [
        'name' => 'Torniquete Puerta Vehicular Salida',
        'code' => 'TERM-SAL-02',
        'mode' => 'exit',
        'location' => 'Salida Sur',
        'is_active' => true,
    ];

    $response = $this->actingAs($this->admin)
        ->post(route('attendance.settings.terminals.store'), $payload);

    $response->assertRedirect();

    $this->assertDatabaseHas('attendance_terminals', [
        'institution_id' => $this->institution->id,
        'code' => 'TERM-SAL-02',
        'mode' => 'exit',
        'is_active' => true,
    ]);
});

test('la validación rechaza registrar un terminal con modo que no sea exclusivamente entry o exit', function () {
    $payload = [
        'name' => 'Terminal Modo Inválido',
        'code' => 'TERM-INV-01',
        'mode' => 'mixto_no_permitido',
    ];

    $response = $this->actingAs($this->admin)
        ->post(route('attendance.settings.terminals.store'), $payload);

    $response->assertSessionHasErrors('mode');

    $this->assertDatabaseMissing('attendance_terminals', [
        'code' => 'TERM-INV-01',
    ]);
});

test('se puede actualizar la configuración y el modo de un terminal QR en PostgreSQL', function () {
    $terminal = AttendanceTerminal::create([
        'institution_id' => $this->institution->id,
        'name' => 'Lector Antiguo',
        'code' => 'TERM-MOD-01',
        'mode' => 'entry',
        'is_active' => true,
    ]);

    $payload = [
        'name' => 'Lector Reconfigurado para Salidas',
        'code' => 'TERM-MOD-01',
        'mode' => 'exit',
        'location' => 'Nueva Puerta de Salida',
        'is_active' => true,
    ];

    $response = $this->actingAs($this->admin)
        ->put(route('attendance.settings.terminals.update', $terminal), $payload);

    $response->assertRedirect();

    $this->assertDatabaseHas('attendance_terminals', [
        'id' => $terminal->id,
        'name' => 'Lector Reconfigurado para Salidas',
        'mode' => 'exit',
    ]);
});

test('se puede autorizar o inhabilitar un terminal QR mediante toggle-status', function () {
    $terminal = AttendanceTerminal::create([
        'institution_id' => $this->institution->id,
        'name' => 'Lector de Prueba',
        'code' => 'TERM-TOG-01',
        'mode' => 'entry',
        'is_active' => true,
    ]);

    // Inhabilitar
    $this->actingAs($this->admin)
        ->patch(route('attendance.settings.terminals.toggle-status', $terminal));

    expect($terminal->fresh()->is_active)->toBeFalse();

    // Reautorizar
    $this->actingAs($this->admin)
        ->patch(route('attendance.settings.terminals.toggle-status', $terminal));

    expect($terminal->fresh()->is_active)->toBeTrue();
});

test('se puede eliminar un terminal QR de PostgreSQL', function () {
    $terminal = AttendanceTerminal::create([
        'institution_id' => $this->institution->id,
        'name' => 'Lector Obsoleto',
        'code' => 'TERM-DEL-01',
        'mode' => 'exit',
        'is_active' => false,
    ]);

    $response = $this->actingAs($this->admin)
        ->delete(route('attendance.settings.terminals.destroy', $terminal));

    $response->assertRedirect();

    $this->assertDatabaseMissing('attendance_terminals', [
        'id' => $terminal->id,
    ]);
});
