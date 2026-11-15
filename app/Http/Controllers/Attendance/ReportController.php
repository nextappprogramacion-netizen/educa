<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Grade;
use App\Models\Institution;
use App\Models\Section;
use App\Models\Student;
use App\Services\AttendanceExcelService;
use App\Services\AttendancePdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ReportController extends Controller
{
    public function __construct(
        public AttendancePdfService $pdfService,
        public AttendanceExcelService $excelService,
    ) {}

    /**
     * Muestra la interfaz de Reportes y Estadísticas de Asistencia conectada con PostgreSQL,
     * con resumen de ingresos, salidas, tardanzas y ausencias, y filtros por fechas,
     * estudiantes, grados y secciones.
     */
    public function index(Request $request): Response
    {
        $data = $this->getFilteredReportData($request);

        return Inertia::render('attendance/Reports', [
            'summary' => $data['summary'],
            'records' => $data['records'],
            'grades' => $data['grades'],
            'sections' => $data['sections'],
            'students' => $data['students'],
            'filters' => $data['filters'],
        ]);
    }

    /**
     * Exporta el reporte filtrado a formato PDF oficial.
     */
    public function exportPdf(Request $request): SymfonyResponse
    {
        $data = $this->getFilteredReportData($request);

        $pdfContent = $this->pdfService->generate(
            $data['institution_data'],
            $data['filters_labels'],
            $data['summary'],
            $data['records']
        );

        $startDate = $data['filters']['start_date'] ?? date('Y-m-d');
        $endDate = $data['filters']['end_date'] ?? date('Y-m-d');
        $filename = "reporte_asistencia_{$startDate}_{$endDate}.pdf";

        return new HttpResponse($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * Exporta el reporte filtrado a formato Excel (.xlsx).
     */
    public function exportExcel(Request $request): SymfonyResponse
    {
        $data = $this->getFilteredReportData($request);

        $excelContent = $this->excelService->generate(
            $data['institution_data'],
            $data['filters_labels'],
            $data['summary'],
            $data['records']
        );

        $startDate = $data['filters']['start_date'] ?? date('Y-m-d');
        $endDate = $data['filters']['end_date'] ?? date('Y-m-d');
        $filename = "reporte_asistencia_{$startDate}_{$endDate}.xlsx";

        return new HttpResponse($excelContent, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * Recupera y estructura los registros filtrados desde PostgreSQL.
     *
     * @return array{
     *     institution_data: array<string, mixed>,
     *     filters: array<string, mixed>,
     *     filters_labels: array<string, mixed>,
     *     summary: array<string, mixed>,
     *     records: array<int, array<string, mixed>>,
     *     grades: array<int, mixed>,
     *     sections: array<int, mixed>,
     *     students: array<int, mixed>
     * }
     */
    private function getFilteredReportData(Request $request): array
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

        $institution = Institution::find($institutionId);

        // Periodo por defecto: mes en curso
        $now = Carbon::now();
        $defaultStart = $now->copy()->startOfMonth()->toDateString();
        $defaultEnd = $now->toDateString();

        $startDate = $request->filled('start_date') ? (string) $request->input('start_date') : $defaultStart;
        $endDate = $request->filled('end_date') ? (string) $request->input('end_date') : $defaultEnd;
        $gradeId = $request->input('grade_id');
        $sectionId = $request->input('section_id');
        $studentId = $request->input('student_id');
        $type = $request->input('type', 'all'); // 'all', 'punctual', 'late', 'absent', 'exits', 'early_exits'

        $query = AttendanceRecord::query()
            ->where('attendance_records.institution_id', $institutionId)
            ->with([
                'enrollment.student',
                'section.grade',
                'registeredBy',
                'earlyExitAuthorizedBy',
            ]);

        // 1. Filtro por rango de fechas
        if ($startDate && $endDate) {
            $query->whereDate('attendance_records.date', '>=', $startDate)
                ->whereDate('attendance_records.date', '<=', $endDate);
        } elseif ($startDate) {
            $query->whereDate('attendance_records.date', '>=', $startDate);
        } elseif ($endDate) {
            $query->whereDate('attendance_records.date', '<=', $endDate);
        }

        // 2. Filtro por Grado
        if ($gradeId && $gradeId !== 'all') {
            $query->whereHas('section', fn ($q) => $q->where('grade_id', (int) $gradeId));
        }

        // 3. Filtro por Sección
        if ($sectionId && $sectionId !== 'all') {
            $query->where('attendance_records.section_id', (int) $sectionId);
        }

        // 4. Filtro por Estudiante
        if ($studentId && $studentId !== 'all') {
            $query->whereHas('enrollment', fn ($q) => $q->where('student_id', (int) $studentId));
        }

        // 5. Filtro por Tipo de movimiento / estado
        if ($type === 'punctual') {
            $query->where('attendance_records.status', 'present');
        } elseif ($type === 'late') {
            $query->where('attendance_records.status', 'late');
        } elseif ($type === 'absent') {
            $query->where('attendance_records.status', 'absent');
        } elseif ($type === 'exits') {
            $query->whereNotNull('attendance_records.exit_time');
        } elseif ($type === 'early_exits') {
            $query->where('attendance_records.is_early_exit', true);
        }

        $allRecords = $query
            ->orderBy('attendance_records.date', 'desc')
            ->orderBy('attendance_records.entry_time', 'desc')
            ->get();

        // Cálculo de métricas cuantitativas
        $totalEvaluated = $allRecords->count();
        $totalPunctual = $allRecords->where('status', 'present')->count();
        $totalLate = $allRecords->where('status', 'late')->count();
        $totalAbsent = $allRecords->where('status', 'absent')->count();
        $totalExits = $allRecords->whereNotNull('exit_time')->count();
        $totalEarlyExits = $allRecords->where('is_early_exit', true)->count();

        $totalPresentAndLate = $totalPunctual + $totalLate;
        $attendanceRate = $totalEvaluated > 0
            ? round(($totalPresentAndLate / $totalEvaluated) * 100, 1)
            : 0.0;
        $punctualityRate = $totalPresentAndLate > 0
            ? round(($totalPunctual / $totalPresentAndLate) * 100, 1)
            : 0.0;

        $summary = [
            'total_evaluated' => $totalEvaluated,
            'total_punctual' => $totalPunctual,
            'total_late' => $totalLate,
            'total_absent' => $totalAbsent,
            'total_exits' => $totalExits,
            'total_early_exits' => $totalEarlyExits,
            'attendance_rate' => $attendanceRate,
            'punctuality_rate' => $punctualityRate,
        ];

        // Mapeo detallado de registros
        $formattedRecords = $allRecords->map(function (AttendanceRecord $r) {
            $student = $r->enrollment?->student;
            $grade = $r->section?->grade;
            $section = $r->section;

            $statusLabel = match ($r->status) {
                'present' => 'Puntual',
                'late' => 'Tardanza',
                'absent' => 'Inasistencia',
                default => ucfirst((string) $r->status),
            };

            return [
                'id' => $r->id,
                'date' => $r->date ? $r->date->format('Y-m-d') : '',
                'student_id' => $student?->id,
                'student_code' => $student?->code ?? "EST-{$student?->id}",
                'student_dni' => $student?->document_number ?? '—',
                'student_name' => $student ? "{$student->last_name}, {$student->first_name}" : 'Estudiante no registrado',
                'grade_id' => $grade?->id,
                'grade_name' => $grade?->name ?? '—',
                'section_id' => $section?->id,
                'section_name' => $section?->name ?? '—',
                'classroom' => $grade && $section ? "{$grade->name} - {$section->name}" : 'Sin aula',
                'shift' => $r->shift ?? 'Mañana',
                'entry_time' => $r->entry_time ? substr((string) $r->entry_time, 0, 5) : null,
                'status' => $r->status,
                'status_label' => $statusLabel,
                'exit_time' => $r->exit_time ? substr((string) $r->exit_time, 0, 5) : null,
                'is_early_exit' => (bool) $r->is_early_exit,
                'early_exit_reason' => $r->early_exit_reason,
                'observation' => $r->observation,
                'is_justified' => (bool) $r->is_justified,
            ];
        })->values()->all();

        // Catálogos para filtros
        $grades = Grade::query()
            ->where('institution_id', $institutionId)
            ->where('is_active', true)
            ->with(['sections' => fn ($q) => $q->where('institution_id', $institutionId)])
            ->orderBy('level')
            ->orderBy('name')
            ->get();

        $sectionsQuery = Section::query()
            ->where('institution_id', $institutionId);
        if ($gradeId && $gradeId !== 'all') {
            $sectionsQuery->where('grade_id', (int) $gradeId);
        }
        $sections = $sectionsQuery->orderBy('name')->get();

        $students = Student::query()
            ->where('institution_id', $institutionId)
            ->where('is_active', true)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->select(['id', 'first_name', 'last_name', 'code', 'document_number'])
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => "{$s->last_name}, {$s->first_name} ({$s->code})",
            ]);

        // Nombres de etiquetas para exportación
        $selectedGradeName = 'Todos';
        if ($gradeId && $gradeId !== 'all') {
            $g = $grades->firstWhere('id', (int) $gradeId);
            $selectedGradeName = $g?->name ?? "Grado #{$gradeId}";
        }

        $selectedSectionName = 'Todas';
        if ($sectionId && $sectionId !== 'all') {
            $sec = $sections->firstWhere('id', (int) $sectionId);
            $selectedSectionName = $sec?->name ?? "Sección #{$sectionId}";
        }

        $selectedStudentName = 'Todos';
        if ($studentId && $studentId !== 'all') {
            $st = $students->firstWhere('id', (int) $studentId);
            $selectedStudentName = $st['name'] ?? "Estudiante #{$studentId}";
        }

        return [
            'institution_data' => [
                'name' => $institution?->name ?? 'Colegio Modelo Educa',
                'code' => $institution?->modular_code ?? '0000000',
            ],
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'grade_id' => $gradeId && $gradeId !== 'all' ? (int) $gradeId : 'all',
                'section_id' => $sectionId && $sectionId !== 'all' ? (int) $sectionId : 'all',
                'student_id' => $studentId && $studentId !== 'all' ? (int) $studentId : 'all',
                'type' => $type,
            ],
            'filters_labels' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'grade_name' => $selectedGradeName,
                'section_name' => $selectedSectionName,
                'student_name' => $selectedStudentName,
                'type' => $type,
            ],
            'summary' => $summary,
            'records' => $formattedRecords,
            'grades' => $grades,
            'sections' => $sections,
            'students' => $students,
        ];
    }
}
