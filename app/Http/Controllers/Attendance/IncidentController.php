<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AttendanceIncident;
use App\Models\AttendanceRecord;
use App\Models\Institution;
use App\Models\Student;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class IncidentController extends Controller
{
    /**
     * Muestra la bandeja de incidencias y excepciones conectada con PostgreSQL,
     * permitiendo consultar, filtrar y gestionar tardanzas, olvidos de carnet
     * y salidas anticipadas.
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

        $type = $request->input('type', 'all');
        $status = $request->input('status', 'all');
        $date = $request->input('date');
        $search = $request->input('search');

        $query = AttendanceIncident::where('attendance_incidents.institution_id', $institutionId)
            ->with([
                'student',
                'enrollment.section.grade',
                'registeredBy',
                'justifiedBy',
                'attendanceRecord',
            ]);

        // 1. Filtro por tipo de incidencia
        if ($type && $type !== 'all') {
            $query->where('attendance_incidents.type', $type);
        }

        // 2. Filtro por estado
        if ($status && $status !== 'all') {
            if ($status === 'justified') {
                $query->where('attendance_incidents.is_justified', true);
            } elseif ($status === 'pending') {
                $query->where('attendance_incidents.is_justified', false);
            } else {
                $query->where('attendance_incidents.status', $status);
            }
        }

        // 3. Filtro por fecha específica
        if ($date && trim($date) !== '') {
            $query->whereDate('attendance_incidents.date', $date);
        }

        // 4. Filtro por búsqueda textual (estudiante o responsable)
        if ($search && trim($search) !== '') {
            $driver = DB::connection()->getDriverName();
            $like = $driver === 'pgsql' ? 'ilike' : 'like';
            $term = "%{$search}%";

            $query->where(function ($q) use ($like, $term, $driver) {
                $q->where('attendance_incidents.responsible_name', $like, $term)
                    ->orWhere('attendance_incidents.reason', $like, $term)
                    ->orWhereHas('student', function ($sq) use ($like, $term, $driver) {
                        $sq->where('students.first_name', $like, $term)
                            ->orWhere('students.last_name', $like, $term)
                            ->orWhere('students.document_number', $like, $term)
                            ->orWhere('students.code', $like, $term);

                        if ($driver === 'pgsql') {
                            $sq->orWhereRaw("concat(students.first_name, ' ', students.last_name) ilike ?", [$term])
                                ->orWhereRaw("concat(students.last_name, ' ', students.first_name) ilike ?", [$term]);
                        }
                    });
            });
        }

        $typeLabels = [
            'tardiness' => 'Tardanza',
            'card_forgotten' => 'Olvido de Carnet QR',
            'early_exit' => 'Salida Anticipada',
        ];

        $incidents = $query
            ->orderByDesc('attendance_incidents.date')
            ->orderByDesc('attendance_incidents.created_at')
            ->get()
            ->map(function (AttendanceIncident $i) use ($typeLabels) {
                $student = $i->student;
                $section = $i->enrollment?->section;
                $grade = $section?->grade;

                $typeLabel = $typeLabels[$i->type] ?? ucfirst($i->type);

                return [
                    'id' => $i->id,
                    'type' => $i->type,
                    'type_label' => $typeLabel,
                    'date' => $i->date ? $i->date->format('Y-m-d') : null,
                    'date_formatted' => $i->date ? $i->date->format('d/m/Y') : '—',
                    'time' => $i->time ? substr((string) $i->time, 0, 5) : null,
                    'reason' => $i->reason,
                    'responsible_name' => $i->responsible_name,
                    'responsible_relationship' => $i->responsible_relationship,
                    'responsible_display' => $i->responsible_relationship
                        ? "{$i->responsible_name} ({$i->responsible_relationship})"
                        : $i->responsible_name,
                    'status' => $i->status,
                    'is_justified' => (bool) $i->is_justified,
                    'status_label' => $i->is_justified ? 'Justificado' : 'Pendiente',
                    'justification_reason' => $i->justification_reason,
                    'justified_at' => $i->justified_at?->format('d/m/Y H:i'),
                    'justified_by' => $i->justifiedBy?->name,
                    'registered_by' => $i->registeredBy?->name,
                    'created_at_formatted' => $i->created_at?->format('d/m/Y H:i'),
                    'student' => [
                        'id' => $student?->id,
                        'code' => $student?->code ?? "EST-{$student?->id}",
                        'document_number' => $student?->document_number,
                        'full_name' => $student?->full_name ?? 'Estudiante',
                        'first_name' => $student?->first_name,
                        'last_name' => $student?->last_name,
                        'avatar_url' => $student?->avatar_url,
                        'classroom' => $grade && $section ? "{$grade->name} - {$section->name}" : 'Sin aula asignada',
                        'shift' => $section?->shift ?? 'Mañana',
                    ],
                    'has_attendance_record' => (bool) $i->attendance_record_id,
                ];
            });

        // Estudiantes activos para el selector de registro de incidencia
        $students = Student::where('institution_id', $institutionId)
            ->where('is_active', true)
            ->with(['currentEnrollment.section.grade'])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(function (Student $s) {
                $section = $s->currentEnrollment?->section;
                $grade = $section?->grade;

                return [
                    'id' => $s->id,
                    'code' => $s->code,
                    'document_number' => $s->document_number,
                    'full_name' => $s->full_name,
                    'classroom' => $grade && $section ? "{$grade->name} - {$section->name}" : 'Sin aula',
                    'shift' => $section?->shift ?? 'Mañana',
                ];
            });

        // Resumen estadístico
        $allIncidents = AttendanceIncident::where('institution_id', $institutionId)->get();
        $stats = [
            'total' => $allIncidents->count(),
            'tardiness' => $allIncidents->where('type', 'tardiness')->count(),
            'card_forgotten' => $allIncidents->where('type', 'card_forgotten')->count(),
            'early_exit' => $allIncidents->where('type', 'early_exit')->count(),
            'justified' => $allIncidents->where('is_justified', true)->count(),
            'pending' => $allIncidents->where('is_justified', false)->count(),
        ];

        return Inertia::render('attendance/Incidents', [
            'incidents' => $incidents,
            'students' => $students,
            'stats' => $stats,
            'filters' => [
                'type' => $type,
                'status' => $status,
                'date' => $date ?? '',
                'search' => $search ?? '',
            ],
        ]);
    }

    /**
     * Registra una nueva incidencia (tardanza, olvido de carnet o salida anticipada)
     * en PostgreSQL guardando motivo, responsable y fecha, conservando los registros originales.
     */
    public function store(Request $request): RedirectResponse
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
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'type' => ['required', 'string', 'in:tardiness,card_forgotten,early_exit'],
            'date' => ['required', 'date'],
            'time' => ['nullable', 'string', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'responsible_name' => ['required', 'string', 'min:2', 'max:200'],
            'responsible_relationship' => ['nullable', 'string', 'max:100'],
            'is_justified' => ['nullable', 'boolean'],
            'justification_reason' => ['nullable', 'string', 'max:1000'],
        ], [
            'student_id.required' => 'Debe seleccionar un estudiante.',
            'type.required' => 'Seleccione el tipo de incidencia.',
            'date.required' => 'La fecha de la incidencia es obligatoria.',
            'reason.required' => 'El motivo de la incidencia es obligatorio.',
            'responsible_name.required' => 'Indique el nombre de la persona responsable o apoderado.',
        ]);

        $student = Student::where('institution_id', $institutionId)->findOrFail($validated['student_id']);
        $enrollment = $student->currentEnrollment;

        $date = $validated['date'];
        $time = $validated['time'] ?? now()->format('H:i:s');
        if (strlen($time) === 5) {
            $time .= ':00';
        }

        $isJustified = (bool) ($validated['is_justified'] ?? false);
        $justificationReason = $isJustified ? ($validated['justification_reason'] ?? $validated['reason']) : null;

        $attendanceRecordId = null;

        DB::transaction(function () use (
            $institutionId,
            $student,
            $enrollment,
            $validated,
            $date,
            $time,
            $isJustified,
            $justificationReason,
            $user,
            &$attendanceRecordId
        ) {
            // Verificar si ya existe registro de asistencia para este alumno en la fecha
            $existingRecord = $enrollment
                ? AttendanceRecord::where('institution_id', $institutionId)
                    ->where('enrollment_id', $enrollment->id)
                    ->whereDate('date', $date)
                    ->first()
                : null;

            $shift = $enrollment?->section?->shift ?? 'Mañana';

            // Conservar registros originales según tipo de incidencia
            if ($validated['type'] === 'tardiness') {
                if ($existingRecord) {
                    $attendanceRecordId = $existingRecord->id;
                    if ($isJustified) {
                        // Conserva hora original y estado 'late', actualizando is_justified
                        $existingRecord->update([
                            'is_justified' => true,
                            'updated_by' => $user->id,
                        ]);
                    }
                } elseif ($enrollment) {
                    // Si no existía registro, se genera con la hora de tardanza sin alterar credenciales
                    $record = AttendanceRecord::create([
                        'institution_id' => $institutionId,
                        'enrollment_id' => $enrollment->id,
                        'academic_year_id' => $enrollment->academic_year_id,
                        'section_id' => $enrollment->section_id,
                        'date' => $date,
                        'shift' => $shift,
                        'session_type' => 'daily',
                        'status' => 'late',
                        'entry_time' => $time,
                        'observation' => "Tardanza registrada por incidencia. Responsable: {$validated['responsible_name']}. Motivo: {$validated['reason']}",
                        'is_justified' => $isJustified,
                        'registered_by' => $user->id,
                        'source' => 'incident_tardiness',
                    ]);
                    $attendanceRecordId = $record->id;
                }
            } elseif ($validated['type'] === 'card_forgotten') {
                if ($existingRecord) {
                    $attendanceRecordId = $existingRecord->id;
                    if ($isJustified) {
                        $existingRecord->update(['is_justified' => true, 'updated_by' => $user->id]);
                    }
                } elseif ($enrollment) {
                    // Olvido de carnet: Se permite el ingreso manual conservando la credencial intacta
                    $record = AttendanceRecord::create([
                        'institution_id' => $institutionId,
                        'enrollment_id' => $enrollment->id,
                        'academic_year_id' => $enrollment->academic_year_id,
                        'section_id' => $enrollment->section_id,
                        'date' => $date,
                        'shift' => $shift,
                        'session_type' => 'daily',
                        'status' => 'present',
                        'entry_time' => $time,
                        'observation' => "Ingreso manual por olvido de carnet QR. Responsable: {$validated['responsible_name']}",
                        'is_justified' => true,
                        'registered_by' => $user->id,
                        'source' => 'manual_card_forgotten',
                    ]);
                    $attendanceRecordId = $record->id;
                }
            } elseif ($validated['type'] === 'early_exit') {
                if ($existingRecord) {
                    $attendanceRecordId = $existingRecord->id;
                    // Conserva hora de ingreso original y actualiza salida anticipada autorizada
                    $existingRecord->update([
                        'exit_time' => $existingRecord->exit_time ?? $time,
                        'exit_type' => 'early',
                        'is_early_exit' => true,
                        'early_exit_reason' => $validated['reason'],
                        'early_exit_authorized_by' => $user->id,
                        'is_justified' => $isJustified,
                        'updated_by' => $user->id,
                    ]);
                } elseif ($enrollment) {
                    $record = AttendanceRecord::create([
                        'institution_id' => $institutionId,
                        'enrollment_id' => $enrollment->id,
                        'academic_year_id' => $enrollment->academic_year_id,
                        'section_id' => $enrollment->section_id,
                        'date' => $date,
                        'shift' => $shift,
                        'session_type' => 'daily',
                        'status' => 'present',
                        'entry_time' => null,
                        'exit_time' => $time,
                        'exit_type' => 'early',
                        'is_early_exit' => true,
                        'early_exit_reason' => $validated['reason'],
                        'early_exit_authorized_by' => $user->id,
                        'is_justified' => $isJustified,
                        'registered_by' => $user->id,
                        'source' => 'incident_early_exit',
                    ]);
                    $attendanceRecordId = $record->id;
                }
            }

            // Guardar incidencia en PostgreSQL
            AttendanceIncident::create([
                'institution_id' => $institutionId,
                'student_id' => $student->id,
                'enrollment_id' => $enrollment?->id,
                'attendance_record_id' => $attendanceRecordId,
                'type' => $validated['type'],
                'date' => $date,
                'time' => $time,
                'reason' => $validated['reason'],
                'responsible_name' => $validated['responsible_name'],
                'responsible_relationship' => $validated['responsible_relationship'] ?? null,
                'registered_by' => $user->id,
                'status' => $isJustified ? 'justified' : 'registered',
                'is_justified' => $isJustified,
                'justification_reason' => $justificationReason,
                'justified_by' => $isJustified ? $user->id : null,
                'justified_at' => $isJustified ? now() : null,
            ]);
        });

        $typeNames = [
            'tardiness' => 'Tardanza',
            'card_forgotten' => 'Olvido de Carnet QR',
            'early_exit' => 'Salida Anticipada',
        ];
        $typeName = $typeNames[$validated['type']] ?? 'Incidencia';

        return back()->with('success', "Incidencia de {$typeName} para {$student->full_name} registrada correctamente en PostgreSQL.");
    }

    /**
     * Justifica una incidencia pendiente en PostgreSQL, conservando los registros originales.
     */
    public function justify(Request $request, AttendanceIncident $incident): RedirectResponse
    {
        $user = $request->user();
        if ($incident->institution_id !== $user->institution_id) {
            abort(403);
        }

        $validated = $request->validate([
            'justification_reason' => ['required', 'string', 'min:3', 'max:1000'],
            'responsible_name' => ['nullable', 'string', 'max:200'],
        ], [
            'justification_reason.required' => 'El motivo de la justificación es obligatorio.',
        ]);

        DB::transaction(function () use ($incident, $validated, $user) {
            $incident->update([
                'is_justified' => true,
                'status' => 'justified',
                'justification_reason' => $validated['justification_reason'],
                'justified_by' => $user->id,
                'justified_at' => now(),
                'responsible_name' => ($validated['responsible_name'] ?? null) ?: $incident->responsible_name,
            ]);

            // Actualizar el registro original de asistencia a justificado conservando horas y estados previos
            if ($incident->attendance_record_id) {
                $record = $incident->attendanceRecord;
                if ($record) {
                    $record->update([
                        'is_justified' => true,
                        'updated_by' => $user->id,
                    ]);
                }
            }
        });

        return back()->with('success', "Incidencia #{$incident->id} justificada exitosamente en PostgreSQL.");
    }
}
