<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            {
                title: 'Asistencia',
                href: '/attendance/overview',
            },
            {
                title: 'Configuración de Asistencia',
                href: '/attendance/settings',
            },
        ],
    };
</script>

<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import ArrowDownRight from '@lucide/svelte/icons/arrow-down-right';
    import ArrowUpRight from '@lucide/svelte/icons/arrow-up-right';
    import Ban from '@lucide/svelte/icons/ban';
    import Bell from '@lucide/svelte/icons/bell';
    import Calendar from '@lucide/svelte/icons/calendar';
    import CheckCircle2 from '@lucide/svelte/icons/check-circle-2';
    import Clock from '@lucide/svelte/icons/clock';
    import Plus from '@lucide/svelte/icons/plus';
    import QrCode from '@lucide/svelte/icons/qr-code';
    import RefreshCw from '@lucide/svelte/icons/refresh-cw';
    import Save from '@lucide/svelte/icons/save';
    import Sliders from '@lucide/svelte/icons/sliders';
    import Trash2 from '@lucide/svelte/icons/trash-2';
    import X from '@lucide/svelte/icons/x';
    import { toast } from 'svelte-sonner';
    import AppHead from '@/components/AppHead.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
    import { Input } from '@/components/ui/input';

    interface ScheduleItem {
        id: number;
        name: string;
        shift: string;
        entry_time: string;
        exit_time: string;
        tolerance_minutes: number;
        is_active: boolean;
    }

    interface CalendarDayItem {
        id: number;
        date: string;
        day_type: 'school_day' | 'holiday' | 'suspension';
        title: string;
        description: string | null;
        is_attendance_allowed: boolean;
    }

    interface TerminalItem {
        id: number;
        name: string;
        code: string;
        mode: 'entry' | 'exit';
        mode_label: string;
        location: string | null;
        device_identifier: string | null;
        description: string | null;
        is_active: boolean;
        last_heartbeat_at: string | null;
    }

    let {
        schedules = [],
        calendar_days = [],
        terminals = [],
    }: {
        schedules: ScheduleItem[];
        calendar_days?: CalendarDayItem[];
        terminals?: TerminalItem[];
    } = $props();

    let activeTab = $state<'schedules' | 'calendar' | 'devices' | 'alerts'>('schedules');
    let localSchedules = $state<ScheduleItem[]>([]);
    let isSaving = $state(false);

    // Estado para el modal de agregar día al calendario
    let isCalendarModalOpen = $state(false);
    let calendarDate = $state('');
    let calendarDayType = $state<'school_day' | 'holiday' | 'suspension'>('holiday');
    let calendarTitle = $state('');
    let calendarDescription = $state('');
    let isSavingCalendarDay = $state(false);
    let calendarErrors = $state<Record<string, string>>({});

    // Estado para el modal de terminales QR
    let isTerminalModalOpen = $state(false);
    let editingTerminal = $state<TerminalItem | null>(null);
    let terminalName = $state('');
    let terminalCode = $state('');
    let terminalMode = $state<'entry' | 'exit'>('entry');
    let terminalLocation = $state('');
    let terminalIdentifier = $state('');
    let terminalDescription = $state('');
    let terminalIsActive = $state(true);
    let isSavingTerminal = $state(false);
    let terminalErrors = $state<Record<string, string>>({});

    $effect(() => {
        if (schedules && schedules.length > 0) {
            localSchedules = schedules.map((s) => ({ ...s }));
        } else {
            localSchedules = [
                {
                    id: 1,
                    name: 'Turno Mañana (Primaria y Secundaria)',
                    shift: 'Mañana',
                    entry_time: '08:00',
                    exit_time: '13:30',
                    tolerance_minutes: 0,
                    is_active: true,
                },
                {
                    id: 2,
                    name: 'Turno Tarde (Primaria y Secundaria)',
                    shift: 'Tarde',
                    entry_time: '13:30',
                    exit_time: '18:30',
                    tolerance_minutes: 0,
                    is_active: true,
                },
            ];
        }
    });

    function saveSchedules() {
        isSaving = true;
        router.put(
            '/attendance/settings/schedules',
            {
                schedules: localSchedules.map((s) => ({
                    shift: s.shift,
                    name: s.name,
                    entry_time: s.entry_time,
                    exit_time: s.exit_time,
                })),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    isSaving = false;
                    toast.success('Horarios de ingreso y salida por turno guardados exitosamente en PostgreSQL.');
                },
                onError: (errs) => {
                    isSaving = false;
                    const msg = Object.values(errs)[0] as string;
                    toast.error(msg || 'Error al guardar los horarios.');
                },
            },
        );
    }

    function openCalendarModal() {
        calendarDate = '';
        calendarDayType = 'holiday';
        calendarTitle = '';
        calendarDescription = '';
        calendarErrors = {};
        isCalendarModalOpen = true;
    }

    function saveCalendarDay() {
        const errors: Record<string, string> = {};
        if (!calendarDate) {
            errors.date = 'Seleccione una fecha válida.';
        }
        if (!calendarTitle.trim()) {
            errors.title = 'Ingrese un título o motivo.';
        }
        if (Object.keys(errors).length > 0) {
            calendarErrors = errors;
            return;
        }

        isSavingCalendarDay = true;
        router.post(
            '/attendance/settings/calendar-days',
            {
                date: calendarDate,
                day_type: calendarDayType,
                title: calendarTitle.trim(),
                description: calendarDescription.trim() || undefined,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    isSavingCalendarDay = false;
                    isCalendarModalOpen = false;
                    toast.success('Día registrado exitosamente en el calendario escolar de PostgreSQL.');
                },
                onError: (errs) => {
                    isSavingCalendarDay = false;
                    calendarErrors = errs as Record<string, string>;
                    const msg = Object.values(errs)[0] as string;
                    toast.error(msg || 'Error al guardar el día.');
                },
            },
        );
    }

    function deleteCalendarDay(day: CalendarDayItem) {
        if (!confirm(`¿Eliminar "${day.title}" (${day.date}) del calendario escolar?`)) {
            return;
        }

        router.delete(`/attendance/settings/calendar-days/${day.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Día eliminado del calendario escolar.');
            },
            onError: () => {
                toast.error('No se pudo eliminar el registro.');
            },
        });
    }

    function openCreateTerminalModal() {
        editingTerminal = null;
        terminalName = '';
        terminalCode = '';
        terminalMode = 'entry'; // Exclusivamente Ingreso o Salida
        terminalLocation = '';
        terminalIdentifier = '';
        terminalDescription = '';
        terminalIsActive = true;
        terminalErrors = {};
        isTerminalModalOpen = true;
    }

    function openEditTerminalModal(terminal: TerminalItem) {
        editingTerminal = terminal;
        terminalName = terminal.name;
        terminalCode = terminal.code;
        terminalMode = terminal.mode; // Exclusivamente Ingreso o Salida
        terminalLocation = terminal.location || '';
        terminalIdentifier = terminal.device_identifier || '';
        terminalDescription = terminal.description || '';
        terminalIsActive = terminal.is_active;
        terminalErrors = {};
        isTerminalModalOpen = true;
    }

    function saveTerminal() {
        const errors: Record<string, string> = {};
        if (!terminalName.trim()) {
            errors.name = 'Ingrese el nombre del terminal.';
        }
        if (!terminalCode.trim()) {
            errors.code = 'Ingrese el código identificador del terminal.';
        }
        if (terminalMode !== 'entry' && terminalMode !== 'exit') {
            errors.mode = 'El modo asignado debe ser exclusivamente Ingreso o Salida.';
        }
        if (Object.keys(errors).length > 0) {
            terminalErrors = errors;
            return;
        }

        isSavingTerminal = true;
        const payload = {
            name: terminalName.trim(),
            code: terminalCode.trim().toUpperCase(),
            mode: terminalMode,
            location: terminalLocation.trim() || undefined,
            device_identifier: terminalIdentifier.trim() || undefined,
            description: terminalDescription.trim() || undefined,
            is_active: terminalIsActive,
        };

        if (editingTerminal) {
            router.put(`/attendance/settings/terminals/${editingTerminal.id}`, payload, {
                preserveScroll: true,
                onSuccess: () => {
                    isSavingTerminal = false;
                    isTerminalModalOpen = false;
                    toast.success('Terminal QR actualizado exitosamente en PostgreSQL.');
                },
                onError: (errs) => {
                    isSavingTerminal = false;
                    terminalErrors = errs as Record<string, string>;
                    const msg = Object.values(errs)[0] as string;
                    toast.error(msg || 'Error al actualizar el terminal.');
                },
            });
        } else {
            router.post('/attendance/settings/terminals', payload, {
                preserveScroll: true,
                onSuccess: () => {
                    isSavingTerminal = false;
                    isTerminalModalOpen = false;
                    toast.success('Terminal QR registrado exitosamente en PostgreSQL.');
                },
                onError: (errs) => {
                    isSavingTerminal = false;
                    terminalErrors = errs as Record<string, string>;
                    const msg = Object.values(errs)[0] as string;
                    toast.error(msg || 'Error al registrar el terminal.');
                },
            });
        }
    }

    function toggleTerminal(terminal: TerminalItem) {
        router.patch(`/attendance/settings/terminals/${terminal.id}/toggle-status`, {}, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Estado del terminal actualizado en PostgreSQL.');
            },
            onError: () => {
                toast.error('Error al actualizar el estado del terminal.');
            },
        });
    }

    function deleteTerminal(terminal: TerminalItem) {
        if (!confirm(`¿Eliminar el terminal QR "${terminal.name}" (${terminal.code}) de PostgreSQL?`)) {
            return;
        }

        router.delete(`/attendance/settings/terminals/${terminal.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Terminal QR eliminado de PostgreSQL.');
            },
            onError: () => {
                toast.error('No se pudo eliminar el terminal.');
            },
        });
    }
