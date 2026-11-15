<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AcademicCalendarDay;
use App\Models\AcademicYear;
use App\Models\AttendanceSchedule;
use App\Models\AttendanceTerminal;
use App\Models\Institution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
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

        // Asegurar que existan los turnos básicos en PostgreSQL para la institución
        $defaultShifts = [
            [
                'name' => 'Turno Mañana (Primaria y Secundaria)',
                'shift' => 'Mañana',
                'entry_time' => '08:00:00',
                'exit_time' => '13:30:00',
            ],
            [
                'name' => 'Turno Tarde (Primaria y Secundaria)',
                'shift' => 'Tarde',
                'entry_time' => '13:30:00',
                'exit_time' => '18:30:00',
            ],
        ];

        foreach ($defaultShifts as $ds) {
            AttendanceSchedule::firstOrCreate(
                [
                    'institution_id' => $institutionId,
                    'shift' => $ds['shift'],
                ],
                [
                    'name' => $ds['name'],
                    'entry_time' => $ds['entry_time'],
                    'exit_time' => $ds['exit_time'],
                    'tolerance_minutes' => 0,
                    'is_active' => true,
                ]
            );
        }

        // Cargar horarios ordenados por turno desde PostgreSQL
        $schedules = AttendanceSchedule::where('institution_id', $institutionId)
            ->orderByRaw("CASE WHEN shift = 'Mañana' THEN 1 WHEN shift = 'Tarde' THEN 2 ELSE 3 END")
            ->get()
            ->map(fn (AttendanceSchedule $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'shift' => $s->shift,
                'entry_time' => substr($s->entry_time, 0, 5),
                'exit_time' => $s->exit_time ? substr($s->exit_time, 0, 5) : '13:30',
                'tolerance_minutes' => 0,
                'is_active' => (bool) $s->is_active,
            ]);

        // Cargar días del calendario escolar institucional desde PostgreSQL
        $calendarDays = AcademicCalendarDay::where('institution_id', $institutionId)
            ->orderBy('date')
            ->get()
            ->map(fn (AcademicCalendarDay $d) => [
                'id' => $d->id,
                'date' => $d->date?->format('Y-m-d') ?? (string) $d->date,
                'day_type' => $d->day_type,
                'title' => $d->title,
                'description' => $d->description,
                'is_attendance_allowed' => (bool) $d->is_attendance_allowed,
            ]);

        // Cargar terminales QR autorizados desde PostgreSQL
        $terminals = AttendanceTerminal::where('institution_id', $institutionId)
            ->orderBy('name')
            ->get()
            ->map(fn (AttendanceTerminal $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'code' => $t->code,
                'mode' => $t->mode, // Exclusivamente 'entry' (Ingreso) o 'exit' (Salida)
                'mode_label' => $t->mode === 'entry' ? 'Ingreso' : 'Salida',
                'location' => $t->location,
                'device_identifier' => $t->device_identifier,
                'description' => $t->description,
                'is_active' => (bool) $t->is_active,
                'last_heartbeat_at' => $t->last_heartbeat_at?->format('d/m/Y H:i'),
            ]);

        return Inertia::render('attendance/Settings', [
            'schedules' => $schedules,
            'calendar_days' => $calendarDays,
            'terminals' => $terminals,
        ]);
    }

    public function updateSchedules(Request $request): RedirectResponse
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
            'schedules' => ['required', 'array', 'min:1'],
            'schedules.*.shift' => ['required', 'string', 'max:50'],
            'schedules.*.name' => ['nullable', 'string', 'max:100'],
            'schedules.*.entry_time' => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'schedules.*.exit_time' => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
        ]);

        DB::transaction(function () use ($validated, $institutionId) {
            foreach ($validated['schedules'] as $item) {
                AttendanceSchedule::updateOrCreate(
                    [
                        'institution_id' => $institutionId,
                        'shift' => $item['shift'],
                    ],
                    [
                        'name' => $item['name'] ?? "Turno {$item['shift']}",
                        'entry_time' => $item['entry_time'].':00',
                        'exit_time' => $item['exit_time'].':00',
                        'tolerance_minutes' => 0, // Sin tolerancia: cualquier ingreso posterior es tardanza
                        'is_active' => true,
                    ]
                );
            }
        });

        return back()->with('success', 'Horarios de ingreso y salida por turno actualizados correctamente en PostgreSQL.');
    }

    public function storeCalendarDay(Request $request): RedirectResponse
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
            'day_type' => ['required', 'string', 'in:school_day,holiday,suspension'],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
        ], [
            'date.required' => 'La fecha es obligatoria.',
            'date.date' => 'Debe ingresar una fecha válida.',
            'day_type.required' => 'Seleccione el tipo de día escolar.',
            'day_type.in' => 'El tipo de día debe ser lectivo, feriado o suspensión.',
            'title.required' => 'El título o motivo es obligatorio.',
        ]);

        $academicYear = AcademicYear::where('institution_id', $institutionId)
            ->where('is_active', true)
            ->first();

        AcademicCalendarDay::updateOrCreate(
            [
                'institution_id' => $institutionId,
                'date' => $validated['date'],
            ],
            [
                'academic_year_id' => $academicYear?->id,
                'day_type' => $validated['day_type'],
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'is_attendance_allowed' => $validated['day_type'] === 'school_day',
            ]
        );

        $typeLabels = [
            'school_day' => 'día lectivo',
            'holiday' => 'feriado',
            'suspension' => 'suspensión de actividades',
        ];
        $label = $typeLabels[$validated['day_type']] ?? 'día';

        return back()->with('success', "El {$label} '{$validated['title']}' se guardó exitosamente en el calendario escolar de PostgreSQL.");
    }

    public function deleteCalendarDay(Request $request, AcademicCalendarDay $calendarDay): RedirectResponse
    {
        if ($calendarDay->institution_id !== $request->user()->institution_id) {
            abort(403);
        }

        $title = $calendarDay->title;
        $calendarDay->delete();

        return back()->with('success', "Se eliminó '{$title}' del calendario escolar.");
    }

    /**
     * Registra un nuevo terminal QR autorizado asignándole exclusivamente el modo Ingreso o Salida.
     */
    public function storeTerminal(Request $request): RedirectResponse
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
            'name' => ['required', 'string', 'max:150'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('attendance_terminals', 'code')->where('institution_id', $institutionId),
            ],
            'mode' => ['required', 'string', 'in:entry,exit'],
            'location' => ['nullable', 'string', 'max:150'],
            'device_identifier' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'name.required' => 'El nombre del terminal es obligatorio.',
            'code.required' => 'El código de identificación del terminal es obligatorio.',
            'code.unique' => 'Ya existe un terminal registrado con este código en la institución.',
            'mode.required' => 'Debe asignar exclusivamente el modo Ingreso o Salida.',
            'mode.in' => 'El modo asignado debe ser exclusivamente Ingreso (entry) o Salida (exit).',
        ]);

        AttendanceTerminal::create([
            'institution_id' => $institutionId,
            'name' => $validated['name'],
            'code' => strtoupper(trim($validated['code'])),
            'mode' => $validated['mode'],
            'location' => $validated['location'] ?? null,
            'device_identifier' => $validated['device_identifier'] ?? null,
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        $modeLabel = $validated['mode'] === 'entry' ? 'Ingreso' : 'Salida';

        return back()->with('success', "Terminal QR '{$validated['name']}' registrado en modo exclusivo de {$modeLabel} en PostgreSQL.");
    }

    /**
     * Actualiza la configuración del terminal QR autorizado.
     */
    public function updateTerminal(Request $request, AttendanceTerminal $terminal): RedirectResponse
    {
        $user = $request->user();
        if ($terminal->institution_id !== $user->institution_id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('attendance_terminals', 'code')
                    ->where('institution_id', $terminal->institution_id)
                    ->ignore($terminal->id),
            ],
            'mode' => ['required', 'string', 'in:entry,exit'],
            'location' => ['nullable', 'string', 'max:150'],
            'device_identifier' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'name.required' => 'El nombre del terminal es obligatorio.',
            'code.required' => 'El código del terminal es obligatorio.',
            'code.unique' => 'Ya existe otro terminal con este código.',
            'mode.required' => 'Debe asignar exclusivamente el modo Ingreso o Salida.',
            'mode.in' => 'El modo asignado debe ser exclusivamente Ingreso o Salida.',
        ]);

        $terminal->update([
            'name' => $validated['name'],
            'code' => strtoupper(trim($validated['code'])),
            'mode' => $validated['mode'],
            'location' => $validated['location'] ?? null,
            'device_identifier' => $validated['device_identifier'] ?? null,
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active', $terminal->is_active),
        ]);

        $modeLabel = $terminal->mode === 'entry' ? 'Ingreso' : 'Salida';

        return back()->with('success', "Configuración de terminal QR actualizada (Modo exclusivo: {$modeLabel}) en PostgreSQL.");
    }

    /**
     * Habilita o inhabilita la autorización del terminal QR.
     */
    public function toggleTerminalStatus(Request $request, AttendanceTerminal $terminal): RedirectResponse
    {
        $user = $request->user();
        if ($terminal->institution_id !== $user->institution_id) {
            abort(403);
        }

        $terminal->update([
            'is_active' => ! $terminal->is_active,
        ]);

        $msg = $terminal->is_active
            ? "Terminal '{$terminal->name}' autorizado y activo para registro de asistencia."
            : "Terminal '{$terminal->name}' desautorizado e inhabilitado.";

        return back()->with('success', $msg);
    }

    /**
     * Elimina el terminal QR de PostgreSQL.
     */
    public function deleteTerminal(Request $request, AttendanceTerminal $terminal): RedirectResponse
    {
        $user = $request->user();
        if ($terminal->institution_id !== $user->institution_id) {
            abort(403);
        }

        $name = $terminal->name;
        $terminal->delete();

        return back()->with('success', "Terminal QR '{$name}' eliminado de PostgreSQL.");
    }
}
