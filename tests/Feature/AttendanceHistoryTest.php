<?php

use App\Models\AcademicCalendarDay;
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
    $this->institution = Institution::create([
        'name' => 'Colegio San Martín History Test',
        'code' => 'HIST-SMP',
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

    $this->grade1 = Grade::create([
        'institution_id' => $this->institution->id,
        'name' => '1° Primaria',
        'level' => 'Primaria',
        'order_index' => 1,
    ]);

    $this->grade2 = Grade::create([
        'institution_id' => $this->institution->id,
        'name' => '2° Primaria',
        'level' => 'Primaria',
        'order_index' => 2,
    ]);

    $this->sectionA = Section::create([
        'institution_id' => $this->institution->id,
        'grade_id' => $this->grade1->id,
        'name' => 'A',
        'shift' => 'Mañana',
    ]);

    $this->sectionB = Section::create([
        'institution_id' => $this->institution->id,
        'grade_id' => $this->grade1->id,
        'name' => 'B',
        'shift' => 'Mañana',
    ]);

    $this->sectionGrade2 = Section::create([
        'institution_id' => $this->institution->id,
        'grade_id' => $this->grade2->id,
        'name' => 'A',
        'shift' => 'Mañana',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('un usuario autenticado puede acceder al historial de asistencia conectado a PostgreSQL', function () {
    $response = $this->actingAs($this->admin)
        ->get(route('attendance.history'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('attendance/History')
        ->has('records')
        ->has('grades', 2)
        ->has('sections', 3)
        ->has('filters')
        ->has('stats')
    );
});

test('el historial muestra por estudiante la fecha, hora de ingreso, hora de salida, puntualidad y estado de asistencia', function () {
    $student = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Mateo',
        'last_name' => 'Álvarez Quispe',
        'document_number' => '78451230',
        'is_active' => true,
    ]);

    $enrollment = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->sectionA->id,
        'status' => 'active',
    ]);

    $record = AttendanceRecord::create([
        'institution_id' => $this->institution->id,
        'enrollment_id' => $enrollment->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->sectionA->id,
        'date' => '2026-11-15',
        'shift' => 'Mañana',
        'session_type' => 'daily',
        'status' => 'present',
        'entry_time' => '07:48:15',
        'exit_time' => '13:35:10',
        'exit_type' => 'regular',
        'is_early_exit' => false,
        'registered_by' => $this->admin->id,
        'source' => 'qr_terminal',
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('attendance.history'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('attendance/History')
        ->has('records', 1)
        ->where('records.0.id', $record->id)
        ->where('records.0.date', '2026-11-15')
        ->where('records.0.date_formatted', '15/11/2026')
        ->where('records.0.entry_time', '07:48')
        ->where('records.0.exit_time', '13:35')
        ->where('records.0.punctuality', 'Puntual')
        ->where('records.0.status', 'present')
        ->where('records.0.status_label', 'Presente')
        ->where('records.0.exit_type', 'regular')
        ->where('records.0.student.full_name', 'Álvarez Quispe, Mateo')
        ->where('records.0.classroom.label', '1° Primaria - A')
    );
});

test('el historial permite filtrar registros por fecha especifica', function () {
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
        'section_id' => $this->sectionA->id,
        'status' => 'active',
    ]);

    // Registro día 14
    AttendanceRecord::create([
        'institution_id' => $this->institution->id,
        'enrollment_id' => $enrollment->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->sectionA->id,
        'date' => '2026-11-14',
        'shift' => 'Mañana',
        'status' => 'present',
        'entry_time' => '07:50:00',
        'registered_by' => $this->admin->id,
    ]);

    // Registro día 15
    AttendanceRecord::create([
        'institution_id' => $this->institution->id,
        'enrollment_id' => $enrollment->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->sectionA->id,
        'date' => '2026-11-15',
        'shift' => 'Mañana',
        'status' => 'late',
        'entry_time' => '08:10:00',
        'registered_by' => $this->admin->id,
    ]);

    // Filtrar exclusivamente fecha 2026-11-15
    $response = $this->actingAs($this->admin)
        ->get(route('attendance.history', ['date' => '2026-11-15']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('attendance/History')
        ->has('records', 1)
        ->where('records.0.date', '2026-11-15')
        ->where('records.0.status', 'late')
        ->where('records.0.punctuality', 'Tardanza')
        ->where('filters.date', '2026-11-15')
    );
});

test('el historial permite filtrar registros por grado escolar', function () {
    $student1 = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Lucas',
        'last_name' => '1° Grado',
        'is_active' => true,
    ]);

    $student2 = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Sofía',
        'last_name' => '2° Grado',
        'is_active' => true,
    ]);

    $enrollment1 = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student1->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->sectionA->id, // 1° Primaria
        'status' => 'active',
    ]);

    $enrollment2 = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student2->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->sectionGrade2->id, // 2° Primaria
        'status' => 'active',
    ]);

    AttendanceRecord::create([
        'institution_id' => $this->institution->id,
        'enrollment_id' => $enrollment1->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->sectionA->id,
        'date' => '2026-11-15',
        'shift' => 'Mañana',
        'status' => 'present',
        'registered_by' => $this->admin->id,
    ]);

    AttendanceRecord::create([
        'institution_id' => $this->institution->id,
        'enrollment_id' => $enrollment2->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->sectionGrade2->id,
        'date' => '2026-11-15',
        'shift' => 'Mañana',
        'status' => 'present',
        'registered_by' => $this->admin->id,
    ]);

    // Filtrar por 2° Primaria
    $response = $this->actingAs($this->admin)
        ->get(route('attendance.history', ['grade_id' => $this->grade2->id]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('attendance/History')
        ->has('records', 1)
        ->where('records.0.student.first_name', 'Sofía')
        ->where('records.0.classroom.grade_name', '2° Primaria')
        ->where('filters.grade_id', $this->grade2->id)
    );
});

