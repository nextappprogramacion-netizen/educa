<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AcademicCalendarDay;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSchedule;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Institution;
use App\Models\Section;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class HistoryController extends Controller
{
    /**
     * Muestra el historial cronológico de asistencia conectado con PostgreSQL,
     * detectando estudiantes sin ingreso registrado, mostrando "Sin registro"
     * antes del cierre de jornada y permitiendo clasificarlos como ausentes
     * después del cierre, respetando el calendario escolar.
     */
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

        $date = $request->input('date');
        $gradeId = $request->input('grade_id');
        $sectionId = $request->input('section_id');
        $search = $request->input('search');
        $status = $request->input('status', 'all');

        $query = AttendanceRecord::query()
            ->where('attendance_records.institution_id', $institutionId)
            ->with([
                'enrollment.student',
                'section.grade',
                'registeredBy',
                'earlyExitAuthorizedBy',
            ]);

        // 1. Filtro por fecha específica
        if ($date && trim($date) !== '') {
            $query->whereDate('attendance_records.date', $date);
        }

        // 2. Filtro por grado
        if ($gradeId && $gradeId !== 'all') {
            $query->whereHas('section', fn ($q) => $q->where('grade_id', (int) $gradeId));
        }

        // 3. Filtro por sección
        if ($sectionId && $sectionId !== 'all') {
            $query->where('attendance_records.section_id', (int) $sectionId);
        }

        // 4. Filtro por estado de asistencia (si es 'unregistered', los registros de DB no aplican)
        if ($status && $status !== 'all' && $status !== 'unregistered') {
            $query->where('attendance_records.status', $status);
        }

        // 5. Filtro textual por estudiante (nombre, apellido, DNI o código)
        if ($search && trim($search) !== '') {
            $query->whereHas('enrollment.student', function ($q) use ($search) {
                $driver = DB::connection()->getDriverName();
                $like = $driver === 'pgsql' ? 'ilike' : 'like';
                $term = "%{$search}%";

                $q->where('students.first_name', $like, $term)
                    ->orWhere('students.last_name', $like, $term)
                    ->orWhere('students.document_number', $like, $term)
                    ->orWhere('students.code', $like, $term);

                if ($driver === 'pgsql') {
                    $q->orWhereRaw("concat(students.first_name, ' ', students.last_name) ilike ?", [$term])
                        ->orWhereRaw("concat(students.last_name, ' ', students.first_name) ilike ?", [$term]);
                }
            });
        }

        $dbRecords = ($status === 'unregistered')
            ? collect()
            : $query
                ->orderByDesc('attendance_records.date')
                ->orderByDesc('attendance_records.entry_time')
                ->orderByDesc('attendance_records.updated_at')
                ->get()
                ->map(function (AttendanceRecord $r) {
                    $student = $r->enrollment?->student;
                    $section = $r->section ?? $r->enrollment?->section;
                    $grade = $section?->grade;

                    $punctuality = match ($r->status) {
                        'present' => 'Puntual',
                        'late' => 'Tardanza',
                        default => 'Sin registro',
                    };

                    $exitTypeLabel = match ($r->exit_type) {
                        'early' => 'Salida Anticipada',
                        'regular' => 'Salida Regular',
                        default => $r->exit_time ? 'Salida Registrada' : null,
                    };

                    $statusLabel = match ($r->status) {
                        'present' => 'Presente',
                        'late' => 'Tardanza',
                        'absent' => 'Falta',
                        'justified' => 'Justificado',
                        default => ucfirst($r->status),
                    };

                    return [
                        'id' => $r->id,
                        'enrollment_id' => $r->enrollment_id,
                        'date' => $r->date ? $r->date->format('Y-m-d') : null,
                        'date_formatted' => $r->date ? $r->date->format('d/m/Y') : '—',
                        'student' => [
                            'id' => $student?->id,
                            'code' => $student?->code ?? "EST-{$student?->id}",
                            'document_number' => $student?->document_number,
                            'first_name' => $student?->first_name,
                            'last_name' => $student?->last_name,
                            'full_name' => $student?->full_name ?? 'Estudiante no identificado',
                            'avatar_url' => $student?->avatar_url,
                            'is_active' => (bool) ($student?->is_active ?? true),
                        ],
                        'classroom' => [
                            'grade_id' => $grade?->id,
                            'grade_name' => $grade?->name ?? 'Sin grado',
                            'section_id' => $section?->id,
                            'section_name' => $section?->name ?? 'Sin sección',
                            'label' => $grade && $section ? "{$grade->name} - {$section->name}" : 'Sin aula asignada',
                            'shift' => $r->shift ?? $section?->shift ?? 'Mañana',
                        ],
                        'entry_time' => $r->entry_time ? substr((string) $r->entry_time, 0, 5) : null,
                        'entry_time_full' => $r->entry_time ? substr((string) $r->entry_time, 0, 8) : null,
                        'exit_time' => $r->exit_time ? substr((string) $r->exit_time, 0, 5) : null,
                        'exit_time_full' => $r->exit_time ? substr((string) $r->exit_time, 0, 8) : null,
                        'status' => $r->status,
                        'status_label' => $statusLabel,
                        'punctuality' => $punctuality,
                        'exit_type' => $r->exit_type,
                        'exit_type_label' => $exitTypeLabel,
                        'is_early_exit' => (bool) $r->is_early_exit,
                        'early_exit_reason' => $r->early_exit_reason,
                        'early_exit_authorized_by' => $r->earlyExitAuthorizedBy?->name,
                        'source' => $r->source ?? 'qr_terminal',
                        'observation' => $r->observation,
                        'is_unregistered' => false,
                        'can_classify_absent' => false,
                    ];
                });

        // 6. Detección de estudiantes sin ingreso registrado (respetando el calendario escolar y turnos)
        $calendarDayInfo = null;
        $unrecordedRecords = collect();

        $schedules = AttendanceSchedule::where('institution_id', $institutionId)
            ->where('is_active', true)
            ->get()
            ->keyBy('shift');

        if ($date && trim($date) !== '') {
            // Validar en el calendario escolar de PostgreSQL
            $calendarDay = AcademicCalendarDay::where('institution_id', $institutionId)
                ->whereDate('date', $date)
                ->first();

            $isSchoolDay = true;
            $calendarDayType = 'school_day';
            $calendarTitle = 'Día lectivo';
            $calendarDescription = null;

            if ($calendarDay) {
                $calendarDayType = $calendarDay->day_type;
                $calendarTitle = $calendarDay->title;
                $calendarDescription = $calendarDay->description;
                $isSchoolDay = (bool) $calendarDay->is_attendance_allowed && ! in_array($calendarDay->day_type, ['holiday', 'suspension']);
            } else {
                $carbon = Carbon::parse($date);
                if ($carbon->isWeekend()) {
                    $isSchoolDay = false;
                    $calendarDayType = 'weekend';
                    $calendarTitle = 'Fin de semana';
                }
            }

            $calendarDayInfo = [
                'is_school_day' => $isSchoolDay,
                'day_type' => $calendarDayType,
                'title' => $calendarTitle,
                'description' => $calendarDescription,
            ];

            // Si es día lectivo y el filtro de estado permite no registrados ('all' o 'unregistered')
            if ($isSchoolDay && in_array($status, ['all', 'unregistered'])) {
                // Matrículas que ya cuentan con algún registro en PostgreSQL para esta fecha
                $recordedEnrollmentIds = AttendanceRecord::where('institution_id', $institutionId)
                    ->whereDate('date', $date)
                    ->pluck('enrollment_id')
                    ->all();

                $enrollmentsQuery = Enrollment::where('enrollments.institution_id', $institutionId)
                    ->where('enrollments.status', 'active')
                    ->whereHas('student', fn ($q) => $q->where('students.is_active', true))
                    ->with(['student', 'section.grade']);

                if ($gradeId && $gradeId !== 'all') {
                    $enrollmentsQuery->whereHas('section', fn ($q) => $q->where('grade_id', (int) $gradeId));
                }

                if ($sectionId && $sectionId !== 'all') {
                    $enrollmentsQuery->where('enrollments.section_id', (int) $sectionId);
                }

                if ($search && trim($search) !== '') {
                    $enrollmentsQuery->whereHas('student', function ($q) use ($search) {
                        $driver = DB::connection()->getDriverName();
                        $like = $driver === 'pgsql' ? 'ilike' : 'like';
                        $term = "%{$search}%";

                        $q->where('students.first_name', $like, $term)
                            ->orWhere('students.last_name', $like, $term)
                            ->orWhere('students.document_number', $like, $term)
                            ->orWhere('students.code', $like, $term);

                        if ($driver === 'pgsql') {
                            $q->orWhereRaw("concat(students.first_name, ' ', students.last_name) ilike ?", [$term])
                                ->orWhereRaw("concat(students.last_name, ' ', students.first_name) ilike ?", [$term]);
                        }
                    });
                }

                $todayDate = now()->toDateString();
                $currentTime = now()->format('H:i:s');
                $isPastDate = $date < $todayDate;
                $isToday = $date === $todayDate;

                $unrecordedEnrollments = $enrollmentsQuery->get()
                    ->reject(fn ($enr) => in_array($enr->id, $recordedEnrollmentIds));

                $unrecordedRecords = $unrecordedEnrollments->map(function (Enrollment $enr) use ($date, $schedules, $isPastDate, $isToday, $currentTime) {
                    $student = $enr->student;
                    $section = $enr->section;
                    $grade = $section?->grade;
                    $shift = $section?->shift ?? 'Mañana';

                    $schedule = $schedules[$shift] ?? null;
                    $exitTime = $schedule?->exit_time ?? ($shift === 'Tarde' ? '18:30:00' : '13:30:00');
                    if (strlen($exitTime) === 5) {
                        $exitTime .= ':00';
                    }

                    // Cierre de jornada: si es fecha pasada, ya cerró. Si es hoy, comparar hora actual con hora de salida programada
                    $isClosed = $isPastDate || ($isToday && strcmp($currentTime, $exitTime) >= 0);

                    return [
                        'id' => 'unregistered-'.$enr->id,
                        'enrollment_id' => $enr->id,
                        'date' => $date,
                        'date_formatted' => Carbon::parse($date)->format('d/m/Y'),
                        'student' => [
                            'id' => $student?->id,
                            'code' => $student?->code ?? "EST-{$student?->id}",
                            'document_number' => $student?->document_number,
                            'first_name' => $student?->first_name,
                            'last_name' => $student?->last_name,
                            'full_name' => $student?->full_name ?? 'Estudiante',
                            'avatar_url' => $student?->avatar_url,
                            'is_active' => (bool) ($student?->is_active ?? true),
                        ],
                        'classroom' => [
                            'grade_id' => $grade?->id,
                            'grade_name' => $grade?->name ?? 'Sin grado',
                            'section_id' => $section?->id,
                            'section_name' => $section?->name ?? 'Sin sección',
                            'label' => $grade && $section ? "{$grade->name} - {$section->name}" : 'Sin aula asignada',
                            'shift' => $shift,
                        ],
                        'entry_time' => null,
                        'entry_time_full' => null,
                        'exit_time' => null,
                        'exit_time_full' => null,
                        'status' => 'unregistered',
                        'status_label' => 'Sin registro',
                        'punctuality' => 'Sin registro',
                        'exit_type' => null,
                        'exit_type_label' => null,
                        'is_early_exit' => false,
                        'early_exit_reason' => null,
                        'early_exit_authorized_by' => null,
                        'source' => null,
                        'observation' => $isClosed
                            ? 'Sin ingreso registrado tras el cierre de jornada escolar. Listo para clasificar como ausente.'
                            : 'Jornada escolar en curso (Cierre programado: '.substr($exitTime, 0, 5).').',
                        'is_unregistered' => true,
                        'can_classify_absent' => $isClosed,
                        'exit_time_scheduled' => substr($exitTime, 0, 5),
                    ];
                });
            }
        }

        $records = ($status === 'unregistered')
            ? $unrecordedRecords
            : $dbRecords->concat($unrecordedRecords);

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

        // Catálogo de Secciones reales desde PostgreSQL
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

        // Resumen estadístico de los registros
        $stats = [
            'total' => $records->count(),
            'punctual' => $records->where('status', 'present')->count(),
            'late' => $records->where('status', 'late')->count(),
            'absent' => $records->where('status', 'absent')->count(),
            'unregistered' => $records->where('status', 'unregistered')->count(),
            'early_exit' => $records->where('is_early_exit', true)->count(),
            'regular_exit' => $records->where('exit_type', 'regular')->count(),
            'can_classify_absent_count' => $records->where('can_classify_absent', true)->count(),
        ];

        return Inertia::render('attendance/History', [
            'records' => $records->values(),
            'grades' => $grades,
            'sections' => $sections,
            'filters' => [
                'date' => $date ?? '',
                'grade_id' => $gradeId && $gradeId !== 'all' ? (int) $gradeId : 'all',
                'section_id' => $sectionId && $sectionId !== 'all' ? (int) $sectionId : 'all',
                'search' => $search ?? '',
                'status' => $status,
            ],
            'stats' => $stats,
            'calendar_day' => $calendarDayInfo,
        ]);
    }

    /**
     * Clasifica a los estudiantes sin ingreso registrado como ausentes en PostgreSQL
     * tras el cierre de jornada, respetando el calendario escolar.
     */
    public function classifyAbsent(Request $request): RedirectResponse
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

        $validated = $request->validate([
            'date' => ['required', 'date'],
            'enrollment_id' => ['nullable', 'integer', 'exists:enrollments,id'],
            'grade_id' => ['nullable'],
            'section_id' => ['nullable'],
        ]);

        $date = $validated['date'];
        $enrollmentId = $validated['enrollment_id'] ?? null;
        $todayDate = now()->toDateString();
        $currentTime = now()->format('H:i:s');

        // 1. Respetando el calendario escolar: Validar que sea día lectivo
        $calendarDay = AcademicCalendarDay::where('institution_id', $institutionId)
            ->whereDate('date', $date)
            ->first();

        if ($calendarDay) {
            if (! $calendarDay->is_attendance_allowed || in_array($calendarDay->day_type, ['holiday', 'suspension'])) {
                $typeLabel = $calendarDay->day_type === 'holiday' ? 'feriado' : ($calendarDay->day_type === 'suspension' ? 'suspensión de actividades' : 'día no lectivo');

                return back()->withErrors([
                    'calendar' => "No se puede clasificar como ausente: La fecha {$date} está registrada como {$typeLabel} ('{$calendarDay->title}') en el calendario escolar.",
                ]);
            }
        } else {
            $carbonDate = Carbon::parse($date);
            if ($carbonDate->isWeekend()) {
                return back()->withErrors([
                    'calendar' => "No se puede clasificar como ausente: La fecha {$date} corresponde a fin de semana y no es día lectivo.",
                ]);
            }
        }

        // No se puede clasificar ausencias para fechas futuras
        if ($date > $todayDate) {
            return back()->withErrors([
                'date' => "No se puede clasificar ausencias para fechas futuras ({$date}).",
            ]);
        }

        // 2. Horarios por turno desde PostgreSQL
        $schedules = AttendanceSchedule::where('institution_id', $institutionId)
            ->where('is_active', true)
            ->get()
            ->keyBy('shift');

        // 3. Matrículas activas a clasificar
        $enrollmentsQuery = Enrollment::where('enrollments.institution_id', $institutionId)
            ->where('enrollments.status', 'active')
            ->whereHas('student', fn ($q) => $q->where('students.is_active', true))
            ->with(['section.grade', 'student']);

        if ($enrollmentId) {
            $enrollmentsQuery->where('enrollments.id', $enrollmentId);
        } else {
            if (! empty($validated['section_id']) && $validated['section_id'] !== 'all') {
                $enrollmentsQuery->where('enrollments.section_id', (int) $validated['section_id']);
            } elseif (! empty($validated['grade_id']) && $validated['grade_id'] !== 'all') {
                $enrollmentsQuery->whereHas('section', fn ($q) => $q->where('grade_id', (int) $validated['grade_id']));
            }
        }

        $enrollments = $enrollmentsQuery->get();

        if ($enrollments->isEmpty()) {
            return back()->withErrors(['enrollments' => 'No se encontraron estudiantes para clasificar como ausentes.']);
        }

        $classifiedCount = 0;
        $errors = [];

        DB::transaction(function () use ($enrollments, $institutionId, $date, $todayDate, $currentTime, $schedules, $user, &$classifiedCount, &$errors) {
            foreach ($enrollments as $enrollment) {
                $shift = $enrollment->section?->shift ?? 'Mañana';
                $schedule = $schedules[$shift] ?? null;
                $exitTime = $schedule?->exit_time ?? ($shift === 'Tarde' ? '18:30:00' : '13:30:00');
                if (strlen($exitTime) === 5) {
                    $exitTime .= ':00';
                }

                // Si es hoy, verificar que haya concluido el turno (cierre de jornada)
                if ($date === $todayDate && strcmp($currentTime, $exitTime) < 0) {
                    $exitTimeShort = substr($exitTime, 0, 5);
                    $studentName = $enrollment->student?->full_name ?? "Estudiante #{$enrollment->student_id}";
                    $errors[] = "No se puede clasificar como ausente a {$studentName} antes del cierre de jornada del Turno {$shift} (Hora de salida: {$exitTimeShort}).";

                    continue;
                }

                // Si ya tiene un registro con entry_time registrado, omitir
                $existing = AttendanceRecord::where('institution_id', $institutionId)
                    ->where('enrollment_id', $enrollment->id)
                    ->whereDate('date', $date)
                    ->first();

                if ($existing && $existing->entry_time) {
                    continue;
                }

                AttendanceRecord::updateOrCreate(
                    [
                        'institution_id' => $institutionId,
                        'enrollment_id' => $enrollment->id,
                        'date' => $date,
                        'shift' => $shift,
                    ],
                    [
                        'academic_year_id' => $enrollment->academic_year_id,
                        'section_id' => $enrollment->section_id,
                        'session_type' => 'daily',
                        'status' => 'absent',
                        'entry_time' => null,
                        'exit_time' => null,
                        'observation' => 'Ausencia registrada al cierre de jornada escolar',
                        'registered_by' => $user->id,
                        'source' => 'manual',
                    ]
                );

                $classifiedCount++;
            }
        });

        if (! empty($errors) && $classifiedCount === 0) {
            return back()->withErrors(['shift_error' => $errors[0]]);
        }

        $message = $classifiedCount === 1
            ? 'Estudiante clasificado como ausente correctamente en PostgreSQL.'
            : "{$classifiedCount} estudiantes clasificados como ausentes correctamente en PostgreSQL al cierre de jornada.";

        return back()->with('success', $message);
    }
}
