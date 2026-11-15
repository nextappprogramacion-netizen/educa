<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AcademicCalendarDay;
use App\Models\AttendanceRecord;
use App\Models\AttendanceTerminal;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Institution;
use App\Models\Section;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OverviewController extends Controller
{
    /**
     * Muestra el Panel General de Asistencia conectado a PostgreSQL con cifras reales
     * de estudiantes dentro del colegio, ingresos, salidas, puntualidad, tardanzas,
     * ausencias y registros pendientes, junto con gráficos de asistencia diaria,
     * semanal y mensual filtrables por grado, sección y periodo.
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

        $period = $request->input('period', 'daily');
        if (! in_array($period, ['daily', 'weekly', 'monthly'])) {
            $period = 'daily';
        }

        $gradeId = $request->input('grade_id');
        $sectionId = $request->input('section_id');

        $today = now()->toDateString();
        $now = now();

        // 1. Calendario Escolar: Validar si hoy es día lectivo
        $calendarDay = AcademicCalendarDay::where('institution_id', $institutionId)
            ->whereDate('date', $today)
            ->first();

        $isSchoolDay = true;
        $calendarTitle = 'Día lectivo';
        $calendarType = 'school_day';

        if ($calendarDay) {
            $calendarType = $calendarDay->day_type;
            $calendarTitle = $calendarDay->title;
            $isSchoolDay = (bool) $calendarDay->is_attendance_allowed && ! in_array($calendarDay->day_type, ['holiday', 'suspension']);
        } else {
            if ($now->isWeekend()) {
                $isSchoolDay = false;
                $calendarType = 'weekend';
                $calendarTitle = 'Fin de semana';
            }
        }

        // 2. Registros de Asistencia de hoy en PostgreSQL (filtrables por grado y sección)
        $todayRecordsQuery = AttendanceRecord::where('attendance_records.institution_id', $institutionId)
            ->whereDate('attendance_records.date', $today);

        if ($gradeId && $gradeId !== 'all') {
            $todayRecordsQuery->whereHas('section', fn ($q) => $q->where('grade_id', (int) $gradeId));
        }

        if ($sectionId && $sectionId !== 'all') {
            $todayRecordsQuery->where('attendance_records.section_id', (int) $sectionId);
        }

        $todayRecords = $todayRecordsQuery->get();

        // Cifras reales de hoy:
        $insideSchool = $todayRecords->whereNotNull('entry_time')->whereNull('exit_time')->count();
        $totalEntries = $todayRecords->whereNotNull('entry_time')->count();
        $totalExits = $todayRecords->whereNotNull('exit_time')->count();
        $punctual = $todayRecords->where('status', 'present')->whereNotNull('entry_time')->count();
        $late = $todayRecords->where('status', 'late')->count();
        $absent = $todayRecords->where('status', 'absent')->count();
        $regularExits = $todayRecords->where('exit_type', 'regular')->count();
        $earlyExits = $todayRecords->where('is_early_exit', true)->count();

        // Estudiantes matriculados activos correspondientes a los filtros
        $enrollmentsQuery = Enrollment::where('enrollments.institution_id', $institutionId)
            ->where('enrollments.status', 'active')
            ->whereHas('student', fn ($q) => $q->where('students.is_active', true));

        if ($gradeId && $gradeId !== 'all') {
            $enrollmentsQuery->whereHas('section', fn ($q) => $q->where('grade_id', (int) $gradeId));
        }

        if ($sectionId && $sectionId !== 'all') {
            $enrollmentsQuery->where('enrollments.section_id', (int) $sectionId);
        }

        $totalActiveEnrolled = $enrollmentsQuery->count();

        // Registros pendientes: Alumnos matriculados activos sin movimiento de asistencia hoy en día lectivo
        $recordedEnrollmentCount = $todayRecords->pluck('enrollment_id')->unique()->count();
        $pendingRecords = $isSchoolDay ? max(0, $totalActiveEnrolled - $recordedEnrollmentCount) : 0;

        $stats = [
            'inside_school' => $insideSchool,
            'total_entries' => $totalEntries,
            'total_exits' => $totalExits,
            'punctual' => $punctual,
            'late' => $late,
            'absent' => $absent,
            'pending_records' => $pendingRecords,
            'regular_exits' => $regularExits,
            'early_exits' => $earlyExits,
            'total_enrolled' => $totalActiveEnrolled,
            'date_formatted' => $now->translatedFormat('l, d \d\e F \d\e Y'),
            'date' => $today,
            'is_school_day' => $isSchoolDay,
            'calendar_title' => $calendarTitle,
            'calendar_type' => $calendarType,
        ];

        // 3. Gráficos de Asistencia (diaria, semanal, mensual) desde PostgreSQL
        $chartData = $this->buildChartData($institutionId, $period, $gradeId, $sectionId);

        // 4. Catálogo de Grados y Secciones para filtros
        $grades = Grade::where('institution_id', $institutionId)
            ->orderBy('order_index')
            ->orderBy('name')
            ->get(['id', 'name', 'level'])
            ->map(fn (Grade $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'level' => $g->level,
            ]);

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
                'shift' => $s->shift,
                'label' => "{$s->grade?->name} - {$s->name} ({$s->shift})",
            ]);

        // 5. Puntos de Control y Terminales autorizadas
        $terminals = AttendanceTerminal::where('institution_id', $institutionId)
            ->orderBy('name')
            ->get()
            ->map(function (AttendanceTerminal $t) {
                return [
                    'id' => $t->id,
                    'name' => $t->name,
                    'code' => $t->code,
                    'location' => $t->location ?? 'Portería',
                    'mode' => $t->mode,
                    'mode_label' => $t->mode === 'entry' ? 'Entrada' : 'Salida',
                    'is_active' => (bool) $t->is_active,
                    'last_heartbeat' => $t->last_heartbeat_at?->format('H:i'),
                ];
            });

        // 6. Últimos accesos registrados hoy
        $recentScansQuery = AttendanceRecord::where('attendance_records.institution_id', $institutionId)
            ->whereDate('attendance_records.date', $today)
            ->with(['enrollment.student', 'enrollment.section.grade', 'registeredBy']);

        if ($gradeId && $gradeId !== 'all') {
            $recentScansQuery->whereHas('section', fn ($q) => $q->where('grade_id', (int) $gradeId));
        }

        if ($sectionId && $sectionId !== 'all') {
            $recentScansQuery->where('attendance_records.section_id', (int) $sectionId);
        }

        $recentScans = $recentScansQuery
            ->orderByDesc('attendance_records.updated_at')
            ->take(8)
            ->get()
            ->map(function (AttendanceRecord $r) {
                $student = $r->enrollment?->student;
                $section = $r->enrollment?->section;
                $grade = $section?->grade;

                $latestAction = $r->exit_time ? 'exit' : 'entry';
                $time = $r->exit_time ?? $r->entry_time;

                return [
                    'id' => $r->id,
                    'student_name' => $student?->full_name ?? 'Estudiante no identificado',
                    'first_name' => $student?->first_name ?? 'Estudiante',
                    'last_name' => $student?->last_name ?? '',
                    'student_code' => $student?->code ?? "EST-{$student?->id}",
                    'classroom' => $grade && $section ? "{$grade->name} - {$section->name}" : 'Sin aula asignada',
                    'shift' => $r->shift,
                    'entry_time' => $r->entry_time ? substr((string) $r->entry_time, 0, 5) : null,
                    'exit_time' => $r->exit_time ? substr((string) $r->exit_time, 0, 5) : null,
                    'action' => $latestAction,
                    'status' => $r->status,
                    'is_early_exit' => (bool) $r->is_early_exit,
                    'early_exit_reason' => $r->early_exit_reason,
                    'time_formatted' => $time ? substr((string) $time, 0, 5) : '—',
                    'observation' => $r->observation,
                ];
            });

        return Inertia::render('attendance/Overview', [
            'stats' => $stats,
            'chart' => $chartData,
            'grades' => $grades,
            'sections' => $sections,
            'terminals' => $terminals,
            'recent_scans' => $recentScans,
            'filters' => [
                'period' => $period,
                'grade_id' => $gradeId && $gradeId !== 'all' ? (int) $gradeId : 'all',
                'section_id' => $sectionId && $sectionId !== 'all' ? (int) $sectionId : 'all',
            ],
        ]);
    }

    /**
     * Construye las series de datos para gráficos diarios, semanales y mensuales desde PostgreSQL.
     *
     * @return array{
     *     period: string,
     *     series: array<int, array{
     *         label: string,
     *         sublabel: string,
     *         start_date: string,
     *         end_date: string,
     *         punctual: int,
     *         late: int,
     *         absent: int,
     *         total_attendance: int,
     *         total_evaluated: int,
     *         attendance_rate: float
     *     }>,
     *     summary: array{
     *         total_punctual: int,
     *         total_late: int,
     *         total_absent: int,
     *         total_attendance: int,
     *         overall_rate: float,
     *         max_val: int
     *     }
     * }
     */
    private function buildChartData(int $institutionId, string $period, mixed $gradeId, mixed $sectionId): array
    {
        $now = now();
        $intervals = collect();

        if ($period === 'weekly') {
            // Últimas 6 semanas
            for ($i = 5; $i >= 0; $i--) {
                $startOfWeek = $now->copy()->subWeeks($i)->startOfWeek();
                $endOfWeek = $now->copy()->subWeeks($i)->endOfWeek();
                $weekNumber = $startOfWeek->weekOfYear;
                $intervals->push([
                    'start_date' => $startOfWeek->toDateString(),
                    'end_date' => $endOfWeek->toDateString(),
                    'label' => "Sem {$weekNumber}",
                    'sublabel' => $startOfWeek->format('d/m').' - '.$endOfWeek->format('d/m'),
                ]);
            }
        } elseif ($period === 'monthly') {
            // Últimos 6 meses
            for ($i = 5; $i >= 0; $i--) {
                $monthDate = $now->copy()->subMonths($i);
                $startOfMonth = $monthDate->copy()->startOfMonth();
                $endOfMonth = $monthDate->copy()->endOfMonth();
                $intervals->push([
                    'start_date' => $startOfMonth->toDateString(),
                    'end_date' => $endOfMonth->toDateString(),
                    'label' => ucfirst($monthDate->translatedFormat('M')),
                    'sublabel' => ucfirst($monthDate->translatedFormat('F Y')),
                ]);
            }
        } else {
            // Diaria: Últimos 7 días
            $period = 'daily';
            for ($i = 6; $i >= 0; $i--) {
                $dayDate = $now->copy()->subDays($i);
                $dateStr = $dayDate->toDateString();
                $intervals->push([
                    'start_date' => $dateStr,
                    'end_date' => $dateStr,
                    'label' => ucfirst($dayDate->translatedFormat('D d')),
                    'sublabel' => $dayDate->format('d/m/Y'),
                ]);
            }
        }

        $minDate = $intervals->first()['start_date'];
        $maxDate = $intervals->last()['end_date'];

        $recordsQuery = AttendanceRecord::where('institution_id', $institutionId)
            ->whereDate('date', '>=', $minDate)
            ->whereDate('date', '<=', $maxDate);

        if ($gradeId && $gradeId !== 'all') {
            $recordsQuery->whereHas('section', fn ($q) => $q->where('grade_id', (int) $gradeId));
        }

        if ($sectionId && $sectionId !== 'all') {
            $recordsQuery->where('section_id', (int) $sectionId);
        }

        $records = $recordsQuery->get(['id', 'date', 'status', 'entry_time']);

        $series = $intervals->map(function ($interval) use ($records) {
            $startDate = $interval['start_date'];
            $endDate = $interval['end_date'];

            $matching = $records->filter(function ($r) use ($startDate, $endDate) {
                $recordDate = $r->date ? $r->date->format('Y-m-d') : null;

                return $recordDate >= $startDate && $recordDate <= $endDate;
            });

            $punctual = $matching->where('status', 'present')->whereNotNull('entry_time')->count();
            $late = $matching->where('status', 'late')->count();
            $absent = $matching->where('status', 'absent')->count();
            $totalAttendance = $punctual + $late;
            $totalEvaluated = $totalAttendance + $absent;

            $attendanceRate = $totalEvaluated > 0
                ? round(($totalAttendance / $totalEvaluated) * 100, 1)
                : 0.0;

            return [
                'label' => $interval['label'],
                'sublabel' => $interval['sublabel'],
                'start_date' => $startDate,
                'end_date' => $endDate,
                'punctual' => $punctual,
                'late' => $late,
                'absent' => $absent,
                'total_attendance' => $totalAttendance,
                'total_evaluated' => $totalEvaluated,
                'attendance_rate' => $attendanceRate,
            ];
        });

        $totalPunctual = $series->sum('punctual');
        $totalLate = $series->sum('late');
        $totalAbsent = $series->sum('absent');
        $overallAttendance = $totalPunctual + $totalLate;
        $overallEvaluated = $overallAttendance + $totalAbsent;
        $overallRate = $overallEvaluated > 0
            ? round(($overallAttendance / $overallEvaluated) * 100, 1)
            : 0.0;

        $maxVal = max(
            1,
            $series->max(fn ($item) => max($item['punctual'], $item['late'], $item['absent'], $item['total_attendance']))
        );

        return [
            'period' => $period,
            'series' => $series->values()->all(),
            'summary' => [
                'total_punctual' => $totalPunctual,
                'total_late' => $totalLate,
                'total_absent' => $totalAbsent,
                'total_attendance' => $overallAttendance,
                'overall_rate' => $overallRate,
                'max_val' => $maxVal,
            ],
        ];
    }
}