test('el historial permite filtrar registros por seccion', function () {
    $studentA = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Alumno',
        'last_name' => 'Sección A',
        'is_active' => true,
    ]);

    $studentB = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Alumno',
        'last_name' => 'Sección B',
        'is_active' => true,
    ]);

    $enrollmentA = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $studentA->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->sectionA->id,
        'status' => 'active',
    ]);

    $enrollmentB = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $studentB->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->sectionB->id,
        'status' => 'active',
    ]);

    AttendanceRecord::create([
        'institution_id' => $this->institution->id,
        'enrollment_id' => $enrollmentA->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->sectionA->id,
        'date' => '2026-11-15',
        'shift' => 'Mañana',
        'status' => 'present',
        'registered_by' => $this->admin->id,
    ]);

    AttendanceRecord::create([
        'institution_id' => $this->institution->id,
        'enrollment_id' => $enrollmentB->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->sectionB->id,
        'date' => '2026-11-15',
        'shift' => 'Mañana',
        'status' => 'present',
        'registered_by' => $this->admin->id,
    ]);

    // Filtrar exclusivamente Sección B
    $response = $this->actingAs($this->admin)
        ->get(route('attendance.history', ['section_id' => $this->sectionB->id]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('attendance/History')
        ->has('records', 1)
        ->where('records.0.student.last_name', 'Sección B')
        ->where('records.0.classroom.section_name', 'B')
        ->where('filters.section_id', $this->sectionB->id)
    );
});

test('el historial presenta un estado vacio cuando no existen datos para los filtros', function () {
    // Buscar en fecha sin registros
    $response = $this->actingAs($this->admin)
        ->get(route('attendance.history', ['date' => '2026-01-01']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('attendance/History')
        ->has('records', 0)
        ->where('stats.total', 0)
    );
});

test('el historial distingue salidas anticipadas con motivo y salidas regulares', function () {
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
        'section_id' => $this->sectionA->id,
        'status' => 'active',
    ]);

    AttendanceRecord::create([
        'institution_id' => $this->institution->id,
        'enrollment_id' => $enrollment->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->sectionA->id,
        'date' => '2026-11-15',
        'shift' => 'Mañana',
        'status' => 'late',
        'entry_time' => '08:05:00',
        'exit_time' => '11:30:00',
        'exit_type' => 'early',
        'is_early_exit' => true,
        'early_exit_reason' => 'Retiro médico justificado con apoderado',
        'early_exit_authorized_by' => $this->admin->id,
        'registered_by' => $this->admin->id,
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('attendance.history', ['date' => '2026-11-15']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('attendance/History')
        ->has('records', 1)
        ->where('records.0.status', 'late')
        ->where('records.0.punctuality', 'Tardanza')
        ->where('records.0.exit_type', 'early')
        ->where('records.0.exit_type_label', 'Salida Anticipada')
        ->where('records.0.is_early_exit', true)
        ->where('records.0.early_exit_reason', 'Retiro médico justificado con apoderado')
        ->where('records.0.early_exit_authorized_by', $this->admin->name)
        ->where('stats.early_exit', 1)
        ->where('stats.late', 1)
    );
});

test('el historial detecta estudiantes sin ingreso registrado mostrando Sin registro antes del cierre de jornada', function () {
    Carbon::setTestNow('2026-11-16 09:00:00'); // Lunes, 9:00 AM (turno Mañana finaliza a las 13:30)

    $studentRegistered = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Mateo',
        'last_name' => 'Álvarez',
        'is_active' => true,
    ]);

    $studentUnregistered = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Valeria',
        'last_name' => 'Mendoza',
        'is_active' => true,
    ]);

    $enrollment1 = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $studentRegistered->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->sectionA->id,
        'status' => 'active',
    ]);

    $enrollment2 = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $studentUnregistered->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->sectionA->id,
        'status' => 'active',
    ]);

    AttendanceRecord::create([
        'institution_id' => $this->institution->id,
        'enrollment_id' => $enrollment1->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->sectionA->id,
        'date' => '2026-11-16',
        'shift' => 'Mañana',
        'status' => 'present',
        'entry_time' => '07:55:00',
        'registered_by' => $this->admin->id,
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('attendance.history', ['date' => '2026-11-16']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('attendance/History')
        ->has('records', 2)
        ->where('stats.total', 2)
        ->where('stats.punctual', 1)
        ->where('stats.unregistered', 1)
        ->where('stats.can_classify_absent_count', 0)
        ->where('records.1.student.first_name', 'Valeria')
        ->where('records.1.status', 'unregistered')
        ->where('records.1.status_label', 'Sin registro')
        ->where('records.1.punctuality', 'Sin registro')
        ->where('records.1.can_classify_absent', false)
    );
});

