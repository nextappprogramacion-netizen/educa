<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSchedule;
use App\Models\AttendanceTerminal;
use App\Models\Enrollment;
use App\Models\Institution;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentCredential;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ScannerController extends Controller
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

        // Asegurar terminales autorizadas básicas en PostgreSQL si no existen
        AttendanceTerminal::firstOrCreate(
            [
                'institution_id' => $institutionId,
                'code' => 'TERM-ING-01',
            ],
            [
                'name' => 'Lector Puerta Principal 1 (Ingreso)',
                'mode' => 'entry',
                'location' => 'Puerta Principal',
                'is_active' => true,
            ]
        );

        AttendanceTerminal::firstOrCreate(
            [
                'institution_id' => $institutionId,
                'code' => 'TERM-SAL-01',
            ],
            [
                'name' => 'Lector Puerta Salida Principal',
                'mode' => 'exit',
                'location' => 'Puerta de Salida',
                'is_active' => true,
            ]
        );

        // Cargar todas las terminales autorizadas de la institución
        $terminals = AttendanceTerminal::where('institution_id', $institutionId)
            ->where('is_active', true)
            ->orderByRaw("CASE WHEN mode = 'entry' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->get()
            ->map(fn (AttendanceTerminal $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'code' => $t->code,
                'location' => $t->location,
                'mode' => $t->mode, // 'entry' o 'exit'
                'mode_label' => $t->mode === 'entry' ? 'Ingreso' : 'Salida',
            ]);

        // Cargar lecturas recientes registradas hoy en PostgreSQL (tanto ingresos como salidas)
        $today = now()->toDateString();
        $recentScans = AttendanceRecord::where('institution_id', $institutionId)
            ->where('date', $today)
            ->with(['enrollment.student', 'enrollment.section.grade'])
            ->orderByDesc('updated_at')
            ->take(20)
            ->get()
            ->map(function (AttendanceRecord $r) {
                $student = $r->enrollment?->student;
                $section = $r->enrollment?->section;
                $grade = $section?->grade;

                $latestAction = $r->exit_time ? 'exit' : 'entry';

                return [
                    'id' => $r->id,
                    'student_name' => $student ? $student->full_name : 'Estudiante no identificado',
                    'student_code' => $student?->code,
                    'classroom' => $grade && $section ? "{$grade->name} - {$section->name}" : 'Sin asignar',
                    'entry_time' => $r->entry_time ? substr((string) $r->entry_time, 0, 5) : null,
                    'exit_time' => $r->exit_time ? substr((string) $r->exit_time, 0, 5) : null,
                    'status' => $r->status, // 'present' o 'late'
                    'status_label' => $r->status === 'present' ? 'Puntual' : 'Tardanza',
                    'exit_type' => $r->exit_type,
                    'is_early_exit' => (bool) $r->is_early_exit,
                    'early_exit_reason' => $r->early_exit_reason,
                    'latest_action' => $latestAction,
                ];
            });

        return Inertia::render('attendance/Scanner', [
            'terminals' => $terminals,
            'entry_terminals' => $terminals->filter(fn ($t) => $t['mode'] === 'entry')->values(),
            'recent_scans' => $recentScans,
            'flash_scan' => session('last_scan'),
            'scan_error_detail' => session('scan_error_detail'),
            'early_exit_prompt' => session('early_exit_prompt'),
        ]);
    }

    /**
     * Enrutador inteligente de escaneo: despacha a registro de ingreso o salida según el modo de la terminal.
     */
    public function scan(Request $request): RedirectResponse
    {
        $action = $request->input('action');
        if ($action === 'entry') {
            return $this->registerEntry($request);
        }
        if ($action === 'exit') {
            return $this->registerExit($request);
        }

        $terminalId = $request->input('terminal_id');
        $terminal = AttendanceTerminal::find($terminalId);

        if ($terminal && $terminal->mode === 'exit') {
            return $this->registerExit($request);
        }

        return $this->registerEntry($request);
    }

    /**
     * Valida de manera estricta el escaneo QR rechazando códigos inválidos,
     * credenciales bloqueadas/invalidadas, estudiantes inactivos y lecturas duplicadas.
     *
     * @return array{
     *     terminal: AttendanceTerminal,
     *     credential: StudentCredential,
     *     student: Student,
     *     enrollment: Enrollment,
     *     shift: string,
     *     serverNow: Carbon,
     *     serverDate: string,
     *     serverTime: string,
     *     existingRecord: ?AttendanceRecord
     * }|RedirectResponse
     */
    private function validateAndResolveScan(Request $request, int $institutionId, string $expectedMode)
    {
        $rawQr = $request->input('qr_code');
        $terminalId = $request->input('terminal_id');

        // 1. Validar parámetros de entrada
        if (! is_string($rawQr) || trim($rawQr) === '') {
            return $this->scanError(
                'invalid_code',
                'Código QR Inválido',
                'Código QR inválido: El código proporcionado está vacío o no contiene caracteres válidos.'
            );
        }

        if (! $terminalId) {
            return $this->scanError(
                'invalid_terminal',
                'Terminal No Especificada',
                'Debe especificar una terminal autorizada para procesar el escaneo.'
            );
        }

        // 2. Validar terminal autorizada
        $terminal = AttendanceTerminal::where('institution_id', $institutionId)
            ->where('id', (int) $terminalId)
            ->first();

        if (! $terminal) {
            return $this->scanError(
                'invalid_terminal',
                'Terminal No Encontrada',
                'La terminal especificada no existe o no pertenece a esta institución.'
            );
        }

        if (! $terminal->is_active) {
            return $this->scanError(
                'inactive_terminal',
                'Terminal Inhabilitada',
                "La terminal [{$terminal->code}] está inhabilitada y no puede registrar asistencias."
            );
        }

        if ($terminal->mode !== $expectedMode) {
            $expectedLabel = $expectedMode === 'entry' ? 'Ingresos' : 'Salidas';
            $oppositeLabel = $expectedMode === 'entry' ? 'Salidas' : 'Ingresos';

            return $this->scanError(
                'terminal_mode_mismatch',
                'Modo de Terminal Inválido',
                "Modo no permitido: La terminal [{$terminal->code}] está configurada para {$oppositeLabel}. No puede registrar {$expectedLabel}."
            );
        }

        // 3. Validar código QR de la credencial
        $trimmedCode = trim($rawQr);

        $credential = StudentCredential::where('qr_code', $trimmedCode)
            ->where('institution_id', $institutionId)
            ->first();

        if (! $credential) {
            return $this->scanError(
                'invalid_code',
                'Código QR No Reconocido',
                'Código QR inválido: La credencial escaneada no existe o no pertenece a esta institución escolar.'
            );
        }

        // 4. Validar estado de la credencial (bloqueadas, invalidadas, etc.)
        if ($credential->status === 'blocked') {
            $student = $credential->student;
            $studentName = $student ? " de {$student->full_name}" : '';

            return $this->scanError(
                'blocked_credential',
                'Credencial Bloqueada',
                "Credencial QR bloqueada: La credencial{$studentName} se encuentra BLOQUEADA por seguridad o extravío. Diríjase a Dirección para su reactivación."
            );
        }

        if ($credential->status === 'invalidated') {
            $student = $credential->student;
            $studentName = $student ? " del alumno {$student->full_name}" : '';

            return $this->scanError(
                'invalidated_credential',
                'Credencial Invalidada',
                "Credencial QR invalidada: Esta credencial{$studentName} fue dada de baja tras emitirse un nuevo carnet. Utilice la credencial actualizada."
            );
        }

        if ($credential->status !== 'active') {
            return $this->scanError(
                'inactive_credential',
                'Credencial No Habilitada',
                "Credencial no autorizada: La credencial QR se encuentra en estado '{$credential->status}' y no está habilitada para el registro."
            );
        }

        // 5. Validar estudiante y estado activo
        $student = $credential->student;
        if (! $student) {
            return $this->scanError(
                'invalid_code',
                'Estudiante No Vinculado',
                'Código QR inválido: No se encontró ningún estudiante vinculado a esta credencial.'
            );
        }

        if (! $student->is_active) {
            return $this->scanError(
                'inactive_student',
                'Estudiante Inactivo',
                "Estudiante inactivo: El(la) estudiante {$student->full_name} se encuentra deshabilitado(a) en el sistema. No se permite registrar movimientos de asistencia."
            );
        }

        // 6. Validar matrícula y aula
        $enrollment = $student->currentEnrollment;
        if (! $enrollment) {
            $academicYear = AcademicYear::where('institution_id', $institutionId)->where('is_active', true)->first()
                ?? AcademicYear::firstOrCreate(
                    ['institution_id' => $institutionId, 'year' => (int) date('Y')],
                    ['name' => 'Año Escolar '.date('Y'), 'start_date' => date('Y').'-03-01', 'end_date' => date('Y').'-12-22', 'is_active' => true]
                );
            $defaultSection = Section::where('institution_id', $institutionId)->first();
            if ($defaultSection) {
                $enrollment = Enrollment::create([
                    'institution_id' => $institutionId,
                    'student_id' => $student->id,
                    'academic_year_id' => $academicYear->id,
                    'section_id' => $defaultSection->id,
                    'status' => 'active',
                ]);
            } else {
                return $this->scanError(
                    'unenrolled_student',
                    'Estudiante Sin Aula',
                    "El(la) estudiante {$student->full_name} no tiene aula o matrícula asignada para registrar asistencia."
                );
            }
        }

        $section = $enrollment->section;
        $shift = $section?->shift ?? 'Mañana';

        $serverNow = Carbon::now();
        $serverDate = $serverNow->toDateString();
        $serverTime = $serverNow->format('H:i:s');

        // 7. Validar lecturas duplicadas en PostgreSQL
        $existingRecord = AttendanceRecord::where('institution_id', $institutionId)
            ->where('enrollment_id', $enrollment->id)
            ->whereDate('date', $serverDate)
            ->where('shift', $shift)
            ->first();

        if ($expectedMode === 'entry' && $existingRecord && $existingRecord->entry_time) {
            $registeredTime = substr((string) $existingRecord->entry_time, 0, 5);
            $existingStatus = $existingRecord->status === 'present' ? 'Puntual' : 'Tardanza';

            return $this->scanError(
                'duplicate_scan',
                'Lectura Duplicada de Ingreso',
                "Lectura duplicada: {$student->full_name} ya registró su ingreso hoy a las {$registeredTime} ({$existingStatus}). No se generó ningún movimiento incorrecto."
            );
        }

        if ($expectedMode === 'exit' && $existingRecord && $existingRecord->exit_time) {
            $registeredTime = substr((string) $existingRecord->exit_time, 0, 5);
            $typeLabel = $existingRecord->exit_type === 'early' ? 'Salida Anticipada' : 'Salida Regular';

            return $this->scanError(
                'duplicate_scan',
                'Lectura Duplicada de Salida',
                "Lectura duplicada: {$student->full_name} ya registró su salida hoy a las {$registeredTime} ({$typeLabel}). No se generó ningún movimiento incorrecto."
            );
        }

        return [
            'terminal' => $terminal,
            'credential' => $credential,
            'student' => $student,
            'enrollment' => $enrollment,
            'shift' => $shift,
            'serverNow' => $serverNow,
            'serverDate' => $serverDate,
            'serverTime' => $serverTime,
            'existingRecord' => $existingRecord,
        ];
    }

    /**
     * Devuelve una redirección de error estructurada sin generar movimientos de asistencia.
     */
    private function scanError(string $type, string $title, string $message): RedirectResponse
    {
        session()->forget('last_scan');
        session()->forget('early_exit_prompt');

        return back()
            ->withErrors(['qr_code' => $message])
            ->with('scan_error_detail', [
                'type' => $type,
                'title' => $title,
                'message' => $message,
            ]);
    }

    /**
     * Registra el ingreso del estudiante al escanear el QR desde una terminal autorizada de entrada.
     */
    public function registerEntry(Request $request): RedirectResponse
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

        $validated = $this->validateAndResolveScan($request, $institutionId, 'entry');
        if ($validated instanceof RedirectResponse) {
            return $validated;
        }

        $terminal = $validated['terminal'];
        $student = $validated['student'];
        $enrollment = $validated['enrollment'];
        $shift = $validated['shift'];
        $serverNow = $validated['serverNow'];
        $serverDate = $validated['serverDate'];
        $serverTime = $validated['serverTime'];

        $schedule = AttendanceSchedule::where('institution_id', $institutionId)
            ->where('shift', $shift)
            ->first();

        $officialEntryTime = $schedule?->entry_time ?? ($shift === 'Tarde' ? '13:30:00' : '08:00:00');
        if (strlen($officialEntryTime) === 5) {
            $officialEntryTime .= ':00';
        }

        $isLate = strcmp($serverTime, $officialEntryTime) > 0;
        $status = $isLate ? 'late' : 'present';
        $statusLabel = $isLate ? 'Tardanza' : 'Puntual';

        $attendanceRecord = AttendanceRecord::updateOrCreate(
            [
                'institution_id' => $institutionId,
                'enrollment_id' => $enrollment->id,
                'date' => $serverDate,
                'shift' => $shift,
            ],
            [
                'academic_year_id' => $enrollment->academic_year_id,
                'section_id' => $enrollment->section_id,
                'session_type' => 'daily',
                'status' => $status,
                'entry_time' => $serverTime,
                'observation' => "Ingreso {$statusLabel} registrado por terminal [{$terminal->code}] ({$terminal->name})",
                'registered_by' => $user->id,
                'source' => 'qr_terminal',
            ]
        );

        $terminal->update(['last_heartbeat_at' => $serverNow]);

        $section = $enrollment->section;

        $scanData = [
            'action' => 'entry',
            'student' => [
                'id' => $student->id,
                'first_name' => $student->first_name,
                'last_name' => $student->last_name,
                'full_name' => $student->full_name,
                'code' => $student->code,
                'document_number' => $student->document_number,
                'avatar_url' => $student->avatar_url,
                'classroom' => $section && $section->grade ? "{$section->grade->name} - {$section->name}" : 'Sin aula',
                'shift' => $shift,
            ],
            'record' => [
                'id' => $attendanceRecord->id,
                'action' => 'entry',
                'status' => $status,
                'status_label' => $statusLabel,
                'entry_time' => $serverTime,
                'entry_time_formatted' => substr($serverTime, 0, 8),
                'official_entry_time' => substr($officialEntryTime, 0, 8),
                'is_late' => $isLate,
                'terminal_name' => $terminal->name,
                'terminal_code' => $terminal->code,
                'date' => $serverDate,
            ],
        ];

        session()->forget('scan_error_detail');

        return back()
            ->with('last_scan', $scanData)
            ->with('success', "Ingreso {$statusLabel} registrado para {$student->full_name} a las {$serverTime}.");
    }

    /**
     * Registra la salida del estudiante al escanear el QR desde una terminal autorizada de salida.
     * Distingue salidas regulares de anticipadas, solicitando autorización para estas últimas.
     */
    public function registerExit(Request $request): RedirectResponse
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

        $validated = $this->validateAndResolveScan($request, $institutionId, 'exit');
        if ($validated instanceof RedirectResponse) {
            return $validated;
        }

        $terminal = $validated['terminal'];
        $credential = $validated['credential'];
        $student = $validated['student'];
        $enrollment = $validated['enrollment'];
        $shift = $validated['shift'];
        $serverNow = $validated['serverNow'];
        $serverDate = $validated['serverDate'];
        $serverTime = $validated['serverTime'];
        $existingRecord = $validated['existingRecord'];
        $section = $enrollment->section;

        // Horario oficial de salida configurado en PostgreSQL para el turno
        $schedule = AttendanceSchedule::where('institution_id', $institutionId)
            ->where('shift', $shift)
            ->first();

        $officialExitTime = $schedule?->exit_time ?? ($shift === 'Tarde' ? '18:30:00' : '13:30:00');
        if (strlen($officialExitTime) === 5) {
            $officialExitTime .= ':00';
        }

        // Distinguir si es Salida Regular o Salida Anticipada
        $isEarlyExit = strcmp($serverTime, $officialExitTime) < 0;

        // Si es salida anticipada, SOLICITAR AUTORIZACIÓN OBLIGATORIA
        $isAuthorized = $request->boolean('authorized');
        $reason = trim((string) $request->input('authorization_reason', ''));

        if ($isEarlyExit && (! $isAuthorized || empty($reason))) {
            $earlyExitPrompt = [
                'requires_authorization' => true,
                'student' => [
                    'id' => $student->id,
                    'full_name' => $student->full_name,
                    'code' => $student->code,
                    'document_number' => $student->document_number,
                    'classroom' => $section && $section->grade ? "{$section->grade->name} - {$section->name}" : 'Sin aula',
                    'shift' => $shift,
                    'avatar_url' => $student->avatar_url,
                ],
                'qr_code' => $credential->qr_code,
                'terminal_id' => $terminal->id,
                'server_time' => substr($serverTime, 0, 8),
                'official_exit_time' => substr($officialExitTime, 0, 8),
            ];

            session()->forget('last_scan');

            return back()
                ->with('early_exit_prompt', $earlyExitPrompt)
                ->withErrors([
                    'early_exit_required' => "Salida Anticipada detectada a las {$serverTime} (Hora oficial: {$officialExitTime}). Se requiere autorización y motivo de retiro.",
                ]);
        }

        $exitType = $isEarlyExit ? 'early' : 'regular';
        $exitTypeLabel = $isEarlyExit ? 'Salida Anticipada' : 'Salida Regular';

        // Guardar o actualizar registro de salida en PostgreSQL
        if ($existingRecord) {
            $existingRecord->update([
                'exit_time' => $serverTime,
                'exit_type' => $exitType,
                'is_early_exit' => $isEarlyExit,
                'early_exit_reason' => $isEarlyExit ? $reason : null,
                'early_exit_authorized_by' => $isEarlyExit ? $user->id : null,
                'exit_registered_by' => $user->id,
                'observation' => trim(($existingRecord->observation ?? '')." | Salida {$exitTypeLabel} registrada por terminal [{$terminal->code}]".($isEarlyExit ? " [Motivo: {$reason}]" : '')),
            ]);
            $attendanceRecord = $existingRecord;
        } else {
            $attendanceRecord = AttendanceRecord::create([
                'institution_id' => $institutionId,
                'enrollment_id' => $enrollment->id,
                'academic_year_id' => $enrollment->academic_year_id,
                'section_id' => $enrollment->section_id,
                'date' => $serverDate,
                'shift' => $shift,
                'session_type' => 'daily',
                'status' => 'present',
                'exit_time' => $serverTime,
                'exit_type' => $exitType,
                'is_early_exit' => $isEarlyExit,
                'early_exit_reason' => $isEarlyExit ? $reason : null,
                'early_exit_authorized_by' => $isEarlyExit ? $user->id : null,
                'exit_registered_by' => $user->id,
                'registered_by' => $user->id,
                'source' => 'qr_terminal',
                'observation' => "Salida {$exitTypeLabel} registrada por terminal [{$terminal->code}]".($isEarlyExit ? " [Motivo: {$reason}]" : ''),
            ]);
        }

        $terminal->update(['last_heartbeat_at' => $serverNow]);

        $scanData = [
            'action' => 'exit',
            'student' => [
                'id' => $student->id,
                'first_name' => $student->first_name,
                'last_name' => $student->last_name,
                'full_name' => $student->full_name,
                'code' => $student->code,
                'document_number' => $student->document_number,
                'avatar_url' => $student->avatar_url,
                'classroom' => $section && $section->grade ? "{$section->grade->name} - {$section->name}" : 'Sin aula',
                'shift' => $shift,
            ],
            'record' => [
                'id' => $attendanceRecord->id,
                'action' => 'exit',
                'exit_type' => $exitType,
                'exit_type_label' => $exitTypeLabel,
                'exit_time' => $serverTime,
                'exit_time_formatted' => substr($serverTime, 0, 8),
                'official_exit_time' => substr($officialExitTime, 0, 8),
                'is_early_exit' => $isEarlyExit,
                'early_exit_reason' => $isEarlyExit ? $reason : null,
                'terminal_name' => $terminal->name,
                'terminal_code' => $terminal->code,
                'date' => $serverDate,
            ],
        ];

        session()->forget('scan_error_detail');

        return back()
            ->with('last_scan', $scanData)
            ->with('success', "{$exitTypeLabel} registrada exitosamente para {$student->full_name} a las {$serverTime} en PostgreSQL.");
    }
}
