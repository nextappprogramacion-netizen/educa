<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\StoreStudentRequest;
use App\Http\Requests\Attendance\UpdateStudentRequest;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Institution;
use App\Models\Section;
use App\Models\Student;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StudentController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $institutionId = $user->institution_id;

        if (! $institutionId) {
            $defaultInstitution = Institution::where('is_active', true)->first();
            if ($defaultInstitution) {
                $institutionId = $defaultInstitution->id;
                $user->forceFill(['institution_id' => $institutionId])->save();
            }
        }

        $search = $request->input('search');
        $level = $request->input('level');
        $gradeId = $request->input('grade_id');
        $sectionId = $request->input('section_id');
        $shift = $request->input('shift');
        $status = $request->input('status', 'all'); // all, active, inactive

        $query = Student::query()
            ->where('students.institution_id', $institutionId)
            ->with(['currentEnrollment.section.grade']);

        // 1. Filtro de búsqueda textual por nombre, apellido, nombre completo, DNI o código
        if ($search) {
            $query->where(function ($q) use ($search) {
                $driver = DB::connection()->getDriverName();
                $like = $driver === 'pgsql' ? 'ilike' : 'like';
                $term = "%{$search}%";

                $q->where('students.first_name', $like, $term)
                    ->orWhere('students.last_name', $like, $term)
                    ->orWhere('students.document_number', $like, $term)
                    ->orWhere('students.code', $like, $term);

                if ($driver === 'pgsql') {
                    $q->orWhereRaw("concat(students.first_name, ' ', students.last_name) ilike ?", [$term])
                        ->orWhereRaw("concat(students.last_name, ' ', students.first_name) ilike ?", [$term])
                        ->orWhereRaw("concat(students.last_name, ', ', students.first_name) ilike ?", [$term]);
                } else {
                    $q->orWhereRaw("(students.first_name || ' ' || students.last_name) like ?", [$term])
                        ->orWhereRaw("(students.last_name || ' ' || students.first_name) like ?", [$term]);
                }
            });
        }

        // 2. Filtro por estado activo / inactivo
        if ($status === 'active') {
            $query->where('students.is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('students.is_active', false);
        }

        // 3. Filtro por nivel (Inicial, Primaria, Secundaria)
        if ($level && $level !== 'all') {
            $query->whereHas('currentEnrollment.section.grade', function ($gq) use ($level) {
                $gq->whereRaw('LOWER(grades.level) = LOWER(?)', [$level]);
            });
        }

        // 4. Filtro por grado
        if ($gradeId && $gradeId !== 'all') {
            $query->whereHas('currentEnrollment.section', function ($sq) use ($gradeId) {
                $sq->where('sections.grade_id', $gradeId);
            });
        }

        // 5. Filtro por sección
        if ($sectionId && $sectionId !== 'all') {
            $query->whereHas('currentEnrollment', function ($eq) use ($sectionId) {
                $eq->where('enrollments.section_id', $sectionId);
            });
        }

        // 6. Filtro por turno (si aplica)
        if ($shift && $shift !== 'all') {
            $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->whereHas('currentEnrollment.section', function ($sq) use ($shift, $like) {
                $sq->where('sections.shift', $like, $shift);
            });
        }

        $students = $query->orderBy('students.last_name')
            ->orderBy('students.first_name')
            ->get()
            ->map(function (Student $student) {
                $enrollment = $student->currentEnrollment;
                $section = $enrollment?->section;
                $grade = $section?->grade;

                return [
                    'id' => $student->id,
                    'code' => $student->code ?? "EST-{$student->id}",
                    'first_name' => $student->first_name,
                    'last_name' => $student->last_name,
                    'full_name' => $student->full_name,
                    'document_number' => $student->document_number,
                    'gender' => $student->gender,
                    'birth_date' => $student->birth_date?->format('Y-m-d'),
                    'photo_url' => $student->avatar_url,
                    'is_active' => (bool) $student->is_active,
                    'section_id' => $section?->id,
                    'section_name' => $section?->name,
                    'grade_id' => $grade?->id,
                    'grade_name' => $grade?->name,
                    'level' => $grade?->level ?? 'Primaria',
                    'shift' => $section?->shift ?? 'Mañana',
                    'classroom' => $grade && $section ? "{$grade->name} - {$section->name} ({$section->shift})" : 'Sin asignar',
                ];
            });

        // Catálogo de Grados reales desde PostgreSQL
        $grades = Grade::where('institution_id', $institutionId)
            ->orderBy('order_index')
            ->orderBy('name')
            ->get(['id', 'name', 'level'])
            ->map(fn (Grade $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'level' => $g->level,
            ]);

        // Catálogo de Secciones y Grados reales para los selectores del formulario y filtros
        $sections = Section::where('sections.institution_id', $institutionId)
            ->with('grade')
            ->join('grades', 'sections.grade_id', '=', 'grades.id')
            ->orderBy('grades.order_index')
            ->orderBy('sections.name')
            ->select('sections.*')
            ->get()
            ->map(fn (Section $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'grade_id' => $s->grade_id,
                'grade_name' => $s->grade?->name ?? '',
                'level' => $s->grade?->level ?? 'Primaria',
                'shift' => $s->shift,
                'label' => "{$s->grade?->name} - {$s->name} ({$s->shift})",
            ]);

        // Niveles reales desde PostgreSQL
        $dbLevels = Grade::where('institution_id', $institutionId)
            ->select('level')
            ->distinct()
            ->pluck('level')
            ->filter()
            ->values()
            ->toArray();

        $levels = ! empty($dbLevels) ? $dbLevels : ['Inicial', 'Primaria', 'Secundaria'];

        return Inertia::render('attendance/Students', [
            'students' => $students,
            'grades' => $grades,
            'sections' => $sections,
            'levels' => $levels,
            'filters' => [
                'search' => $search ?? '',
                'level' => $level ?? 'all',
                'grade_id' => $gradeId ? (int) $gradeId : 'all',
                'section_id' => $sectionId ? (int) $sectionId : 'all',
                'shift' => $shift ?? 'all',
                'status' => $status,
            ],
            'stats' => [
                'total' => Student::where('institution_id', $institutionId)->count(),
                'active' => Student::where('institution_id', $institutionId)->where('is_active', true)->count(),
                'inactive' => Student::where('institution_id', $institutionId)->where('is_active', false)->count(),
            ],
        ]);
    }

    public function store(StoreStudentRequest $request): RedirectResponse
    {
        $user = $request->user();
        $institutionId = $user->institution_id;

        if (! $institutionId) {
            $defaultInstitution = Institution::where('is_active', true)->first();
            if ($defaultInstitution) {
                $institutionId = $defaultInstitution->id;
                $user->forceFill(['institution_id' => $institutionId])->save();
            }
        }

        DB::transaction(function () use ($request, $institutionId) {
            $code = $request->validated('code');
            if (empty($code)) {
                $next = ((int) Student::where('institution_id', $institutionId)->max('id')) + 1;
                $code = 'EST-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
                while (Student::where('institution_id', $institutionId)->where('code', $code)->exists()) {
                    $next++;
                    $code = 'EST-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
                }
            }

            $student = Student::create([
                'institution_id' => $institutionId,
                'first_name' => $request->validated('first_name'),
                'last_name' => $request->validated('last_name'),
                'document_number' => $request->validated('document_number'),
                'code' => $code,
                'gender' => $request->validated('gender'),
                'birth_date' => $request->validated('birth_date'),
                'avatar_url' => $request->validated('photo_url'),
                'is_active' => true,
            ]);

            $academicYear = AcademicYear::where('institution_id', $institutionId)
                ->where('is_active', true)
                ->first() ?? AcademicYear::firstOrCreate(
                    ['institution_id' => $institutionId, 'year' => (int) date('Y')],
                    [
                        'name' => 'Año Escolar '.date('Y'),
                        'start_date' => date('Y').'-03-01',
                        'end_date' => date('Y').'-12-22',
                        'is_active' => true,
                    ]
                );

            Enrollment::create([
                'institution_id' => $institutionId,
                'student_id' => $student->id,
                'academic_year_id' => $academicYear->id,
                'section_id' => $request->validated('section_id'),
                'status' => 'active',
            ]);
        });

        return back()->with('success', 'Estudiante matriculado y registrado exitosamente.');
    }

    public function update(UpdateStudentRequest $request, Student $student): RedirectResponse
    {
        if ($student->institution_id !== $request->user()->institution_id) {
            abort(403);
        }

        DB::transaction(function () use ($request, $student) {
            $student->update([
                'first_name' => $request->validated('first_name'),
                'last_name' => $request->validated('last_name'),
                'document_number' => $request->validated('document_number'),
                'code' => $request->validated('code') ?: $student->code,
                'gender' => $request->validated('gender'),
                'birth_date' => $request->validated('birth_date'),
                'avatar_url' => $request->validated('photo_url'),
            ]);

            $enrollment = $student->currentEnrollment;
            if ($enrollment) {
                $enrollment->update([
                    'section_id' => $request->validated('section_id'),
                ]);
            } else {
                $academicYear = AcademicYear::where('institution_id', $student->institution_id)
                    ->where('is_active', true)
                    ->first();

                if ($academicYear) {
                    Enrollment::create([
                        'institution_id' => $student->institution_id,
                        'student_id' => $student->id,
                        'academic_year_id' => $academicYear->id,
                        'section_id' => $request->validated('section_id'),
                        'status' => 'active',
                    ]);
                }
            }
        });

        return back()->with('success', 'Datos del estudiante actualizados correctamente.');
    }

    public function toggleStatus(Request $request, Student $student): RedirectResponse
    {
        if ($student->institution_id !== $request->user()->institution_id) {
            abort(403);
        }

        $newActive = ! $student->is_active;

        DB::transaction(function () use ($student, $newActive) {
            $student->update([
                'is_active' => $newActive,
            ]);

            // Actualizar estado de matrícula conservando todo el historial previo
            $enrollment = $student->currentEnrollment;
            if ($enrollment) {
                $enrollment->update([
                    'status' => $newActive ? 'active' : 'inactive',
                ]);
            }
        });

        $statusLabel = $newActive ? 'reactivado' : 'desactivado';
        $detailMsg = $newActive
            ? 'Volverá a figurar como activo para el control de asistencia.'
            : 'Su historial y registros previos se conservan intactos, pero ya no aparecerá como activo para el control de asistencia.';

        return back()->with('success', "Estudiante {$student->full_name} {$statusLabel} con éxito. {$detailMsg}");
    }
}