test('antes del cierre de jornada no se permite clasificar a estudiantes sin registro como ausentes', function () {
    Carbon::setTestNow('2026-11-16 10:15:00'); // Antes de las 13:30

    $student = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Carlos',
        'last_name' => 'Ramos',
        'is_active' => true,
    ]);

    $enrollment = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->sectionA->id,
        'status' => 'active',
    ]);

    $response = $this->actingAs($this->admin)
        ->from(route('attendance.history', ['date' => '2026-11-16']))
        ->post(route('attendance.history.classify-absent'), [
            'date' => '2026-11-16',
            'enrollment_id' => $enrollment->id,
        ]);

    $response->assertRedirect(route('attendance.history', ['date' => '2026-11-16']));
    $response->assertSessionHasErrors('shift_error');

    // Confirmar que no se guardó registro de ausencia en PostgreSQL
    expect(AttendanceRecord::where('enrollment_id', $enrollment->id)->whereDate('date', '2026-11-16')->count())->toBe(0);
});

test('despues del cierre de jornada se permite clasificar estudiantes sin registro como ausentes en PostgreSQL', function () {
    Carbon::setTestNow('2026-11-16 14:00:00'); // Después de las 13:30 (jornada cerrada)

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
        'section_id' => $this->sectionA->id,
        'status' => 'active',
    ]);

    // Verificar que antes de clasificar, can_classify_absent sea true y muestre Sin registro
    $getCheck = $this->actingAs($this->admin)
        ->get(route('attendance.history', ['date' => '2026-11-16']));

    $getCheck->assertOk();
    $getCheck->assertInertia(fn ($page) => $page
        ->component('attendance/History')
        ->where('records.0.status', 'unregistered')
        ->where('records.0.status_label', 'Sin registro')
        ->where('records.0.punctuality', 'Sin registro')
        ->where('records.0.can_classify_absent', true)
        ->where('stats.can_classify_absent_count', 1)
    );

    // Clasificar como ausente tras el cierre de jornada
    $response = $this->actingAs($this->admin)
        ->from(route('attendance.history', ['date' => '2026-11-16']))
        ->post(route('attendance.history.classify-absent'), [
            'date' => '2026-11-16',
            'enrollment_id' => $enrollment->id,
        ]);

    $response->assertRedirect(route('attendance.history', ['date' => '2026-11-16']));
    $response->assertSessionHas('success');

    // Comprobar persistencia en PostgreSQL con status = 'absent' y entry_time = null
    $record = AttendanceRecord::where('enrollment_id', $enrollment->id)
        ->whereDate('date', '2026-11-16')
        ->first();

    expect($record)->not->toBeNull();
    expect($record->status)->toBe('absent');
    expect($record->entry_time)->toBeNull();
    expect($record->exit_time)->toBeNull();

    // Comprobar que en el historial ahora figura con Estado Asistencia 'Falta'
    $getResponse = $this->actingAs($this->admin)
        ->get(route('attendance.history', ['date' => '2026-11-16']));

    $getResponse->assertInertia(fn ($page) => $page
        ->where('records.0.status', 'absent')
        ->where('records.0.status_label', 'Falta')
        ->where('records.0.punctuality', 'Sin registro')
        ->where('stats.absent', 1)
        ->where('stats.unregistered', 0)
    );
});

