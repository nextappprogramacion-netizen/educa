<?php

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Institution;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;

beforeEach(function () {
    $this->institution = Institution::create([
        'name' => 'Colegio San Martín Test',
        'code' => 'TEST-SMP',
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

    $this->gradePrimaria = Grade::create([
        'institution_id' => $this->institution->id,
        'name' => '1° Primaria',
        'level' => 'Primaria',
        'order_index' => 1,
    ]);

    $this->sectionA = Section::create([
        'institution_id' => $this->institution->id,
        'grade_id' => $this->gradePrimaria->id,
        'name' => 'A',
        'shift' => 'Mañana',
    ]);

    $this->sectionB = Section::create([
        'institution_id' => $this->institution->id,
        'grade_id' => $this->gradePrimaria->id,
        'name' => 'B',
        'shift' => 'Tarde',
    ]);

    $this->student = Student::create([
        'institution_id' => $this->institution->id,
        'code' => 'EST-1001',
        'first_name' => 'Mateo Benjamín',
        'last_name' => 'Álvarez Quispe',
        'document_number' => '78451230',
        'gender' => 'M',
        'birth_date' => '2019-05-12',
        'is_active' => true,
    ]);

    Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $this->student->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $this->sectionA->id,
        'status' => 'active',
    ]);
});

