<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            {
                title: 'Asistencia',
                href: '/attendance/overview',
            },
            {
                title: 'Panel General',
                href: '/attendance/overview',
            },
        ],
    };
</script>

<script lang="ts">
    import { Link, router } from '@inertiajs/svelte';
    import AlertCircle from '@lucide/svelte/icons/alert-circle';
    import ArrowDownRight from '@lucide/svelte/icons/arrow-down-right';
    import ArrowRight from '@lucide/svelte/icons/arrow-right';
    import ArrowUpRight from '@lucide/svelte/icons/arrow-up-right';
    import BarChart3 from '@lucide/svelte/icons/bar-chart-3';
    import Calendar from '@lucide/svelte/icons/calendar';
    import CheckCircle2 from '@lucide/svelte/icons/check-circle-2';
    import Clock from '@lucide/svelte/icons/clock';
    import DoorOpen from '@lucide/svelte/icons/door-open';
    import Filter from '@lucide/svelte/icons/filter';
    import LogOut from '@lucide/svelte/icons/log-out';
    import QrCode from '@lucide/svelte/icons/qr-code';
    import Radio from '@lucide/svelte/icons/radio';
    import RotateCcw from '@lucide/svelte/icons/rotate-ccw';
    import TrendingUp from '@lucide/svelte/icons/trending-up';
    import Users from '@lucide/svelte/icons/users';
    import UserX from '@lucide/svelte/icons/user-x';
    import AppHead from '@/components/AppHead.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

    interface OverviewStats {
        inside_school: number;
        total_entries: number;
        total_exits: number;
        punctual: number;
        late: number;
        absent: number;
        pending_records: number;
        regular_exits: number;
        early_exits: number;
        total_enrolled: number;
        date_formatted: string;
        date: string;
        is_school_day: boolean;
        calendar_title: string;
        calendar_type: string;
    }

    interface ChartSeriesItem {
        label: string;
        sublabel: string;
        start_date: string;
        end_date: string;
        punctual: number;
        late: number;
        absent: number;
        total_attendance: number;
        total_evaluated: number;
        attendance_rate: number;
    }

    interface ChartSummary {
        total_punctual: number;
        total_late: number;
        total_absent: number;
        total_attendance: number;
        overall_rate: number;
        max_val: number;
    }

    interface ChartData {
        period: 'daily' | 'weekly' | 'monthly' | string;
        series: ChartSeriesItem[];
        summary: ChartSummary;
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
        shift: string;
        label: string;
    }

    interface TerminalItem {
        id: number;
        name: string;
        code: string;
        location: string;
        mode: string;
        mode_label: string;
        is_active: boolean;
        last_heartbeat: string | null;
    }

    interface RecentScanItem {
        id: number;
        student_name: string;
        first_name: string;
        last_name: string;
        student_code: string;
        classroom: string;
        shift: string;
        entry_time: string | null;
        exit_time: string | null;
        action: 'entry' | 'exit';
        status: string;
        is_early_exit: boolean;
        early_exit_reason: string | null;
        time_formatted: string;
        observation: string | null;
    }

    interface OverviewFilters {
        period: 'daily' | 'weekly' | 'monthly' | string;
        grade_id: number | 'all';
        section_id: number | 'all';
    }

    let {
        stats = {
            inside_school: 0,
            total_entries: 0,
            total_exits: 0,
            punctual: 0,
            late: 0,
            absent: 0,
            pending_records: 0,
            regular_exits: 0,
            early_exits: 0,
            total_enrolled: 0,
            date_formatted: '',
            date: '',
            is_school_day: true,
            calendar_title: 'Día lectivo',
            calendar_type: 'school_day',
        },
        chart = {
            period: 'daily',
            series: [],
            summary: {
                total_punctual: 0,
                total_late: 0,
                total_absent: 0,
                total_attendance: 0,
                overall_rate: 0,
                max_val: 1,
            },
        },
        grades = [],
        sections = [],
        terminals = [],
        recent_scans = [],
        filters = {
            period: 'daily',
            grade_id: 'all',
            section_id: 'all',
        },
    }: {
        stats?: OverviewStats;
        chart?: ChartData;
        grades?: GradeItem[];
        sections?: SectionItem[];
        terminals?: TerminalItem[];
        recent_scans?: RecentScanItem[];
        filters?: OverviewFilters;
    } = $props();

    let periodVal = $state(filters.period ?? 'daily');
    let gradeVal = $state<number | 'all'>(filters.grade_id ?? 'all');
    let sectionVal = $state<number | 'all'>(filters.section_id ?? 'all');

    $effect(() => {
        periodVal = filters.period ?? 'daily';
        gradeVal = filters.grade_id ?? 'all';
        sectionVal = filters.section_id ?? 'all';
    });

    let filteredSections = $derived.by(() => {
        if (!gradeVal || gradeVal === 'all') {
            return sections;
        }
        return sections.filter((s) => s.grade_id === Number(gradeVal));
    });

    let hasActiveFilters = $derived.by(() => {
        return (
            periodVal !== 'daily' ||
            gradeVal !== 'all' ||
            sectionVal !== 'all'
        );
    });

    function applyFilters(overridePeriod?: string) {
        const nextPeriod = overridePeriod ?? periodVal;
        router.get(
            '/attendance/overview',
            {
                period: nextPeriod !== 'daily' ? nextPeriod : undefined,
                grade_id: gradeVal !== 'all' ? gradeVal : undefined,
                section_id: sectionVal !== 'all' ? sectionVal : undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }

    function setPeriod(p: 'daily' | 'weekly' | 'monthly') {
        periodVal = p;
        applyFilters(p);
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
        periodVal = 'daily';
        gradeVal = 'all';
        sectionVal = 'all';

        router.get(
            '/attendance/overview',
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }

    function getInitials(first: string, last: string): string {
        const f = first?.[0] || 'E';
        const l = last?.[0] || '';
        return (f + l).toUpperCase();
    }

    // Porcentaje de altura relativa de las barras respecto al valor máximo
    function getBarHeightPercent(val: number, maxVal: number): number {
        if (!maxVal || maxVal <= 0 || !val) return 0;
        const pct = (val / maxVal) * 100;
        return Math.max(4, Math.min(100, pct));
    }
</script>

<AppHead title="Panel General de Asistencia QR - PostgreSQL" />

<div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
    <!-- Encabezado Principal -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight text-foreground">
                    Panel General de Control de Asistencia QR
                </h1>
                <Badge variant="outline" class="border-emerald-500/40 bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300">
                    <span class="mr-1.5 size-2 rounded-full bg-emerald-500 animate-pulse"></span> PostgreSQL en Vivo
                </Badge>
                {#if stats.calendar_title}
                    <Badge variant="outline" class="text-xs">
                        {stats.calendar_title}
                    </Badge>
                {/if}
            </div>
            <p class="text-sm text-muted-foreground mt-0.5">
                {stats.date_formatted || 'Monitoreo de aforo escolar, ingresos, salidas y puntualidad en tiempo real.'}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <Button asChild class="bg-primary shadow-xs text-xs">
                {#snippet children(props)}
                    <Link {...props} href="/attendance/scanner">
                        <QrCode class="mr-2 size-4" />
                        <span>Abrir Escáner de Puerta</span>
                    </Link>
                {/snippet}
            </Button>
        </div>
    </div>

    <!-- Barra de Filtros: Periodo (Diario, Semanal, Mensual), Grado y Sección -->
    <Card class="border-border/60 shadow-xs">
        <CardContent class="p-3.5 space-y-3">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <!-- Selector de Periodo (Pestañas / Segmented Control) -->
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="text-xs font-semibold text-foreground mr-1">Periodo:</span>
                    <div class="inline-flex max-w-full overflow-x-auto rounded-lg border border-border p-1 bg-muted/40">
                        <button
                            type="button"
                            onclick={() => setPeriod('daily')}
                            class="px-3 py-1 text-xs font-semibold rounded-md transition-all {periodVal === 'daily' ? 'bg-background text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'}"
                        >
                            Diaria (7 días)
                        </button>
                        <button
                            type="button"
                            onclick={() => setPeriod('weekly')}
                            class="px-3 py-1 text-xs font-semibold rounded-md transition-all {periodVal === 'weekly' ? 'bg-background text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'}"
                        >
                            Semanal (6 semanas)
                        </button>
                        <button
                            type="button"
                            onclick={() => setPeriod('monthly')}
                            class="px-3 py-1 text-xs font-semibold rounded-md transition-all {periodVal === 'monthly' ? 'bg-background text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'}"
                        >
                            Mensual (6 meses)
                        </button>
                    </div>
                </div>

                <!-- Filtros por Grado y Sección -->
                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex items-center gap-1.5">
                        <label for="filter-grade" class="text-xs font-semibold text-muted-foreground">
                            Grado:
                        </label>
                        <select
                            id="filter-grade"
                            bind:value={gradeVal}
                            onchange={handleGradeChange}
                            class="rounded-md border border-input bg-background px-2.5 py-1 text-xs shadow-xs focus:outline-none focus:ring-1 focus:ring-primary h-8 font-medium"
                        >
                            <option value="all">Todos los Grados</option>
                            {#each grades as g (g.id)}
                                <option value={g.id}>
                                    {g.name} ({g.level})
                                </option>
                            {/each}
                        </select>
                    </div>

                    <div class="flex items-center gap-1.5">
                        <label for="filter-section" class="text-xs font-semibold text-muted-foreground">
                            Sección:
                        </label>
                        <select
                            id="filter-section"
                            bind:value={sectionVal}
                            onchange={() => applyFilters()}
                            class="rounded-md border border-input bg-background px-2.5 py-1 text-xs shadow-xs focus:outline-none focus:ring-1 focus:ring-primary h-8 font-medium"
                        >
                            <option value="all">Todas las Secciones</option>
                            {#each filteredSections as s (s.id)}
                                <option value={s.id}>
                                    {s.label}
                                </option>
                            {/each}
                        </select>
                    </div>

                    {#if hasActiveFilters}
                        <Button
                            variant="ghost"
                            size="sm"
                            onclick={clearFilters}
                            class="text-xs h-8 px-2 text-muted-foreground hover:text-foreground"
                            title="Restablecer filtros"
                        >
                            <RotateCcw class="mr-1 size-3" /> Limpiar
                        </Button>
                    {/if}
                </div>
            </div>
        </CardContent>
    </Card>

    <!-- Tarjetas de Indicadores Principales (KPIs con cifras reales de PostgreSQL) -->
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-7">
        <!-- 1. Estudiantes Dentro del Colegio -->
        <Card class="border-border/60 shadow-xs bg-card">
            <CardHeader class="flex flex-row items-center justify-between pb-2">
                <CardTitle class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                    Dentro del Colegio
                </CardTitle>
                <DoorOpen class="size-4 text-primary" />
            </CardHeader>
            <CardContent>
                <div class="text-2xl font-extrabold text-foreground">{stats.inside_school}</div>
                <p class="mt-0.5 text-[10px] text-muted-foreground">
                    Aforo actual en campus
                </p>
            </CardContent>
        </Card>

        <!-- 2. Ingresos Totales -->
        <Card class="border-border/60 shadow-xs">
            <CardHeader class="flex flex-row items-center justify-between pb-2">
                <CardTitle class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                    Ingresos
                </CardTitle>
                <ArrowDownRight class="size-4 text-emerald-600" />
            </CardHeader>
            <CardContent>
                <div class="text-2xl font-extrabold text-foreground">{stats.total_entries}</div>
                <p class="mt-0.5 text-[10px] text-muted-foreground">
                    Total entradas hoy
                </p>
            </CardContent>
        </Card>

        <!-- 3. Puntualidad -->
        <Card class="border-emerald-500/20 bg-emerald-500/5 shadow-xs">
            <CardHeader class="flex flex-row items-center justify-between pb-2">
                <CardTitle class="text-[10px] font-semibold uppercase tracking-wider text-emerald-700 dark:text-emerald-400">
                    Puntualidad
                </CardTitle>
                <CheckCircle2 class="size-4 text-emerald-600 dark:text-emerald-400" />
            </CardHeader>
            <CardContent>
                <div class="text-2xl font-extrabold text-emerald-600 dark:text-emerald-400">{stats.punctual}</div>
                <p class="mt-0.5 text-[10px] text-muted-foreground">
                    Ingresos a tiempo
                </p>
            </CardContent>
        </Card>

        <!-- 4. Tardanzas -->
        <Card class="border-amber-500/20 bg-amber-500/5 shadow-xs">
            <CardHeader class="flex flex-row items-center justify-between pb-2">
                <CardTitle class="text-[10px] font-semibold uppercase tracking-wider text-amber-700 dark:text-amber-400">
                    Tardanzas
                </CardTitle>
                <Clock class="size-4 text-amber-600 dark:text-amber-400" />
            </CardHeader>
            <CardContent>
                <div class="text-2xl font-extrabold text-amber-600 dark:text-amber-400">{stats.late}</div>
                <p class="mt-0.5 text-[10px] text-muted-foreground">
                    Posteriores al horario
                </p>
            </CardContent>
        </Card>

        <!-- 5. Salidas -->
        <Card class="border-blue-500/20 bg-blue-500/5 shadow-xs">
            <CardHeader class="flex flex-row items-center justify-between pb-2">
                <CardTitle class="text-[10px] font-semibold uppercase tracking-wider text-blue-700 dark:text-blue-400">
                    Salidas
                </CardTitle>
                <ArrowUpRight class="size-4 text-blue-600 dark:text-blue-400" />
            </CardHeader>
            <CardContent>
                <div class="text-2xl font-extrabold text-blue-600 dark:text-blue-400">{stats.total_exits}</div>
                <p class="mt-0.5 text-[10px] text-muted-foreground">
                    {stats.early_exits > 0 ? `${stats.early_exits} anticipada(s)` : 'Regulares'}
                </p>
            </CardContent>
        </Card>

        <!-- 6. Ausencias -->
        <Card class="border-rose-500/20 bg-rose-500/5 shadow-xs">
            <CardHeader class="flex flex-row items-center justify-between pb-2">
                <CardTitle class="text-[10px] font-semibold uppercase tracking-wider text-rose-700 dark:text-rose-400">
                    Ausencias
                </CardTitle>
                <UserX class="size-4 text-rose-600 dark:text-rose-400" />
            </CardHeader>
            <CardContent>
                <div class="text-2xl font-extrabold text-rose-600 dark:text-rose-400">{stats.absent}</div>
                <p class="mt-0.5 text-[10px] text-muted-foreground">
                    Inasistencias fijadas
                </p>
            </CardContent>
        </Card>

        <!-- 7. Registros Pendientes -->
        <Card class="border-muted bg-muted/20 shadow-xs">
            <CardHeader class="flex flex-row items-center justify-between pb-2">
                <CardTitle class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                    Pendientes
                </CardTitle>
                <Clock class="size-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
                <div class="text-2xl font-extrabold text-muted-foreground">{stats.pending_records}</div>
                <p class="mt-0.5 text-[10px] text-muted-foreground">
                    Sin marca de ingreso hoy
                </p>
            </CardContent>
        </Card>
    </div>

    <!-- Sección de Gráficos de Asistencia (Diaria, Semanal, Mensual) con PostgreSQL -->
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Gráfico Principal de Barras Agrupadas por Periodo -->
        <Card class="border-border/60 shadow-xs lg:col-span-2">
            <CardHeader class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between pb-2">
                <div>
                    <CardTitle class="text-base font-semibold flex items-center gap-2">
                        <BarChart3 class="size-4 text-primary" />
                        <span>Gráfico de Asistencia {periodVal === 'daily' ? 'Diaria' : (periodVal === 'weekly' ? 'Semanal' : 'Mensual')}</span>
                    </CardTitle>
                    <p class="text-xs text-muted-foreground">
                        Comparativa de puntualidad, tardanzas e inasistencias en tiempo real desde PostgreSQL
                    </p>
                </div>

                <!-- Leyenda de Colores -->
                <div class="flex items-center gap-3 text-xs">
                    <span class="inline-flex items-center gap-1.5 font-medium text-emerald-700 dark:text-emerald-400">
                        <span class="size-2.5 rounded-full bg-emerald-500"></span> Puntual
                    </span>
                    <span class="inline-flex items-center gap-1.5 font-medium text-amber-700 dark:text-amber-400">
                        <span class="size-2.5 rounded-full bg-amber-500"></span> Tardanza
                    </span>
                    <span class="inline-flex items-center gap-1.5 font-medium text-rose-700 dark:text-rose-400">
                        <span class="size-2.5 rounded-full bg-rose-500"></span> Inasistencia
                    </span>
                </div>
            </CardHeader>
            <CardContent class="pt-4">
                {#if chart.series.length === 0}
                    <div class="py-16 text-center text-xs text-muted-foreground">
                        <BarChart3 class="mx-auto size-12 text-muted-foreground/30 animate-pulse mb-2" />
                        <p class="font-medium text-foreground text-sm">No hay datos en el rango seleccionado</p>
                        <p class="mt-0.5">Los registros de asistencia de PostgreSQL se reflejarán en este gráfico.</p>
                    </div>
                {:else}
                    <!-- Visualizador de Barras Agrupadas con Escala Dinámica -->
                    <div class="space-y-4">
                        <div class="w-full overflow-x-auto pb-1">
                            <div class="h-60 w-full min-w-[340px] flex items-end gap-2 sm:gap-4 pt-4 pb-2 border-b border-border/80 px-2">
                                {#each chart.series as item (item.label)}
                                <div class="flex-1 flex flex-col items-center justify-end h-full group relative">
                                    <!-- Tooltip al posar el cursor -->
                                    <div class="absolute -top-12 z-20 hidden group-hover:flex flex-col items-center bg-popover text-popover-foreground border border-border shadow-lg rounded-md px-2 py-1 text-[10px] pointer-events-none whitespace-nowrap">
                                        <span class="font-bold">{item.sublabel}</span>
                                        <span>Puntual: {item.punctual} | Tardanza: {item.late} | Falta: {item.absent}</span>
                                        <span class="text-primary font-semibold">{item.attendance_rate}% Asistencia</span>
                                    </div>

                                    <!-- Grupo de Barras Verticales -->
                                    <div class="w-full flex items-end justify-center gap-1 h-full pb-1">
                                        <!-- Barra Puntual (Verde) -->
                                        <div
                                            style="height: {getBarHeightPercent(item.punctual, chart.summary.max_val)}%;"
                                            class="w-2 sm:w-3.5 rounded-t-sm bg-emerald-500 hover:bg-emerald-600 transition-all shadow-2xs"
                                            title="Puntual: {item.punctual}"
                                        ></div>

                                        <!-- Barra Tardanza (Ámbar) -->
                                        <div
                                            style="height: {getBarHeightPercent(item.late, chart.summary.max_val)}%;"
                                            class="w-2 sm:w-3.5 rounded-t-sm bg-amber-500 hover:bg-amber-600 transition-all shadow-2xs"
                                            title="Tardanza: {item.late}"
                                        ></div>

                                        <!-- Barra Falta (Rosa/Rojo) -->
                                        <div
                                            style="height: {getBarHeightPercent(item.absent, chart.summary.max_val)}%;"
                                            class="w-2 sm:w-3.5 rounded-t-sm bg-rose-500 hover:bg-rose-600 transition-all shadow-2xs"
                                            title="Falta: {item.absent}"
                                        ></div>
                                    </div>

                                    <!-- Etiqueta del Intervalo en el Eje X -->
                                    <div class="text-center pt-2">
                                        <div class="text-[11px] font-semibold text-foreground truncate max-w-[65px]">
                                            {item.label}
                                        </div>
                                        <div class="text-[9px] text-muted-foreground font-mono">
                                            {item.attendance_rate}%
                                        </div>
                                    </div>
                                </div>
                            {/each}
                        </div>
                    </div>

                        <!-- Resumen Inferior del Gráfico -->
                        <div class="flex flex-wrap items-center justify-between text-xs text-muted-foreground pt-1">
                            <div class="flex items-center gap-4">
                                <span>Total Puntuales: <strong class="text-emerald-600 font-semibold">{chart.summary.total_punctual}</strong></span>
                                <span>Total Tardanzas: <strong class="text-amber-600 font-semibold">{chart.summary.total_late}</strong></span>
                                <span>Total Faltas: <strong class="text-rose-600 font-semibold">{chart.summary.total_absent}</strong></span>
                            </div>
                            <div class="font-medium text-foreground">
                                Tasa Global del Periodo: <strong class="text-primary font-bold">{chart.summary.overall_rate}%</strong>
                            </div>
                        </div>
                    </div>
                {/if}
            </CardContent>
        </Card>

        <!-- Gráfico Secundario: Distribución y Tasa Porcentual del Periodo -->
        <Card class="border-border/60 shadow-xs lg:col-span-1">
            <CardHeader class="pb-2">
                <CardTitle class="text-sm font-semibold flex items-center justify-between">
                    <span>Efectividad de Asistencia</span>
                    <Badge variant="outline" class="text-[10px] border-primary/30 text-primary">
                        {chart.summary.overall_rate}% Global
                    </Badge>
                </CardTitle>
                <p class="text-xs text-muted-foreground">Distribución acumulada en el periodo</p>
            </CardHeader>
            <CardContent class="space-y-4 pt-2">
                <!-- Barra de Progreso Compuesta (Stacked Bar) -->
                <div class="space-y-1.5">
                    <div class="h-3 w-full rounded-full bg-muted overflow-hidden flex shadow-inner">
                        {#if chart.summary.total_attendance + chart.summary.total_absent > 0}
                            {@const total = chart.summary.total_attendance + chart.summary.total_absent}
                            {@const pctPunctual = (chart.summary.total_punctual / total) * 100}
                            {@const pctLate = (chart.summary.total_late / total) * 100}
                            {@const pctAbsent = (chart.summary.total_absent / total) * 100}
                            <div style="width: {pctPunctual}%;" class="h-full bg-emerald-500" title="Puntual: {pctPunctual.toFixed(1)}%"></div>
                            <div style="width: {pctLate}%;" class="h-full bg-amber-500" title="Tardanza: {pctLate.toFixed(1)}%"></div>
                            <div style="width: {pctAbsent}%;" class="h-full bg-rose-500" title="Falta: {pctAbsent.toFixed(1)}%"></div>
                        {:else}
                            <div class="w-full h-full bg-muted-foreground/20"></div>
                        {/if}
                    </div>
                    <div class="flex items-center justify-between text-[10px] text-muted-foreground font-mono">
                        <span>0%</span>
                        <span>100% Asistencia Esperada</span>
                    </div>
                </div>

                <!-- Detalle Métrico del Periodo -->
                <div class="divide-y divide-border/60 text-xs">
                    <div class="flex items-center justify-between py-2">
                        <span class="inline-flex items-center gap-1.5 text-muted-foreground">
                            <span class="size-2 rounded-full bg-emerald-500"></span> Asistencias Puntuales
                        </span>
                        <span class="font-bold text-foreground font-mono">{chart.summary.total_punctual}</span>
                    </div>
                    <div class="flex items-center justify-between py-2">
                        <span class="inline-flex items-center gap-1.5 text-muted-foreground">
                            <span class="size-2 rounded-full bg-amber-500"></span> Tardanzas Registradas
                        </span>
                        <span class="font-bold text-foreground font-mono">{chart.summary.total_late}</span>
                    </div>
                    <div class="flex items-center justify-between py-2">
                        <span class="inline-flex items-center gap-1.5 text-muted-foreground">
                            <span class="size-2 rounded-full bg-rose-500"></span> Faltas / Ausencias
                        </span>
                        <span class="font-bold text-foreground font-mono">{chart.summary.total_absent}</span>
                    </div>
                    <div class="flex items-center justify-between py-2 font-semibold">
                        <span class="text-foreground">Total Movimientos Evaluados</span>
                        <span class="text-primary font-mono">{chart.summary.total_attendance + chart.summary.total_absent}</span>
                    </div>
                </div>

                <div class="pt-2">
                    <Button variant="outline" asChild class="w-full text-xs h-8">
                        {#snippet children(props)}
                            <Link {...props} href="/attendance/history">
                                <span>Auditar en Historial de Asistencia</span>
                                <ArrowRight class="ml-1 size-3.5" />
                            </Link>
                        {/snippet}
                    </Button>
                </div>
            </CardContent>
        </Card>
    </div>

    <!-- Bloques Secundarios: Estado por Puntos de Acceso y Últimos Escaneos de PostgreSQL -->
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Puntos de Control y Puertas de PostgreSQL -->
        <Card class="border-border/60 shadow-xs lg:col-span-1">
            <CardHeader>
                <CardTitle class="text-sm font-semibold flex items-center justify-between">
                    <span>Terminales y Puertas QR</span>
                    <Badge variant="outline" class="text-[10px]">PostgreSQL</Badge>
                </CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
                {#if terminals.length === 0}
                    <div class="py-8 text-center text-xs text-muted-foreground">
                        <Radio class="mx-auto size-8 text-muted-foreground/30 animate-pulse mb-2" />
                        <p class="font-medium text-foreground">No hay terminales configuradas</p>
                        <p class="mt-0.5">Registre terminales de ingreso o salida en Configuración.</p>
                    </div>
                {:else}
                    {#each terminals as terminal (terminal.id)}
                        <div class="rounded-lg border border-border/60 p-3 flex items-center justify-between">
                            <div>
                                <div class="text-xs font-semibold text-foreground flex items-center gap-1.5">
                                    <span class="size-1.5 rounded-full {terminal.is_active ? 'bg-emerald-500' : 'bg-rose-500'}"></span>
                                    <span>{terminal.name}</span>
                                </div>
                                <div class="text-[10px] text-muted-foreground mt-0.5">
                                    {terminal.code} • {terminal.location}
                                </div>
                            </div>
                            <div class="text-right">
                                <Badge
                                    variant="outline"
                                    class="text-[10px] font-mono {terminal.mode === 'entry' ? 'border-emerald-500/40 text-emerald-600' : 'border-blue-500/40 text-blue-600'}"
                                >
                                    {terminal.mode_label}
                                </Badge>
                                {#if terminal.last_heartbeat}
                                    <div class="text-[9px] text-muted-foreground mt-0.5">
                                        Activo: {terminal.last_heartbeat}
                                    </div>
                                {/if}
                            </div>
                        </div>
                    {/each}
                {/if}

                <div class="pt-2">
                    <Button variant="outline" asChild class="w-full text-xs h-8">
                        {#snippet children(props)}
                            <Link {...props} href="/attendance/settings">
                                <span>Administrar Terminales</span>
                                <ArrowRight class="ml-1 size-3.5" />
                            </Link>
                        {/snippet}
                    </Button>
                </div>
            </CardContent>
        </Card>

        <!-- Monitor de Últimos Escaneos Reales -->
        <Card class="border-border/60 shadow-xs lg:col-span-2">
            <CardHeader class="flex flex-row items-center justify-between pb-3">
                <div>
                    <CardTitle class="text-sm font-semibold">Últimos Accesos Registrados Hoy</CardTitle>
                    <p class="text-xs text-muted-foreground">Movimientos en tiempo real en portería y terminales autorizadas</p>
                </div>
                <Button variant="ghost" size="sm" asChild class="text-xs h-7">
                    {#snippet children(props)}
                        <Link {...props} href="/attendance/history">Ver Historial Completo</Link>
                    {/snippet}
                </Button>
            </CardHeader>
            <CardContent>
                {#if recent_scans.length === 0}
                    <div class="py-12 text-center text-xs text-muted-foreground">
                        <QrCode class="mx-auto size-10 text-muted-foreground/30 animate-pulse mb-2" />
                        <p class="font-medium text-foreground">No se registran accesos hoy</p>
                        <p class="mt-0.5">
                            Los ingresos y salidas escaneados en puerta aparecerán automáticamente aquí.
                        </p>
                    </div>
                {:else}
                    <div class="divide-y divide-border/60">
                        {#each recent_scans as scan (scan.id)}
                            <div class="flex items-center justify-between py-2.5 gap-2">
                                <div class="flex items-center gap-3 min-w-0">
                                    <div class="flex size-8 shrink-0 items-center justify-center rounded-full font-bold text-xs {scan.action === 'entry' ? (scan.status === 'present' ? 'bg-emerald-500/10 text-emerald-600' : 'bg-amber-500/10 text-amber-600') : (scan.is_early_exit ? 'bg-amber-500/10 text-amber-600' : 'bg-blue-500/10 text-blue-600')}">
                                        {getInitials(scan.first_name, scan.last_name)}
                                    </div>
                                    <div class="min-w-0">
                                        <div class="text-xs font-medium text-foreground truncate">{scan.student_name}</div>
                                        <div class="text-[10px] text-muted-foreground font-mono flex items-center gap-1.5 flex-wrap">
                                            <span>{scan.classroom}</span>
                                            <span>•</span>
                                            <span>Cód: {scan.student_code}</span>
                                            <span>•</span>
                                            <span>Turno {scan.shift}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-right shrink-0">
                                    {#if scan.action === 'entry'}
                                        {#if scan.status === 'present'}
                                            <Badge variant="outline" class="border-emerald-500/40 text-emerald-700 bg-emerald-500/10 text-[10px]">
                                                <ArrowDownRight class="mr-1 size-3 text-emerald-600" />
                                                Entrada Puntual ({scan.entry_time})
                                            </Badge>
                                        {:else}
                                            <Badge variant="outline" class="border-amber-500/40 text-amber-700 bg-amber-500/10 text-[10px]">
                                                <Clock class="mr-1 size-3 text-amber-600" />
                                                Tardanza ({scan.entry_time})
                                            </Badge>
                                        {/if}
                                    {:else}
                                        {#if scan.is_early_exit}
                                            <Badge variant="outline" class="border-amber-500/40 text-amber-700 bg-amber-500/10 text-[10px]" title={scan.early_exit_reason || ''}>
                                                <LogOut class="mr-1 size-3 text-amber-600" />
                                                Salida Anticipada ({scan.exit_time})
                                            </Badge>
                                        {:else}
                                            <Badge variant="outline" class="border-blue-500/40 text-blue-700 bg-blue-500/10 text-[10px]">
                                                <ArrowUpRight class="mr-1 size-3 text-blue-600" />
                                                Salida Regular ({scan.exit_time})
                                            </Badge>
                                        {/if}
                                    {/if}
                                </div>
                            </div>
                        {/each}
                    </div>
                {/if}
            </CardContent>
        </Card>
    </div>
</div>
