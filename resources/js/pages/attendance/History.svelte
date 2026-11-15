<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            {
                title: 'Asistencia',
                href: '/attendance/overview',
            },
            {
                title: 'Historial de Asistencia',
                href: '/attendance/history',
            },
        ],
    };
</script>

<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import AlertCircle from '@lucide/svelte/icons/alert-circle';
    import ArrowDownRight from '@lucide/svelte/icons/arrow-down-right';
    import ArrowUpRight from '@lucide/svelte/icons/arrow-up-right';
    import Calendar from '@lucide/svelte/icons/calendar';
    import CalendarCheck from '@lucide/svelte/icons/calendar-check';
    import CalendarX from '@lucide/svelte/icons/calendar-x';
    import CheckCircle2 from '@lucide/svelte/icons/check-circle-2';
    import Clock from '@lucide/svelte/icons/clock';
    import Filter from '@lucide/svelte/icons/filter';
    import RotateCcw from '@lucide/svelte/icons/rotate-ccw';
    import Search from '@lucide/svelte/icons/search';
    import UserX from '@lucide/svelte/icons/user-x';
    import AppHead from '@/components/AppHead.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Card, CardContent } from '@/components/ui/card';
    import { Input } from '@/components/ui/input';

    interface StudentInfo {
        id: number;
        code: string;
        document_number: string | null;
        first_name: string;
        last_name: string;
        full_name: string;
        avatar_url: string | null;
        is_active: boolean;
    }

    interface ClassroomInfo {
        grade_id: number | null;
        grade_name: string;
        section_id: number | null;
        section_name: string;
        label: string;
        shift: string;
    }

    interface HistoryRecord {
        id: number | string;
        enrollment_id?: number;
        date: string | null;
        date_formatted: string;
        student: StudentInfo;
        classroom: ClassroomInfo;
        entry_time: string | null;
        entry_time_full: string | null;
        exit_time: string | null;
        exit_time_full: string | null;
        status: string;
        status_label: string;
        punctuality: string;
        exit_type: string | null;
        exit_type_label: string | null;
        is_early_exit: boolean;
        early_exit_reason: string | null;
        early_exit_authorized_by: string | null;
        source: string | null;
        observation: string | null;
        is_unregistered?: boolean;
        can_classify_absent?: boolean;
        exit_time_scheduled?: string;
    }

    interface GradeItem {
        id: number;
        name: string;
        level: string;
    }

    interface SectionItem {
        id: number;
        name: string;
        grade_id: number;
        grade_name: string;
        level: string;
        shift: string;
        label: string;
    }

    interface HistoryFilters {
        date: string;
        grade_id: number | 'all';
        section_id: number | 'all';
        search: string;
        status: string;
    }

    interface HistoryStats {
        total: number;
        punctual: number;
        late: number;
        absent?: number;
        unregistered?: number;
        early_exit: number;
        regular_exit: number;
        can_classify_absent_count?: number;
    }

    interface CalendarDayInfo {
        is_school_day: boolean;
        day_type: string;
        title: string;
        description: string | null;
    }

    let {
        records = [],
        grades = [],
        sections = [],
        filters = {
            date: '',
            grade_id: 'all',
            section_id: 'all',
            search: '',
            status: 'all',
        },
        stats = {
            total: 0,
            punctual: 0,
            late: 0,
            absent: 0,
            unregistered: 0,
            early_exit: 0,
            regular_exit: 0,
            can_classify_absent_count: 0,
        },
        calendar_day = null,
    }: {
        records?: HistoryRecord[];
        grades?: GradeItem[];
        sections?: SectionItem[];
        filters?: HistoryFilters;
        stats?: HistoryStats;
        calendar_day?: CalendarDayInfo | null;
    } = $props();

    let dateVal = $state(filters.date ?? '');
    let gradeVal = $state<number | 'all'>(filters.grade_id ?? 'all');
    let sectionVal = $state<number | 'all'>(filters.section_id ?? 'all');
    let searchVal = $state(filters.search ?? '');
    let statusVal = $state(filters.status ?? 'all');
    let isClassifying = $state(false);

    $effect(() => {
        dateVal = filters.date ?? '';
        gradeVal = filters.grade_id ?? 'all';
        sectionVal = filters.section_id ?? 'all';
        searchVal = filters.search ?? '';
        statusVal = filters.status ?? 'all';
    });

    let filteredSections = $derived.by(() => {
        if (!gradeVal || gradeVal === 'all') {
            return sections;
        }
        return sections.filter((s) => s.grade_id === Number(gradeVal));
    });

    let hasActiveFilters = $derived.by(() => {
        return (
            Boolean(dateVal) ||
            gradeVal !== 'all' ||
            sectionVal !== 'all' ||
            Boolean(searchVal.trim()) ||
            statusVal !== 'all'
        );
    });

    function applyFilters() {
        router.get(
            '/attendance/history',
            {
                date: dateVal || undefined,
                grade_id: gradeVal !== 'all' ? gradeVal : undefined,
                section_id: sectionVal !== 'all' ? sectionVal : undefined,
                search: searchVal.trim() || undefined,
                status: statusVal !== 'all' ? statusVal : undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }

    let searchTimeout: ReturnType<typeof setTimeout>;
    function handleSearchInput() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            applyFilters();
        }, 350);
    }

    function handleGradeChange() {
        if (gradeVal !== 'all') {
            const isValidSection = filteredSections.some((s) => s.id === Number(sectionVal));
            if (!isValidSection) {
                sectionVal = 'all';
            }
        }
        applyFilters();
    }

    function clearFilters() {
        dateVal = '';
        gradeVal = 'all';
        sectionVal = 'all';
        searchVal = '';
        statusVal = 'all';

        router.get(
            '/attendance/history',
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }

    function setTodayDate() {
        const now = new Date();
        const y = now.getFullYear();
        const m = String(now.getMonth() + 1).padStart(2, '0');
        const d = String(now.getDate()).padStart(2, '0');
        dateVal = `${y}-${m}-${d}`;
        applyFilters();
    }

    function classifyStudentAbsent(enrollmentId?: number) {
        if (!enrollmentId || !dateVal) return;
        isClassifying = true;
        router.post(
            '/attendance/history/classify-absent',
            {
                date: dateVal,
                enrollment_id: enrollmentId,
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    isClassifying = false;
                },
            },
        );
    }

    function classifyAllAbsent() {
        if (!dateVal) return;
        const count = stats.can_classify_absent_count ?? stats.unregistered ?? 0;
        if (!confirm(`¿Desea clasificar como ausentes en PostgreSQL a los ${count} estudiante(s) sin ingreso registrado para la fecha ${dateVal}?`)) {
            return;
        }
        isClassifying = true;
        router.post(
            '/attendance/history/classify-absent',
            {
                date: dateVal,
                grade_id: gradeVal !== 'all' ? gradeVal : undefined,
                section_id: sectionVal !== 'all' ? sectionVal : undefined,
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    isClassifying = false;
                },
            },
        );
    }