test('clasificar estudiantes sin registro respeta el calendario escolar y rechaza ausencias en feriados o suspensiones', function () {
    Carbon::setTestNow('2026-11-16 15:00:00');

    // Registrar feriado escolar en el calendario de PostgreSQL
    AcademicCalendarDay::create([
        'institution_id' => $this->institution->id,
        'academic_year_id' => $this->academicYear->id,
        'date' => '2026-11-16',
        'day_type' => 'holiday',
        'title' => 'Día Festivo Institucional',
        'is_attendance_allowed' => false,
    ]);

    $student = Student::factory()->create([
        'institution_id' => $this->institution->id,
        'first_name' => 'Diego',
        'last_name' => 'Paredes',
        'is_active' => true,
    ]);

    $enrollment = Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->sectionA->id,
        'status' => 'active',
    ]);

    // Comprobar que la vista reconoce que es feriado y no computa alumnos sin registro
    $getCheck = $this->actingAs($this->admin)
        ->get(route('attendance.history', ['date' => '2026-11-16']));

    $getCheck->assertOk();
    $getCheck->assertInertia(fn ($page) => $page
        ->where('calendar_day.is_school_day', false)
        ->where('calendar_day.day_type', 'holiday')
        ->where('stats.unregistered', 0)
    );

    // Intentar clasificar como ausente en día de feriado
    $response = $this->actingAs($this->admin)
        ->from(route('attendance.history', ['date' => '2026-11-16']))
        ->post(route('attendance.history.classify-absent'), [
            'date' => '2026-11-16',
            'enrollment_id' => $enrollment->id,
        ]);

    $response->assertRedirect(route('attendance.history', ['date' => '2026-11-16']));
    $response->assertSessionHasErrors('calendar');

    // Verificar que no se guardó ningún registro
    expect(AttendanceRecord::where('enrollment_id', $enrollment->id)->count())->toBe(0);
});

test('permite clasificar masivamente a todos los estudiantes sin registro como ausentes tras el cierre de jornada', function () {
    Carbon::setTestNow('2026-11-16 14:30:00'); // Jornada cerrada

    $student1 = Student::factory()->create(['institution_id' => $this->institution->id, 'first_name' => 'Alumno 1', 'is_active' => true]);
    $student2 = Student::factory()->create(['institution_id' => $this->institution->id, 'first_name' => 'Alumno 2', 'is_active' => true]);
    $student3 = Student::factory()->create(['institution_id' => $this->institution->id, 'first_name' => 'Alumno 3', 'is_active' => true]);

    $enr1 = Enrollment::create(['institution_id' => $this->institution->id, 'student_id' => $student1->id, 'academic_year_id' => $this->academicYear->id, 'section_id' => $this->sectionA->id, 'status' => 'active']);
    $enr2 = Enrollment::create(['institution_id' => $this->institution->id, 'student_id' => $student2->id, 'academic_year_id' => $this->academicYear->id, 'section_id' => $this->sectionA->id, 'status' => 'active']);
    $enr3 = Enrollment::create(['institution_id' => $this->institution->id, 'student_id' => $student3->id, 'academic_year_id' => $this->academicYear->id, 'section_id' => $this->sectionA->id, 'status' => 'active']);

    // Alumno 1 sí asistió (registró QR puntual)
    AttendanceRecord::create([
        'institution_id' => $this->institution->id,
        'enrollment_id' => $enr1->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->sectionA->id,
        'date' => '2026-11-16',
        'shift' => 'Mañana',
        'status' => 'present',
        'entry_time' => '07:50:00',
        'registered_by' => $this->admin->id,
    ]);

    // Clasificar masivamente ausencias de la fecha
    $response = $this->actingAs($this->admin)
        ->from(route('attendance.history', ['date' => '2026-11-16']))
        ->post(route('attendance.history.classify-absent'), [
            'date' => '2026-11-16',
        ]);

    $response->assertRedirect(route('attendance.history', ['date' => '2026-11-16']));
    $response->assertSessionHas('success');

    // Alumno 1 debe conservar su estado 'present'
    expect(AttendanceRecord::where('enrollment_id', $enr1->id)->value('status'))->toBe('present');

    // Alumno 2 y Alumno 3 deben haberse registrado con estado 'absent'
    expect(AttendanceRecord::where('enrollment_id', $enr2->id)->value('status'))->toBe('absent');
    expect(AttendanceRecord::where('enrollment_id', $enr3->id)->value('status'))->toBe('absent');
});