</script>

<AppHead title="Configuración del Módulo de Asistencia QR" />

<div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
    <!-- Encabezado -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground">
                Configuración del Sistema de Asistencia
            </h1>
            <p class="text-sm text-muted-foreground">
                Parámetros de horarios de ingreso y salida escolar, calendario institucional y lectores QR.
            </p>
        </div>

        <Button
            onclick={saveSchedules}
            disabled={isSaving}
            class="bg-primary text-xs shadow-xs"
        >
            {#if isSaving}
                <RefreshCw class="mr-1.5 size-4 animate-spin" />
                <span>Guardando...</span>
            {:else}
                <Save class="mr-1.5 size-4" />
                <span>Guardar Horarios</span>
            {/if}
        </Button>
    </div>

    <!-- Pestañas de Navegación de Configuración -->
    <div class="flex flex-wrap items-center gap-1.5 border-b border-border/70 pb-2 text-xs font-medium">
        <button
            type="button"
            onclick={() => (activeTab = 'schedules')}
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-md transition-all {activeTab === 'schedules' ? 'bg-primary text-primary-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:bg-muted'}"
        >
            <Clock class="size-3.5" />
            <span>Horarios Escolares</span>
        </button>

        <button
            type="button"
            onclick={() => (activeTab = 'calendar')}
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-md transition-all {activeTab === 'calendar' ? 'bg-primary text-primary-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:bg-muted'}"
        >
            <Calendar class="size-3.5" />
            <span>Calendario Escolar</span>
        </button>

        <button
            type="button"
            onclick={() => (activeTab = 'devices')}
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-md transition-all {activeTab === 'devices' ? 'bg-primary text-primary-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:bg-muted'}"
        >
            <QrCode class="size-3.5" />
            <span>Dispositivos Lectores y Puertas</span>
        </button>

        <button
            type="button"
            onclick={() => (activeTab = 'alerts')}
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-md transition-all {activeTab === 'alerts' ? 'bg-primary text-primary-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:bg-muted'}"
        >
            <Bell class="size-3.5" />
            <span>Alertas y Notificaciones</span>
        </button>
    </div>

    <!-- Contenido de la Pestaña Activa -->
    {#if activeTab === 'schedules'}
        <div class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                {#each localSchedules as schedule, idx (schedule.shift)}
                    <Card class="border-border/60 shadow-xs">
                        <CardHeader class="pb-3 flex flex-row items-center justify-between">
                            <div>
                                <CardTitle class="text-sm font-semibold">{schedule.name || `Turno ${schedule.shift}`}</CardTitle>
                                <p class="text-[11px] text-muted-foreground mt-0.5">Control de jornada escolar para {schedule.shift}</p>
                            </div>
                            <Badge variant="outline" class="border-rose-500/40 bg-rose-50/50 text-rose-700 dark:bg-rose-950/20 dark:text-rose-400 text-[10px] font-semibold">
                                Sin tolerancia (0 min)
                            </Badge>
                        </CardHeader>
                        <CardContent class="space-y-4 text-xs">
                            <div>
                                <label for={`entry-time-${idx}`} class="block font-medium text-foreground mb-1">
                                    Hora Oficial de Entrada ({schedule.shift}) <span class="text-rose-500">*</span>
                                </label>
                                <Input
                                    id={`entry-time-${idx}`}
                                    type="time"
                                    bind:value={schedule.entry_time}
                                    class="h-9 font-mono text-xs"
                                />
                                <div class="mt-1.5 rounded-md bg-amber-500/10 border border-amber-500/20 p-2 text-[11px] text-amber-800 dark:text-amber-300">
                                    <strong>Puntualidad estricta:</strong> Si el estudiante ingresa un segundo después de las {schedule.entry_time || '08:00'}, se considerará <strong>tardanza</strong> (sin minutos de tolerancia).
                                </div>
                            </div>

                            <div>
                                <label for={`exit-time-${idx}`} class="block font-medium text-foreground mb-1">
                                    Hora Oficial de Salida ({schedule.shift}) <span class="text-rose-500">*</span>
                                </label>
                                <Input
                                    id={`exit-time-${idx}`}
                                    type="time"
                                    bind:value={schedule.exit_time}
                                    class="h-9 font-mono text-xs"
                                />
                                <p class="text-[11px] text-muted-foreground mt-0.5">
                                    Hora establecida en la que culmina la jornada lectiva regular del turno.
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                {/each}
            </div>

            <!-- Parámetros Generales de Zona Horaria y Retroactividad -->
            <Card class="border-border/60 shadow-xs">
                <CardHeader>
                    <CardTitle class="text-sm font-semibold">Parámetros de Zona Horaria y Retroactividad</CardTitle>
                </CardHeader>
                <CardContent class="space-y-4 text-xs">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="tz-official" class="block font-medium text-foreground mb-1">Zona Horaria Oficial</label>
                            <Input id="tz-official" value="America/Lima (UTC-5:00)" disabled class="h-9 bg-muted/40 font-mono" />
                        </div>

                        <div>
                            <label for="max-retroactive-days" class="block font-medium text-foreground mb-1">Días Máximos para Corrección Retroactiva (Docentes)</label>
                            <Input id="max-retroactive-days" type="number" value="2" class="h-9" />
                            <p class="text-[11px] text-muted-foreground mt-0.5">Los administradores conservan permisos ilimitados con auditoría</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 pt-1 border-t border-border/40">
                        <input type="checkbox" id="allow-manual-override" checked class="rounded border-input text-primary" />
                        <label for="allow-manual-override" class="text-foreground cursor-pointer">
                            Permitir corrección manual con registro obligatorio de motivo justificado
                        </label>
                    </div>
                </CardContent>
            </Card>
        </div>
    {:else if activeTab === 'calendar'}
        <div class="space-y-4">
            <!-- Resumen de Métricas del Calendario -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <Card class="border-border/60 shadow-xs">
                    <CardContent class="p-4 flex items-center justify-between">
                        <div>
                            <p class="text-xs font-medium text-muted-foreground">Feriados Oficiales</p>
                            <p class="text-2xl font-bold text-rose-600">
                                {calendar_days.filter((d) => d.day_type === 'holiday').length}
                            </p>
                            <p class="text-[11px] text-muted-foreground">Asistencia no permitida</p>
                        </div>
                        <div class="rounded-full bg-rose-500/10 p-2.5 text-rose-600">
                            <Calendar class="size-5" />
                        </div>
                    </CardContent>
                </Card>

                <Card class="border-border/60 shadow-xs">
                    <CardContent class="p-4 flex items-center justify-between">
                        <div>
                            <p class="text-xs font-medium text-muted-foreground">Suspensiones de Clases</p>
                            <p class="text-2xl font-bold text-amber-600">
                                {calendar_days.filter((d) => d.day_type === 'suspension').length}
                            </p>
                            <p class="text-[11px] text-muted-foreground">Eventos o contingencias</p>
                        </div>
                        <div class="rounded-full bg-amber-500/10 p-2.5 text-amber-600">
                            <Clock class="size-5" />
                        </div>
                    </CardContent>
                </Card>

                <Card class="border-border/60 shadow-xs">
                    <CardContent class="p-4 flex items-center justify-between">
                        <div>
                            <p class="text-xs font-medium text-muted-foreground">Días Lectivos Extraordinarios</p>
                            <p class="text-2xl font-bold text-emerald-600">
                                {calendar_days.filter((d) => d.day_type === 'school_day').length}
                            </p>
                            <p class="text-[11px] text-muted-foreground">Recuperación de clases</p>
                        </div>
                        <div class="rounded-full bg-emerald-500/10 p-2.5 text-emerald-600">
                            <CheckCircle2 class="size-5" />
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- Tabla de Días Especiales / Excepciones -->
            <Card class="border-border/60 shadow-xs">
                <CardHeader class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between pb-3">
                    <div>
                        <CardTitle class="text-sm font-semibold">Calendario Escolar y Días Especiales</CardTitle>
                        <p class="text-xs text-muted-foreground">
                            Define días lectivos, feriados y suspensiones guardados en PostgreSQL para validar el control de asistencia.
                        </p>
                    </div>
                    <Button onclick={openCalendarModal} size="sm" class="bg-primary text-xs shadow-xs self-start sm:self-auto">
                        <Plus class="mr-1.5 size-4" />
                        <span>Agregar Día / Excepción</span>
                    </Button>
                </CardHeader>
                <CardContent class="p-0">
                    {#if calendar_days.length === 0}
                        <div class="flex flex-col items-center justify-center p-8 text-center text-muted-foreground">
                            <Calendar class="size-10 text-muted-foreground/40 mb-2" />
                            <p class="text-sm font-medium text-foreground">No hay días registrados en el calendario escolar</p>
                            <p class="text-xs text-muted-foreground max-w-md mt-1">
                                Las jornadas de lunes a viernes operarán de forma regular según los horarios por turno definidos en PostgreSQL.
                            </p>
                            <Button onclick={openCalendarModal} variant="outline" size="sm" class="mt-4 text-xs">
                                <Plus class="mr-1.5 size-3.5" />
                                Registrar primer feriado o suspensión
                            </Button>
                        </div>
                    {:else}
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-xs min-w-[550px]">
                                <thead class="border-y border-border/60 bg-muted/30 text-muted-foreground font-medium">
                                    <tr>
                                        <th class="py-2.5 px-4">Fecha</th>
                                        <th class="py-2.5 px-4">Tipo de Día</th>
                                        <th class="py-2.5 px-4">Título / Motivo</th>
                                        <th class="py-2.5 px-4">Descripción</th>
                                        <th class="py-2.5 px-4">Control de Asistencia</th>
                                        <th class="py-2.5 px-4 text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border/50">
                                    {#each calendar_days as day (day.id)}
                                        <tr class="hover:bg-muted/20 transition-colors">
                                            <td class="py-3 px-4 font-mono font-medium text-foreground whitespace-nowrap">
                                                {day.date}
                                            </td>
                                            <td class="py-3 px-4 whitespace-nowrap">
                                                {#if day.day_type === 'holiday'}
                                                    <Badge variant="outline" class="border-rose-500/40 text-rose-600 bg-rose-50/50 dark:bg-rose-950/20 text-[11px]">
                                                        Feriado
                                                    </Badge>
                                                {:else if day.day_type === 'suspension'}
                                                    <Badge variant="outline" class="border-amber-500/40 text-amber-600 bg-amber-50/50 dark:bg-amber-950/20 text-[11px]">
                                                        Suspensión
                                                    </Badge>
                                                {:else}
                                                    <Badge variant="outline" class="border-emerald-500/40 text-emerald-600 bg-emerald-50/50 dark:bg-emerald-950/20 text-[11px]">
                                                        Día Lectivo
                                                    </Badge>
                                                {/if}
                                            </td>
                                            <td class="py-3 px-4 font-medium text-foreground">
                                                {day.title}
                                            </td>
                                            <td class="py-3 px-4 text-muted-foreground max-w-xs truncate">
                                                {day.description || '—'}
                                            </td>
                                            <td class="py-3 px-4 whitespace-nowrap">
                                                {#if day.is_attendance_allowed}
                                                    <span class="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-600">
                                                        <CheckCircle2 class="size-3.5" />
                                                        Habilitada
                                                    </span>
                                                {:else}
                                                    <span class="inline-flex items-center gap-1 text-[11px] font-medium text-rose-600">
                                                        <X class="size-3.5" />
                                                        Bloqueada
                                                    </span>
                                                {/if}
                                            </td>
                                            <td class="py-3 px-4 text-right whitespace-nowrap">
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    onclick={() => deleteCalendarDay(day)}
                                                    class="size-7 text-muted-foreground hover:text-destructive hover:bg-destructive/10"
                                                    title="Eliminar del calendario"
                                                >
                                                    <Trash2 class="size-3.5" />
                                                </Button>
                                            </td>
                                        </tr>
                                    {/each}
                                </tbody>
                            </table>
                        </div>
                    {/if}
                </CardContent>
            </Card>
        </div>
    {:else if activeTab === 'devices'}
        <div class="space-y-4">
            <!-- Resumen de Métricas de Terminales QR -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <Card class="border-border/60 shadow-xs">
                    <CardContent class="p-4 flex items-center justify-between">
                        <div>
                            <p class="text-xs font-medium text-muted-foreground">Terminales Autorizados</p>
                            <p class="text-2xl font-bold text-foreground">
                                {terminals.filter((t) => t.is_active).length} / {terminals.length}
                            </p>
                            <p class="text-[11px] text-muted-foreground">Lectores activos en portería</p>
                        </div>
                        <div class="rounded-full bg-primary/10 p-2.5 text-primary">
                            <QrCode class="size-5" />
                        </div>
                    </CardContent>
                </Card>

                <Card class="border-emerald-500/20 bg-emerald-500/5 shadow-xs">
                    <CardContent class="p-4 flex items-center justify-between">
                        <div>
                            <p class="text-xs font-medium text-emerald-700 dark:text-emerald-400">Modo Exclusivo Ingreso</p>
                            <p class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                                {terminals.filter((t) => t.mode === 'entry').length}
                            </p>
                            <p class="text-[11px] text-muted-foreground">Dispositivos para registrar entradas</p>
                        </div>
                        <div class="rounded-full bg-emerald-500/10 p-2.5 text-emerald-600">
                            <ArrowDownRight class="size-5" />
                        </div>
                    </CardContent>
                </Card>

                <Card class="border-blue-500/20 bg-blue-500/5 shadow-xs">
                    <CardContent class="p-4 flex items-center justify-between">
                        <div>
                            <p class="text-xs font-medium text-blue-700 dark:text-blue-400">Modo Exclusivo Salida</p>
                            <p class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                                {terminals.filter((t) => t.mode === 'exit').length}
                            </p>
                            <p class="text-[11px] text-muted-foreground">Dispositivos para registrar salidas</p>
                        </div>
                        <div class="rounded-full bg-blue-500/10 p-2.5 text-blue-600">
                            <ArrowUpRight class="size-5" />
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- Tabla de Terminales QR Registrados en PostgreSQL -->
            <Card class="border-border/60 shadow-xs">
                <CardHeader class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between pb-3">
                    <div>
                        <CardTitle class="text-sm font-semibold">Administración de Terminales QR y Lectores</CardTitle>
                        <p class="text-xs text-muted-foreground">
                            Dispositivos autorizados configurados en PostgreSQL con modo exclusivo de Ingreso o Salida.
                        </p>
                    </div>
                    <Button onclick={openCreateTerminalModal} size="sm" class="bg-primary text-xs shadow-xs self-start sm:self-auto">
                        <Plus class="mr-1.5 size-4" />
                        <span>Registrar Nuevo Terminal</span>
                    </Button>
                </CardHeader>
                <CardContent class="p-0">
                    {#if terminals.length === 0}
                        <div class="flex flex-col items-center justify-center p-8 text-center text-muted-foreground">
                            <QrCode class="size-10 text-muted-foreground/40 mb-2" />
                            <p class="text-sm font-medium text-foreground">No hay terminales QR registrados</p>
                            <p class="text-xs text-muted-foreground max-w-md mt-1">
                                Registre lectores ópticos, torniquetes o cámaras asignándoles exclusivamente el modo de Ingreso o Salida para el control de acceso escolar.
                            </p>
                            <Button onclick={openCreateTerminalModal} variant="outline" size="sm" class="mt-4 text-xs">
                                <Plus class="mr-1.5 size-3.5" />
                                Registrar primer terminal autorizado
                            </Button>
                        </div>
                    {:else}
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-xs min-w-[550px]">
                                <thead class="border-y border-border/60 bg-muted/30 text-muted-foreground font-medium">
                                    <tr>
                                        <th class="py-2.5 px-4">Terminal / Dispositivo</th>
                                        <th class="py-2.5 px-4">Código</th>
                                        <th class="py-2.5 px-4">Modo Asignado</th>
                                        <th class="py-2.5 px-4">Ubicación / Puerta</th>
                                        <th class="py-2.5 px-4">Estado Autorización</th>
                                        <th class="py-2.5 px-4 text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border/50">
                                    {#each terminals as terminal (terminal.id)}
                                        <tr class="hover:bg-muted/20 transition-colors">
                                            <td class="py-3 px-4 font-medium text-foreground">
                                                <div>{terminal.name}</div>
                                                {#if terminal.description}
                                                    <div class="text-[11px] text-muted-foreground truncate max-w-xs">{terminal.description}</div>
                                                {/if}
                                            </td>
                                            <td class="py-3 px-4 font-mono font-medium text-foreground whitespace-nowrap">
                                                {terminal.code}
                                            </td>
                                            <td class="py-3 px-4 whitespace-nowrap">
                                                {#if terminal.mode === 'entry'}
                                                    <Badge variant="outline" class="border-emerald-500/40 text-emerald-600 bg-emerald-50/50 dark:bg-emerald-950/20 text-[11px] font-semibold">
                                                        <ArrowDownRight class="mr-1 size-3" />
                                                        Solo Ingreso
                                                    </Badge>
                                                {:else}
                                                    <Badge variant="outline" class="border-blue-500/40 text-blue-600 bg-blue-50/50 dark:bg-blue-950/20 text-[11px] font-semibold">
                                                        <ArrowUpRight class="mr-1 size-3" />
                                                        Solo Salida
                                                    </Badge>
                                                {/if}
                                            </td>
                                            <td class="py-3 px-4 text-muted-foreground whitespace-nowrap">
                                                {terminal.location || '—'}
                                            </td>
                                            <td class="py-3 px-4 whitespace-nowrap">
                                                {#if terminal.is_active}
                                                    <span class="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-600">
                                                        <CheckCircle2 class="size-3.5" />
                                                        Autorizado
                                                    </span>
                                                {:else}
                                                    <span class="inline-flex items-center gap-1 text-[11px] font-medium text-rose-600">
                                                        <Ban class="size-3.5" />
                                                        Inhabilitado
                                                    </span>
                                                {/if}
                                            </td>
                                            <td class="py-3 px-4 text-right whitespace-nowrap space-x-1">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onclick={() => openEditTerminalModal(terminal)}
                                                    class="h-7 text-xs text-muted-foreground hover:text-foreground"
                                                >
                                                    Editar
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onclick={() => toggleTerminal(terminal)}
                                                    class="h-7 text-xs {terminal.is_active ? 'text-amber-600 hover:text-amber-700' : 'text-emerald-600 hover:text-emerald-700'}"
                                                >
                                                    {terminal.is_active ? 'Inhabilitar' : 'Autorizar'}
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    onclick={() => deleteTerminal(terminal)}
                                                    class="size-7 text-muted-foreground hover:text-destructive hover:bg-destructive/10"
                                                    title="Eliminar terminal"
                                                >
                                                    <Trash2 class="size-3.5" />
                                                </Button>
                                            </td>
                                        </tr>
                                    {/each}
                                </tbody>
                            </table>
                        </div>
                    {/if}
                </CardContent>
            </Card>

            <!-- Parámetros de Escaneo y Audio en Dispositivos -->
            <Card class="border-border/60 shadow-xs">
                <CardHeader>
                    <CardTitle class="text-sm font-semibold">Parámetros Operativos de Lectores</CardTitle>
                </CardHeader>
                <CardContent class="space-y-4 text-xs">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="rounded-lg border border-border/60 p-3 space-y-2">
                            <div class="font-semibold text-foreground">Anti-Rebote (Debounce)</div>
                            <p class="text-[11px] text-muted-foreground">Tiempo mínimo entre lecturas para un mismo estudiante:</p>
                            <Input type="number" value="60" class="h-9 w-32" />
                            <span class="text-[11px] text-muted-foreground">segundos</span>
                        </div>

                        <div class="rounded-lg border border-border/60 p-3 space-y-2">
                            <div class="font-semibold text-foreground">Confirmación Sonora en Navegador</div>
                            <p class="text-[11px] text-muted-foreground">Reproducir tono de audio sintetizado tras escaneo exitoso:</p>
                            <div class="flex items-center gap-2 pt-1">
                                <input type="checkbox" id="audio-beep" checked class="rounded border-input text-primary" />
                                <label for="audio-beep" class="cursor-pointer">Emitir pitido de confirmación</label>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    {:else}
        <Card class="border-border/60 shadow-xs">
            <CardHeader>
                <CardTitle class="text-sm font-semibold">Notificaciones a Padres y Apoderados</CardTitle>
            </CardHeader>
            <CardContent class="space-y-3 text-xs">
                <div class="flex items-center justify-between rounded-lg border border-border/60 p-3">
                    <div>
                        <div class="font-semibold text-foreground">Alerta de Ingreso Puntual</div>
                        <div class="text-[11px] text-muted-foreground">Notificar al apoderado cuando el alumno ingresa al colegio</div>
                    </div>
                    <input type="checkbox" checked class="rounded border-input text-primary" />
                </div>

                <div class="flex items-center justify-between rounded-lg border border-border/60 p-3">
                    <div>
                        <div class="font-semibold text-foreground">Alerta de Tardanza</div>
                        <div class="text-[11px] text-muted-foreground">Notificar inmediatamente si el ingreso se registra con tardanza</div>
                    </div>
                    <input type="checkbox" checked class="rounded border-input text-primary" />
                </div>
            </CardContent>
        </Card>
    {/if}

    <!-- Modal: Registrar Día en Calendario Escolar -->
    {#if isCalendarModalOpen}
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4">
            <div class="w-full max-w-md rounded-lg border border-border bg-card p-6 shadow-xl animate-in fade-in-0 zoom-in-95">
                <div class="flex items-center justify-between pb-3 border-b border-border/60">
                    <div class="flex items-center gap-2">
                        <Calendar class="size-4 text-primary" />
                        <h2 class="text-base font-semibold text-foreground">
                            Registrar Día en Calendario Escolar
                        </h2>
                    </div>
                    <button
                        type="button"
                        onclick={() => (isCalendarModalOpen = false)}
                        class="rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <div class="space-y-4 pt-4 text-xs">
                    <div>
                        <label for="cal-date" class="block font-medium text-foreground mb-1">
                            Fecha <span class="text-rose-500">*</span>
                        </label>
                        <Input
                            id="cal-date"
                            type="date"
                            bind:value={calendarDate}
                            class="h-9 {calendarErrors.date ? 'border-rose-500' : ''}"
                        />
                        {#if calendarErrors.date}
                            <p class="text-[11px] text-rose-500 mt-1">{calendarErrors.date}</p>
                        {/if}
                    </div>

                    <div>
                        <label for="cal-type" class="block font-medium text-foreground mb-1">
                            Tipo de Día <span class="text-rose-500">*</span>
                        </label>
                        <select
                            id="cal-type"
                            bind:value={calendarDayType}
                            class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-xs shadow-xs focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                        >
                            <option value="holiday">Feriado Oficial (Asistencia bloqueada)</option>
                            <option value="suspension">Suspensión de Actividades (Asistencia bloqueada)</option>
                            <option value="school_day">Día Lectivo Extraordinario (Asistencia habilitada)</option>
                        </select>
                        <p class="text-[11px] text-muted-foreground mt-1">
                            {#if calendarDayType === 'school_day'}
                                Se permitirá el escaneo QR y registro de asistencia con los horarios regulares.
                            {:else}
                                El sistema bloqueará el registro de asistencia estudiantil para esta fecha.
                            {/if}
                        </p>
                    </div>

                    <div>
                        <label for="cal-title" class="block font-medium text-foreground mb-1">
                            Título o Motivo <span class="text-rose-500">*</span>
                        </label>
                        <Input
                            id="cal-title"
                            placeholder="Ej. Día del Trabajo, Aniversario Institucional"
                            bind:value={calendarTitle}
                            class="h-9 {calendarErrors.title ? 'border-rose-500' : ''}"
                        />
                        {#if calendarErrors.title}
                            <p class="text-[11px] text-rose-500 mt-1">{calendarErrors.title}</p>
                        {/if}
                    </div>

                    <div>
                        <label for="cal-desc" class="block font-medium text-foreground mb-1">
                            Descripción / Observación (Opcional)
                        </label>
                        <Input
                            id="cal-desc"
                            placeholder="Detalle adicional sobre la resolución o justificación"
                            bind:value={calendarDescription}
                            class="h-9"
                        />
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-end gap-2 border-t border-border/60 pt-4">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onclick={() => (isCalendarModalOpen = false)}
                        disabled={isSavingCalendarDay}
                        class="text-xs"
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        onclick={saveCalendarDay}
                        disabled={isSavingCalendarDay}
                        class="bg-primary text-xs shadow-xs"
                    >
                        {#if isSavingCalendarDay}
                            <RefreshCw class="mr-1.5 size-3.5 animate-spin" />
                            Guardando...
                        {:else}
                            <Save class="mr-1.5 size-3.5" />
                            Guardar en PostgreSQL
                        {/if}
                    </Button>
                </div>
            </div>
        </div>
    {/if}

    <!-- Modal: Registrar / Editar Terminal QR Autorizado -->
    {#if isTerminalModalOpen}
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4">
            <div class="w-full max-w-md rounded-lg border border-border bg-card p-6 shadow-xl animate-in fade-in-0 zoom-in-95">
                <div class="flex items-center justify-between pb-3 border-b border-border/60">
                    <div class="flex items-center gap-2">
                        <QrCode class="size-4 text-primary" />
                        <h2 class="text-base font-semibold text-foreground">
                            {editingTerminal ? 'Editar Terminal QR Autorizado' : 'Registrar Nuevo Terminal QR Autorizado'}
                        </h2>
                    </div>
                    <button
                        type="button"
                        onclick={() => (isTerminalModalOpen = false)}
                        class="rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <div class="space-y-4 pt-4 text-xs">
                    <div>
                        <label for="term-name" class="block font-medium text-foreground mb-1">
                            Nombre del Dispositivo / Lector <span class="text-rose-500">*</span>
                        </label>
                        <Input
                            id="term-name"
                            placeholder="Ej. Torniquete Portería 1, Cámara Entrada Secundaria"
                            bind:value={terminalName}
                            class="h-9 {terminalErrors.name ? 'border-rose-500' : ''}"
                        />
                        {#if terminalErrors.name}
                            <p class="text-[11px] text-rose-500 mt-1">{terminalErrors.name}</p>
                        {/if}
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="term-code" class="block font-medium text-foreground mb-1">
                                Código de Terminal <span class="text-rose-500">*</span>
                            </label>
                            <Input
                                id="term-code"
                                placeholder="Ej. TERM-ING-01"
                                bind:value={terminalCode}
                                class="h-9 font-mono uppercase {terminalErrors.code ? 'border-rose-500' : ''}"
                            />
                            {#if terminalErrors.code}
                                <p class="text-[11px] text-rose-500 mt-1">{terminalErrors.code}</p>
                            {/if}
                        </div>

                        <div>
                            <label for="term-mode" class="block font-medium text-foreground mb-1">
                                Modo Exclusivo <span class="text-rose-500">*</span>
                            </label>
                            <select
                                id="term-mode"
                                bind:value={terminalMode}
                                class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-xs shadow-xs focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring font-medium {terminalMode === 'entry' ? 'text-emerald-600 font-semibold' : 'text-blue-600 font-semibold'}"
                            >
                                <option value="entry">Modo Solo Ingreso (Entrada)</option>
                                <option value="exit">Modo Solo Salida</option>
                            </select>
                            {#if terminalErrors.mode}
                                <p class="text-[11px] text-rose-500 mt-1">{terminalErrors.mode}</p>
                            {/if}
                        </div>
                    </div>

                    <div class="rounded-md border p-2.5 text-[11px] {terminalMode === 'entry' ? 'border-emerald-500/20 bg-emerald-50/5 text-emerald-700 dark:text-emerald-400' : 'border-blue-500/20 bg-blue-50/5 text-blue-700 dark:text-blue-400'}">
                        {#if terminalMode === 'entry'}
                            <strong>Asignación exclusiva:</strong> Este terminal solo registrará ingresos y asistencias. No registrará salidas.
                        {:else}
                            <strong>Asignación exclusiva:</strong> Este terminal solo registrará salidas de estudiantes al finalizar la jornada. No registrará ingresos.
                        {/if}
                    </div>

                    <div>
                        <label for="term-location" class="block font-medium text-foreground mb-1">
                            Ubicación / Puerta
                        </label>
                        <Input
                            id="term-location"
                            placeholder="Ej. Puerta Principal Av. Arequipa, Acceso Inicial"
                            bind:value={terminalLocation}
                            class="h-9"
                        />
                    </div>

                    <div>
                        <label for="term-identifier" class="block font-medium text-foreground mb-1">
                            Identificador Técnico de Dispositivo (Opcional)
                        </label>
                        <Input
                            id="term-identifier"
                            placeholder="Ej. Dirección MAC, serial o IP del lector USB/cámara"
                            bind:value={terminalIdentifier}
                            class="h-9 font-mono text-[11px]"
                        />
                    </div>

                    <div>
                        <label for="term-desc" class="block font-medium text-foreground mb-1">
                            Descripción / Observaciones
                        </label>
                        <Input
                            id="term-desc"
                            placeholder="Detalle o notas de instalación técnica"
                            bind:value={terminalDescription}
                            class="h-9"
                        />
                    </div>

                    <div class="flex items-center gap-2 pt-1">
                        <input
                            type="checkbox"
                            id="term-active"
                            bind:checked={terminalIsActive}
                            class="rounded border-input text-primary"
                        />
                        <label for="term-active" class="cursor-pointer font-medium text-foreground">
                            Dispositivo autorizado para registrar asistencia
                        </label>
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-end gap-2 border-t border-border/60 pt-4">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onclick={() => (isTerminalModalOpen = false)}
                        disabled={isSavingTerminal}
                        class="text-xs"
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        onclick={saveTerminal}
                        disabled={isSavingTerminal}
                        class="bg-primary text-xs shadow-xs"
                    >
                        {#if isSavingTerminal}
                            <RefreshCw class="mr-1.5 size-3.5 animate-spin" />
                            Guardando...
                        {:else}
                            <Save class="mr-1.5 size-3.5" />
                            {editingTerminal ? 'Actualizar en PostgreSQL' : 'Registrar en PostgreSQL'}
                        {/if}
                    </Button>
                </div>
            </div>
        </div>
    {/if}
</div>