test('un usuario autenticado puede consultar la nómina de estudiantes', function () {
    $response = $this->actingAs($this->admin)
        ->get(route('attendance.students'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('attendance/Students')
        ->has('students', 1)
        ->where('students.0.document_number', '78451230')
        ->where('students.0.shift', 'Mañana')
    );
});

test('se puede registrar un nuevo estudiante con su grado sección y turno', function () {
    $payload = [
        'first_name' => 'Valeria Sofía',
        'last_name' => 'Díaz Navarro',
        'document_number' => '78451299',
        'code' => 'EST-1002',
        'gender' => 'F',
        'birth_date' => '2019-11-04',
        'section_id' => $this->sectionB->id,
        'photo_url' => 'https://ejemplo.com/valeria.jpg',
    ];

    $response = $this->actingAs($this->admin)
        ->post(route('attendance.students.store'), $payload);

    $response->assertRedirect();

    $this->assertDatabaseHas('students', [
        'institution_id' => $this->institution->id,
        'first_name' => 'Valeria Sofía',
        'last_name' => 'Díaz Navarro',
        'document_number' => '78451299',
        'is_active' => true,
    ]);

    $newStudent = Student::where('document_number', '78451299')->first();
    expect($newStudent->currentEnrollment)->not->toBeNull();
    expect($newStudent->currentEnrollment->section_id)->toBe($this->sectionB->id);
    expect($newStudent->currentEnrollment->section->shift)->toBe('Tarde');
});

test('falla la validación si el DNI no contiene exactamente 8 dígitos', function () {
    $payload = [
        'first_name' => 'Error',
        'last_name' => 'Dni',
        'document_number' => '12345', // Inválido
        'section_id' => $this->sectionA->id,
    ];

    $response = $this->actingAs($this->admin)
        ->post(route('attendance.students.store'), $payload);

    $response->assertSessionHasErrors('document_number');
});

test('falla la validación si el DNI ya existe en la misma institución', function () {
    $payload = [
        'first_name' => 'Duplicado',
        'last_name' => 'Alumno',
        'document_number' => '78451230', // Ya existe
        'section_id' => $this->sectionA->id,
    ];

    $response = $this->actingAs($this->admin)
        ->post(route('attendance.students.store'), $payload);

    $response->assertSessionHasErrors('document_number');
});

test('se puede editar la información y reasignar la sección de un estudiante', function () {
    $payload = [
        'first_name' => 'Mateo Benjamín Modificado',
        'last_name' => 'Álvarez Modificado',
        'document_number' => '78451230',
        'code' => 'EST-1001-MOD',
        'gender' => 'M',
        'birth_date' => '2019-05-12',
        'section_id' => $this->sectionB->id, // Cambiado al turno tarde
    ];

    $response = $this->actingAs($this->admin)
        ->put(route('attendance.students.update', $this->student), $payload);

    $response->assertRedirect();

    $this->assertDatabaseHas('students', [
        'id' => $this->student->id,
        'first_name' => 'Mateo Benjamín Modificado',
        'last_name' => 'Álvarez Modificado',
    ]);

    $this->student->refresh();
    expect($this->student->currentEnrollment->section_id)->toBe($this->sectionB->id);
    expect($this->student->currentEnrollment->section->shift)->toBe('Tarde');
});

test('se puede desactivar y reactivar a un estudiante conservando su historial pero excluyéndolo de asistencia activa', function () {
    expect($this->student->is_active)->toBeTrue();
    expect(Student::active()->where('id', $this->student->id)->exists())->toBeTrue();

    // Desactivar estudiante
    $response = $this->actingAs($this->admin)
        ->patch(route('attendance.students.toggle-status', $this->student));

    $response->assertRedirect();
    $this->student->refresh();
    expect($this->student->is_active)->toBeFalse();

    // Verificar que NO aparece en la consulta de estudiantes activos para asistencia
    expect(Student::active()->where('id', $this->student->id)->exists())->toBeFalse();

    // Verificar que conserva todo su historial de matrículas y datos previos intactos
    expect($this->student->enrollments()->count())->toBe(1);
    expect($this->student->currentEnrollment)->not->toBeNull();
    expect($this->student->currentEnrollment->status)->toBe('inactive');

    // Reactivar estudiante
    $response2 = $this->actingAs($this->admin)
        ->patch(route('attendance.students.toggle-status', $this->student));

    $response2->assertRedirect();
    $this->student->refresh();
    expect($this->student->is_active)->toBeTrue();

    // Verificar que vuelve a figurar como activo para asistencia
    expect(Student::active()->where('id', $this->student->id)->exists())->toBeTrue();
    expect($this->student->currentEnrollment->status)->toBe('active');
});

test('aislamiento multi-tenant evita que un usuario modifique estudiantes de otra institución', function () {
    $otherInstitution = Institution::create([
        'name' => 'Colegio Foráneo',
        'code' => 'FORANEO-01',
        'is_active' => true,
    ]);

    $foreignUser = User::factory()->create([
        'institution_id' => $otherInstitution->id,
        'role' => 'admin',
    ]);

    $response = $this->actingAs($foreignUser)
        ->put(route('attendance.students.update', $this->student), [
            'first_name' => 'Hack',
            'last_name' => 'Test',
            'document_number' => '78451230',
            'section_id' => $this->sectionA->id,
        ]);

    $response->assertForbidden();
});

test('falla la validación si el código de estudiante ya existe en la misma institución', function () {
    $payload = [
        'first_name' => 'Código Duplicado',
        'last_name' => 'Prueba',
        'document_number' => '99887766',
        'code' => 'EST-1001', // Ya pertenece a $this->student
        'section_id' => $this->sectionA->id,
    ];

    $response = $this->actingAs($this->admin)
        ->post(route('attendance.students.store'), $payload);

    $response->assertSessionHasErrors('code');
});

test('dos instituciones diferentes pueden registrar estudiantes con el mismo DNI y código sin colisión', function () {
    $otherInstitution = Institution::create([
        'name' => 'Segundo Colegio',
        'code' => 'COL-002',
        'is_active' => true,
    ]);

    $otherAdmin = User::factory()->create([
        'institution_id' => $otherInstitution->id,
        'role' => 'admin',
    ]);

    $grade = Grade::create([
        'institution_id' => $otherInstitution->id,
        'name' => '1° Primaria',
        'level' => 'Primaria',
        'order_index' => 1,
    ]);

    $section = Section::create([
        'institution_id' => $otherInstitution->id,
        'grade_id' => $grade->id,
        'name' => 'A',
        'shift' => 'Mañana',
    ]);

    // Intentar registrar el mismo DNI '78451230' y código 'EST-1001' que existen en la primera institución
    $payload = [
        'first_name' => 'Alumno de Otra Institución',
        'last_name' => 'Gómez',
        'document_number' => '78451230', // Mismo DNI que $this->student
        'code' => 'EST-1001',            // Mismo código que $this->student
        'section_id' => $section->id,
    ];

    $response = $this->actingAs($otherAdmin)
        ->post(route('attendance.students.store'), $payload);

    $response->assertRedirect();

    $this->assertDatabaseHas('students', [
        'institution_id' => $otherInstitution->id,
        'document_number' => '78451230',
        'code' => 'EST-1001',
    ]);

    $this->assertDatabaseHas('students', [
        'institution_id' => $this->institution->id,
        'document_number' => '78451230',
        'code' => 'EST-1001',
    ]);
});

test('los estudiantes registrados se guardan permanentemente y aparecen en la tabla al recargar la página', function () {
    $payload = [
        'first_name' => 'Camila Andrea',
        'last_name' => 'Morales Vega',
        'document_number' => '71234567',
        'code' => 'EST-9999',
        'gender' => 'F',
        'birth_date' => '2018-09-20',
        'section_id' => $this->sectionA->id,
        'photo_url' => 'https://ejemplo.com/camila.jpg',
    ];

    // Registrar estudiante mediante POST
    $createResponse = $this->actingAs($this->admin)
        ->post(route('attendance.students.store'), $payload);

    $createResponse->assertRedirect();

    // Comprobar persistencia física en PostgreSQL
    $this->assertDatabaseHas('students', [
        'institution_id' => $this->institution->id,
        'first_name' => 'Camila Andrea',
        'last_name' => 'Morales Vega',
        'document_number' => '71234567',
        'code' => 'EST-9999',
        'gender' => 'F',
        'is_active' => true,
    ]);

    $student = Student::where('document_number', '71234567')->first();
    expect($student)->not->toBeNull();
    $this->assertDatabaseHas('enrollments', [
        'institution_id' => $this->institution->id,
        'student_id' => $student->id,
        'section_id' => $this->sectionA->id,
        'status' => 'active',
    ]);

    // Simular recarga de página (GET /attendance/students)
    $reloadResponse = $this->actingAs($this->admin)
        ->get(route('attendance.students'));

    $reloadResponse->assertOk();
    $reloadResponse->assertInertia(fn ($page) => $page
        ->component('attendance/Students')
        ->has('students', 2)
        ->where('students.0.document_number', '71234567')
        ->where('students.0.code', 'EST-9999')
        ->where('students.0.classroom', '1° Primaria - A (Mañana)')
    );
});

test('búsqueda de estudiantes por nombre apellido DNI o código', function () {
    // Buscar por nombre
    $resName = $this->actingAs($this->admin)->get(route('attendance.students', ['search' => 'Mateo']));
    $resName->assertOk();
    $resName->assertInertia(fn ($page) => $page->has('students', 1)->where('students.0.first_name', 'Mateo Benjamín'));

    // Buscar por apellido
    $resLastName = $this->actingAs($this->admin)->get(route('attendance.students', ['search' => 'Álvarez']));
    $resLastName->assertInertia(fn ($page) => $page->has('students', 1)->where('students.0.last_name', 'Álvarez Quispe'));

    // Buscar por DNI
    $resDni = $this->actingAs($this->admin)->get(route('attendance.students', ['search' => '78451230']));
    $resDni->assertInertia(fn ($page) => $page->has('students', 1)->where('students.0.document_number', '78451230'));

    // Buscar por código
    $resCode = $this->actingAs($this->admin)->get(route('attendance.students', ['search' => 'EST-1001']));
    $resCode->assertInertia(fn ($page) => $page->has('students', 1)->where('students.0.code', 'EST-1001'));

    // Búsqueda que no coincide
    $resNone = $this->actingAs($this->admin)->get(route('attendance.students', ['search' => 'Inexistente999']));
    $resNone->assertInertia(fn ($page) => $page->has('students', 0));
});

test('filtrado de estudiantes por nivel grado sección y estado en PostgreSQL', function () {
    // Crear un segundo estudiante en Secundaria, Sección B e inactivo
    $gradeSec = Grade::create([
        'institution_id' => $this->institution->id,
        'name' => '1° Secundaria',
        'level' => 'Secundaria',
        'order_index' => 5,
    ]);

    $sectionSec = Section::create([
        'institution_id' => $this->institution->id,
        'grade_id' => $gradeSec->id,
        'name' => 'B',
        'shift' => 'Tarde',
    ]);

    $studentSec = Student::create([
        'institution_id' => $this->institution->id,
        'code' => 'EST-5001',
        'first_name' => 'Rodrigo',
        'last_name' => 'Castillo',
        'document_number' => '44556677',
        'gender' => 'M',
        'is_active' => false, // Inactivo
    ]);

    Enrollment::create([
        'institution_id' => $this->institution->id,
        'student_id' => $studentSec->id,
        'academic_year_id' => $this->academicYear->id,
        'section_id' => $sectionSec->id,
        'status' => 'inactive',
    ]);

    // Filtrar por Nivel Primaria (debe traer solo al alumno de Primaria)
    $resLevel = $this->actingAs($this->admin)->get(route('attendance.students', ['level' => 'Primaria']));
    $resLevel->assertInertia(fn ($page) => $page->has('students', 1)->where('students.0.level', 'Primaria'));

    // Filtrar por Grado 1° Secundaria
    $resGrade = $this->actingAs($this->admin)->get(route('attendance.students', ['grade_id' => $gradeSec->id]));
    $resGrade->assertInertia(fn ($page) => $page->has('students', 1)->where('students.0.code', 'EST-5001'));

    // Filtrar por Sección A
    $resSecA = $this->actingAs($this->admin)->get(route('attendance.students', ['section_id' => $this->sectionA->id]));
    $resSecA->assertInertia(fn ($page) => $page->has('students', 1)->where('students.0.document_number', '78451230'));

    // Filtrar por Estado: Solo Activos
    $resActive = $this->actingAs($this->admin)->get(route('attendance.students', ['status' => 'active']));
    $resActive->assertInertia(fn ($page) => $page->has('students', 1)->where('students.0.is_active', true));

    // Filtrar por Estado: Solo Inactivos
    $resInactive = $this->actingAs($this->admin)->get(route('attendance.students', ['status' => 'inactive']));
    $resInactive->assertInertia(fn ($page) => $page->has('students', 1)->where('students.0.is_active', false));
});

test('la vista de estudiantes presenta un estado vacío cuando no existen registros en la institución', function () {
    // Eliminar el estudiante de prueba creado en beforeEach
    $this->student->enrollments()->delete();
    $this->student->delete();

    $response = $this->actingAs($this->admin)
        ->get(route('attendance.students'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('attendance/Students')
        ->has('students', 0)
        ->where('stats.total', 0)
        ->where('stats.active', 0)
        ->where('stats.inactive', 0)
    );
});