</script>

<AppHead title="Historial de Asistencia - PostgreSQL" />

<div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
    <!-- Encabezado de Historial -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight text-foreground">
                    Historial de Asistencia Escolar
                </h1>
                <Badge variant="outline" class="border-primary/40 bg-primary/10 text-primary text-xs font-semibold">
                    PostgreSQL
                </Badge>
            </div>
            <p class="text-sm text-muted-foreground">
                Consulta cronológica de asistencias por estudiante con fecha, ingreso, salida, puntualidad, detección de alumnos sin registro y clasificación de ausencias.
            </p>
        </div>

        <!-- Indicador de total de registros y limpiar filtros -->
        <div class="flex flex-wrap items-center gap-2">
            {#if hasActiveFilters}
                <Button
                    variant="outline"
                    size="sm"
                    onclick={clearFilters}
                    class="text-xs h-9"
                >
                    <RotateCcw class="mr-1.5 size-3.5" /> Limpiar Filtros
                </Button>
            {/if}
            <div class="rounded-lg bg-muted/60 border border-border px-3 py-1.5 text-xs font-medium text-foreground">
                Total Registros: <strong class="text-primary">{stats.total}</strong>
            </div>
        </div>
    </div>

    <!-- Tarjetas de Resumen Rápido -->
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <Card class="border-border/60 shadow-xs">
            <CardContent class="p-3 flex items-center justify-between">
                <div>
                    <div class="text-[10px] font-semibold text-muted-foreground uppercase">Puntuales</div>
                    <div class="text-lg font-bold text-emerald-600">{stats.punctual}</div>
                </div>
                <div class="size-8 rounded-lg bg-emerald-500/10 text-emerald-600 flex items-center justify-center">
                    <CheckCircle2 class="size-4" />
                </div>
            </CardContent>
        </Card>

        <Card class="border-border/60 shadow-xs">
            <CardContent class="p-3 flex items-center justify-between">
                <div>
                    <div class="text-[10px] font-semibold text-muted-foreground uppercase">Tardanzas</div>
                    <div class="text-lg font-bold text-amber-600">{stats.late}</div>
                </div>
                <div class="size-8 rounded-lg bg-amber-500/10 text-amber-600 flex items-center justify-center">
                    <Clock class="size-4" />
                </div>
            </CardContent>
        </Card>

        <Card class="border-border/60 shadow-xs">
            <CardContent class="p-3 flex items-center justify-between">
                <div>
                    <div class="text-[10px] font-semibold text-muted-foreground uppercase">Sin Registro</div>
                    <div class="text-lg font-bold text-muted-foreground">{stats.unregistered ?? 0}</div>
                </div>
                <div class="size-8 rounded-lg bg-muted/60 text-muted-foreground flex items-center justify-center">
                    <Clock class="size-4" />
                </div>
            </CardContent>
        </Card>

        <Card class="border-border/60 shadow-xs">
            <CardContent class="p-3 flex items-center justify-between">
                <div>
                    <div class="text-[10px] font-semibold text-muted-foreground uppercase">Faltas</div>
                    <div class="text-lg font-bold text-rose-600">{stats.absent ?? 0}</div>
                </div>
                <div class="size-8 rounded-lg bg-rose-500/10 text-rose-600 flex items-center justify-center">
                    <UserX class="size-4" />
                </div>
            </CardContent>
        </Card>

        <Card class="border-border/60 shadow-xs">
            <CardContent class="p-3 flex items-center justify-between">
                <div>
                    <div class="text-[10px] font-semibold text-muted-foreground uppercase">Salidas Regulares</div>
                    <div class="text-lg font-bold text-blue-600">{stats.regular_exit}</div>
                </div>
                <div class="size-8 rounded-lg bg-blue-500/10 text-blue-600 flex items-center justify-center">
                    <ArrowUpRight class="size-4" />
                </div>
            </CardContent>
        </Card>

        <Card class="border-border/60 shadow-xs">
            <CardContent class="p-3 flex items-center justify-between">
                <div>
                    <div class="text-[10px] font-semibold text-muted-foreground uppercase">Salidas Anticip.</div>
                    <div class="text-lg font-bold text-amber-600">{stats.early_exit}</div>
                </div>
                <div class="size-8 rounded-lg bg-amber-500/10 text-amber-600 flex items-center justify-center">
                    <Clock class="size-4" />
                </div>
            </CardContent>
        </Card>
    </div>

    <!-- Barra de Filtros: Fecha, Grado, Sección, Estado y Búsqueda -->
    <Card class="border-border/60 shadow-xs">
        <CardContent class="p-4 space-y-3">
            <div class="flex items-center gap-1.5 text-xs font-semibold text-foreground">
                <Filter class="size-3.5 text-primary" />
                <span>Filtros de Búsqueda y Auditoría</span>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
                <!-- 1. Filtro por Fecha con botón Hoy -->
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label for="filter-date" class="block text-[11px] font-semibold text-muted-foreground">
                            Fecha Escolar:
                        </label>
                        <button
                            type="button"
                            onclick={setTodayDate}
                            class="text-[10px] font-semibold text-primary hover:underline"
                        >
                            Seleccionar Hoy
                        </button>
                    </div>
                    <div class="flex items-center gap-1.5 rounded-md border border-input bg-background px-2.5 py-1 text-xs shadow-xs h-9">
                        <Calendar class="size-3.5 text-muted-foreground shrink-0" />
                        <input
                            id="filter-date"
                            type="date"
                            bind:value={dateVal}
                            onchange={applyFilters}
                            class="bg-transparent focus:outline-none w-full text-xs font-mono"
                        />
                        {#if dateVal}
                            <button
                                type="button"
                                onclick={() => {
                                    dateVal = '';
                                    applyFilters();
                                }}
                                class="text-muted-foreground hover:text-foreground text-xs px-1"
                                title="Limpiar fecha"
                            >
                                ×
                            </button>
                        {/if}
                    </div>
                </div>

                <!-- 2. Filtro por Grado -->
                <div>
                    <label for="filter-grade" class="block text-[11px] font-semibold text-muted-foreground mb-1">
                        Grado Escolar:
                    </label>
                    <select
                        id="filter-grade"
                        bind:value={gradeVal}
                        onchange={handleGradeChange}
                        class="w-full rounded-md border border-input bg-background px-2.5 py-1 text-xs shadow-xs focus:outline-none focus:ring-1 focus:ring-primary h-9 font-medium"
                    >
                        <option value="all">Todos los Grados</option>
                        {#each grades as g (g.id)}
                            <option value={g.id}>
                                {g.name} ({g.level})
                            </option>
                        {/each}
                    </select>
                </div>

                <!-- 3. Filtro por Sección -->
                <div>
                    <label for="filter-section" class="block text-[11px] font-semibold text-muted-foreground mb-1">
                        Sección / Aula:
                    </label>
                    <select
                        id="filter-section"
                        bind:value={sectionVal}
                        onchange={applyFilters}
                        class="w-full rounded-md border border-input bg-background px-2.5 py-1 text-xs shadow-xs focus:outline-none focus:ring-1 focus:ring-primary h-9 font-medium"
                    >
                        <option value="all">Todas las Secciones</option>
                        {#each filteredSections as s (s.id)}
                            <option value={s.id}>
                                {s.label}
                            </option>
                        {/each}
                    </select>
                </div>

                <!-- 4. Filtro por Estado de Asistencia -->
                <div>
                    <label for="filter-status" class="block text-[11px] font-semibold text-muted-foreground mb-1">
                        Estado Asistencia:
                    </label>
                    <select
                        id="filter-status"
                        bind:value={statusVal}
                        onchange={applyFilters}
                        class="w-full rounded-md border border-input bg-background px-2.5 py-1 text-xs shadow-xs focus:outline-none focus:ring-1 focus:ring-primary h-9 font-medium"
                    >
                        <option value="all">Todos los Estados</option>
                        <option value="present">Presente</option>
                        <option value="late">Tardanza</option>
                        <option value="unregistered">Sin registro</option>
                        <option value="absent">Falta</option>
                        <option value="justified">Justificado</option>
                    </select>
                </div>

                <!-- 5. Buscador de Alumno (Nombre, DNI o Código) -->
                <div>
                    <label for="filter-search" class="block text-[11px] font-semibold text-muted-foreground mb-1">
                        Buscar Estudiante:
                    </label>
                    <div class="relative">
                        <Search class="absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                        <Input
                            id="filter-search"
                            type="text"
                            placeholder="Nombre, DNI o código..."
                            bind:value={searchVal}
                            oninput={handleSearchInput}
                            class="pl-8 text-xs h-9"
                        />
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>

    <!-- Avisos del Calendario Escolar y Detección de Estudiantes Sin Registro -->
    {#if calendar_day && !calendar_day.is_school_day}
        <div class="flex items-center gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-xs text-amber-900 dark:text-amber-200">
            <AlertCircle class="size-5 shrink-0 text-amber-600 dark:text-amber-400" />
            <div>
                <strong class="font-semibold">Calendario Escolar:</strong> La fecha seleccionada ({dateVal}) está registrada como
                <strong>{calendar_day.title}</strong> ({calendar_day.day_type === 'holiday' ? 'Feriado' : (calendar_day.day_type === 'suspension' ? 'Suspensión de actividades' : 'Día no lectivo')}).
                Respetando el calendario escolar oficial, no se computan inasistencias ni ausencias para este día.
            </div>
        </div>
    {:else if dateVal && (stats.unregistered ?? 0) > 0}
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between rounded-xl border border-amber-500/30 bg-amber-500/5 p-4 text-xs">
            <div class="flex items-start gap-3">
                <Clock class="size-5 shrink-0 text-amber-600 mt-0.5" />
                <div>
                    <p class="font-semibold text-foreground text-sm">
                        Detección de Asistencia: {stats.unregistered} estudiante(s) sin ingreso registrado para el {dateVal}.
                    </p>
                    {#if (stats.can_classify_absent_count ?? 0) > 0}
                        <p class="text-xs text-muted-foreground mt-0.5">
                            La jornada escolar ha finalizado para sus turnos. Ahora puede clasificarlos como ausentes en PostgreSQL.
                        </p>
                    {:else}
                        <p class="text-xs text-muted-foreground mt-0.5">
                            Jornada escolar en curso. Los estudiantes muestran el estado oficial <strong class="text-foreground">"Sin registro"</strong> hasta el cierre de turno.
                        </p>
                    {/if}
                </div>
            </div>

            {#if (stats.can_classify_absent_count ?? 0) > 0}
                <div class="shrink-0">
                    <Button
                        size="sm"
                        onclick={classifyAllAbsent}
                        disabled={isClassifying}
                        class="bg-rose-600 hover:bg-rose-700 text-white text-xs shadow-xs"
                    >
                        <UserX class="mr-1.5 size-3.5" />
                        <span>Clasificar {stats.can_classify_absent_count} como ausentes</span>
                    </Button>
                </div>
            {/if}
        </div>
    {/if}

    <!-- Tabla Histórica de Asistencia desde PostgreSQL -->
    <div class="overflow-hidden rounded-xl border border-border/70 bg-card shadow-xs">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm min-w-[880px]">
                <thead class="border-b border-border/70 bg-muted/40 text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">
                    <tr>
                        <th class="px-4 py-3">Fecha</th>
                        <th class="px-4 py-3">Estudiante</th>
                        <th class="px-4 py-3">Aula / Turno</th>
                        <th class="px-4 py-3 text-center">Hora de Ingreso</th>
                        <th class="px-4 py-3 text-center">Hora de Salida</th>
                        <th class="px-4 py-3 text-center">Puntualidad</th>
                        <th class="px-4 py-3 text-center">Estado Asistencia</th>
                        <th class="px-4 py-3 text-center">Detalle de Salida</th>
                        <th class="px-4 py-3 text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/60 text-xs">
                    {#if records.length === 0}
                        <tr>
                            <td colspan="9" class="px-4 py-12 text-center text-muted-foreground">
                                <div class="max-w-sm mx-auto space-y-2">
                                    <CalendarX class="mx-auto size-12 text-muted-foreground/30 animate-pulse" />
                                    <p class="font-semibold text-foreground text-sm">
                                        No se encontraron registros de asistencia
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        No existen movimientos en PostgreSQL que coincidan con la fecha, grado, sección o filtros seleccionados.
                                    </p>
                                    {#if hasActiveFilters}
                                        <div class="pt-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onclick={clearFilters}
                                                class="text-xs h-8"
                                            >
                                                <RotateCcw class="mr-1.5 size-3" /> Restablecer Filtros
                                            </Button>
                                        </div>
                                    {/if}
                                </div>
                            </td>
                        </tr>
                    {:else}
                        {#each records as record (record.id)}
                            <tr class="hover:bg-muted/30 transition-colors {record.is_unregistered ? 'bg-amber-500/5' : ''}">
                                <!-- Fecha -->
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="font-mono font-medium text-foreground">
                                        {record.date_formatted}
                                    </div>
                                    <div class="text-[10px] text-muted-foreground font-mono">
                                        {record.date}
                                    </div>
                                </td>

                                <!-- Estudiante -->
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2.5">
                                        <div class="size-8 rounded-full bg-primary/10 border border-primary/20 flex items-center justify-center text-xs font-bold text-primary shrink-0 overflow-hidden">
                                            {#if record.student.avatar_url}
                                                <img src={record.student.avatar_url} alt={record.student.full_name} class="size-full object-cover" />
                                            {:else}
                                                {record.student.first_name[0]}{record.student.last_name[0]}
                                            {/if}
                                        </div>
                                        <div>
                                            <div class="font-medium text-foreground">
                                                {record.student.last_name}, {record.student.first_name}
                                            </div>
                                            <div class="text-[10px] text-muted-foreground font-mono flex items-center gap-1.5">
                                                <span>DNI: {record.student.document_number || '—'}</span>
                                                <span>•</span>
                                                <span>Cód: {record.student.code}</span>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Aula y Turno -->
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="font-medium text-foreground">
                                        {record.classroom.label}
                                    </div>
                                    <div class="text-[10px] text-muted-foreground">
                                        Turno {record.classroom.shift}
                                    </div>
                                </td>

                                <!-- Hora de Ingreso -->
                                <td class="px-4 py-3 text-center whitespace-nowrap font-mono">
                                    {#if record.entry_time}
                                        <span class="inline-flex items-center gap-1 font-semibold text-foreground">
                                            <ArrowDownRight class="size-3.5 text-emerald-600" />
                                            {record.entry_time}
                                        </span>
                                    {:else}
                                        <span class="text-muted-foreground font-normal">—</span>
                                    {/if}
                                </td>

                                <!-- Hora de Salida -->
                                <td class="px-4 py-3 text-center whitespace-nowrap font-mono">
                                    {#if record.exit_time}
                                        <span class="inline-flex items-center gap-1 font-semibold text-foreground">
                                            <ArrowUpRight class="size-3.5 {record.is_early_exit ? 'text-amber-600' : 'text-blue-600'}" />
                                            {record.exit_time}
                                        </span>
                                    {:else}
                                        <span class="text-muted-foreground font-normal">—</span>
                                    {/if}
                                </td>

                                <!-- Puntualidad -->
                                <td class="px-4 py-3 text-center whitespace-nowrap">
                                    {#if record.punctuality === 'Puntual'}
                                        <Badge variant="outline" class="border-emerald-500/40 text-emerald-600 bg-emerald-500/10 font-semibold text-[11px]">
                                            <CheckCircle2 class="mr-1 size-3" /> Puntual
                                        </Badge>
                                    {:else if record.punctuality === 'Tardanza'}
                                        <Badge variant="outline" class="border-amber-500/40 text-amber-600 bg-amber-500/10 font-semibold text-[11px]">
                                            <Clock class="mr-1 size-3" /> Tardanza
                                        </Badge>
                                    {:else if record.punctuality === 'Sin registro'}
                                        <Badge variant="outline" class="border-muted-foreground/30 text-muted-foreground bg-muted/30 font-medium text-[11px]">
                                            Sin registro
                                        </Badge>
                                    {:else}
                                        <span class="text-muted-foreground text-[11px]">—</span>
                                    {/if}
                                </td>

                                <!-- Estado de Asistencia -->
                                <td class="px-4 py-3 text-center whitespace-nowrap">
                                    {#if record.status === 'present'}
                                        <Badge class="bg-emerald-600/90 hover:bg-emerald-600 text-white font-medium text-[11px]">
                                            Presente
                                        </Badge>
                                    {:else if record.status === 'late'}
                                        <Badge class="bg-amber-600/90 hover:bg-amber-600 text-white font-medium text-[11px]">
                                            Tardanza
                                        </Badge>
                                    {:else if record.status === 'unregistered'}
                                        <Badge variant="outline" class="border-amber-500/40 text-amber-700 bg-amber-500/10 dark:text-amber-400 font-semibold text-[11px]">
                                            <Clock class="mr-1 size-3" /> Sin registro
                                        </Badge>
                                    {:else if record.status === 'absent'}
                                        <Badge class="bg-rose-600/90 hover:bg-rose-600 text-white font-medium text-[11px]">
                                            Falta
                                        </Badge>
                                    {:else if record.status === 'justified'}
                                        <Badge class="bg-blue-600/90 hover:bg-blue-600 text-white font-medium text-[11px]">
                                            Justificado
                                        </Badge>
                                    {:else}
                                        <Badge variant="secondary" class="text-[11px]">
                                            {record.status_label}
                                        </Badge>
                                    {/if}
                                </td>

                                <!-- Detalle de Salida -->
                                <td class="px-4 py-3 text-center whitespace-nowrap">
                                    {#if record.is_early_exit}
                                        <div class="inline-flex flex-col items-center">
                                            <Badge variant="outline" class="border-amber-500/40 text-amber-600 bg-amber-500/10 font-semibold text-[10px]" title={record.early_exit_reason || ''}>
                                                <Clock class="mr-1 size-3" /> Salida Anticipada
                                            </Badge>
                                            {#if record.early_exit_reason}
                                                <span class="inline-block text-[10px] text-muted-foreground italic truncate max-w-[150px] mt-0.5" title={record.early_exit_reason}>
                                                    "{record.early_exit_reason}"
                                                </span>
                                            {/if}
                                        </div>
                                    {:else if record.exit_type === 'regular'}
                                        <Badge variant="outline" class="border-blue-500/40 text-blue-600 bg-blue-500/10 font-semibold text-[10px]">
                                            <ArrowUpRight class="mr-1 size-3" /> Salida Regular
                                        </Badge>
                                    {:else if record.exit_time}
                                        <Badge variant="outline" class="text-[10px]">
                                            Salida Registrada
                                        </Badge>
                                    {:else}
                                        <span class="text-muted-foreground text-[11px]">—</span>
                                    {/if}
                                </td>

                                <!-- Acciones -->
                                <td class="px-4 py-3 text-center whitespace-nowrap">
                                    {#if record.is_unregistered}
                                        {#if record.can_classify_absent}
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onclick={() => classifyStudentAbsent(record.enrollment_id)}
                                                disabled={isClassifying}
                                                class="h-7 px-2 text-xs border-rose-300 text-rose-700 hover:bg-rose-50 dark:border-rose-800 dark:text-rose-400"
                                                title="Clasificar al estudiante como ausente en PostgreSQL"
                                            >
                                                <UserX class="mr-1 size-3" /> Clasificar Falta
                                            </Button>
                                        {:else}
                                            <span class="text-[10px] text-muted-foreground font-mono" title="La jornada se cierra a las {record.exit_time_scheduled}">
                                                En curso ({record.exit_time_scheduled})
                                            </span>
                                        {/if}
                                    {:else}
                                        <span class="text-muted-foreground text-[11px]">—</span>
                                    {/if}
                                </td>
                            </tr>
                        {/each}
                    {/if}
                </tbody>
            </table>
        </div>
    </div>
</div>
