<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            {
                title: 'Asistencia',
                href: '/attendance/overview',
            },
            {
                title: 'Reportes y Estadísticas',
                href: '/attendance/reports',
            },
        ],
    };
</script>

<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import AlertCircle from '@lucide/svelte/icons/alert-circle';
    import Calendar from '@lucide/svelte/icons/calendar';
    import CheckCircle2 from '@lucide/svelte/icons/check-circle-2';
    import Clock from '@lucide/svelte/icons/clock';
    import Download from '@lucide/svelte/icons/download';
    import FileSpreadsheet from '@lucide/svelte/icons/file-spreadsheet';
    import FileText from '@lucide/svelte/icons/file-text';
    import Filter from '@lucide/svelte/icons/filter';
    import LogOut from '@lucide/svelte/icons/log-out';
    import RotateCcw from '@lucide/svelte/icons/rotate-ccw';
    import TrendingUp from '@lucide/svelte/icons/trending-up';
    import Users from '@lucide/svelte/icons/users';
    import AppHead from '@/components/AppHead.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

    let {
        summary = {
            total_evaluated: 0,
            total_punctual: 0,
            total_late: 0,
            total_absent: 0,
            total_exits: 0,
            total_early_exits: 0,
            attendance_rate: 0,
            punctuality_rate: 0,
        },
        records = [],
        grades = [],
        sections = [],
        students = [],
        filters = {
            start_date: '',
            end_date: '',
            grade_id: 'all',
            section_id: 'all',
            student_id: 'all',
            type: 'all',
        },
    } = $props();

    let startDate = $state(filters.start_date ?? '');
    let endDate = $state(filters.end_date ?? '');
    let gradeId = $state(filters.grade_id ?? 'all');
    let sectionId = $state(filters.section_id ?? 'all');
    let studentId = $state(filters.student_id ?? 'all');
    let typeVal = $state(filters.type ?? 'all');
    let isExportingPdf = $state(false);
    let isExportingExcel = $state(false);

    $effect(() => {
        startDate = filters.start_date ?? '';
        endDate = filters.end_date ?? '';
        gradeId = filters.grade_id ?? 'all';
        sectionId = filters.section_id ?? 'all';
        studentId = filters.student_id ?? 'all';
        typeVal = filters.type ?? 'all';
    });

    // Secciones dependientes del grado seleccionado
    let filteredSections = $derived(
        gradeId && gradeId !== 'all'
            ? sections.filter((s: any) => s.grade_id === Number(gradeId))
            : sections
    );

    function handleGradeChange(e: Event) {
        const val = (e.target as HTMLSelectElement).value;
        gradeId = val;
        sectionId = 'all'; // reiniciar sección
        applyFilters();
    }

    function applyFilters() {
        router.get(
            '/attendance/reports',
            {
                start_date: startDate || undefined,
                end_date: endDate || undefined,
                grade_id: gradeId !== 'all' ? gradeId : undefined,
                section_id: sectionId !== 'all' ? sectionId : undefined,
                student_id: studentId !== 'all' ? studentId : undefined,
                type: typeVal !== 'all' ? typeVal : undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            }
        );
    }

    function resetFilters() {
        startDate = '';
        endDate = '';
        gradeId = 'all';
        sectionId = 'all';
        studentId = 'all';
        typeVal = 'all';
        router.get('/attendance/reports', {}, { preserveState: true, replace: true });
    }

    function buildQueryString(): string {
        const params = new URLSearchParams();
        if (startDate) params.set('start_date', startDate);
        if (endDate) params.set('end_date', endDate);
        if (gradeId && gradeId !== 'all') params.set('grade_id', String(gradeId));
        if (sectionId && sectionId !== 'all') params.set('section_id', String(sectionId));
        if (studentId && studentId !== 'all') params.set('student_id', String(studentId));
        if (typeVal && typeVal !== 'all') params.set('type', typeVal);
        return params.toString();
    }

    function downloadPdf() {
        isExportingPdf = true;
        const qs = buildQueryString();
        const url = `/attendance/reports/export/pdf${qs ? '?' + qs : ''}`;
        window.open(url, '_blank');
        setTimeout(() => {
            isExportingPdf = false;
        }, 1500);
    }

    function downloadExcel() {
        isExportingExcel = true;
        const qs = buildQueryString();
        const url = `/attendance/reports/export/excel${qs ? '?' + qs : ''}`;
        window.location.href = url;
        setTimeout(() => {
            isExportingExcel = false;
        }, 1500);
    }

    function quickFilterType(type: string) {
        typeVal = type;
        applyFilters();
    }
</script>

<AppHead title="Reportes y Estadísticas de Asistencia" />

<div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
    <!-- Encabezado con Botones de Exportación -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground">
                Reportes y Estadísticas de Asistencia Escolar
            </h1>
            <p class="text-sm text-muted-foreground">
                Consolidado cuantitativo y exportación oficial a PDF y Excel de ingresos, salidas, tardanzas y ausencias con datos reales de PostgreSQL.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <Button
                variant="outline"
                size="sm"
                class="text-xs border-emerald-600/40 text-emerald-700 hover:bg-emerald-50 dark:hover:bg-emerald-950/20"
                disabled={isExportingExcel}
                onclick={downloadExcel}
            >
                <FileSpreadsheet class="mr-1.5 size-4 text-emerald-600" />
                {isExportingExcel ? 'Generando Excel...' : 'Exportar Excel'}
            </Button>
            <Button
                size="sm"
                class="bg-primary text-xs text-primary-foreground hover:bg-primary/90"
                disabled={isExportingPdf}
                onclick={downloadPdf}
            >
                <FileText class="mr-1.5 size-4" />
                {isExportingPdf ? 'Generando PDF...' : 'Generar PDF Oficial'}
            </Button>
        </div>
    </div>

    <!-- Barra de Filtros Interactiva -->
    <Card class="border-border/60 shadow-xs">
        <CardHeader class="pb-3 border-b border-border/40">
            <div class="flex items-center justify-between">
                <div class="flex flex-wrap items-center gap-2">
                    <Filter class="size-4 text-primary" />
                    <CardTitle class="text-sm font-semibold">Filtros de Búsqueda y Exportación</CardTitle>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        variant="ghost"
                        size="sm"
                        class="h-7 text-xs text-muted-foreground hover:text-foreground"
                        onclick={resetFilters}
                    >
                        <RotateCcw class="mr-1 size-3" /> Restablecer
                    </Button>
                    <Button
                        size="sm"
                        class="h-7 text-xs bg-primary text-primary-foreground"
                        onclick={applyFilters}
                    >
                        Filtrar
                    </Button>
                </div>
            </div>
        </CardHeader>
        <CardContent class="p-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
                <!-- Fecha Inicio -->
                <div class="space-y-1">
                    <label for="start_date_input" class="text-xs font-medium text-muted-foreground">Fecha Desde</label>
                    <input
                        id="start_date_input"
                        type="date"
                        bind:value={startDate}
                        onchange={applyFilters}
                        class="w-full rounded-md border border-input bg-background px-2.5 py-1.5 text-xs shadow-xs focus:outline-none focus:ring-1 focus:ring-primary"
                    />
                </div>

                <!-- Fecha Hasta -->
                <div class="space-y-1">
                    <label for="end_date_input" class="text-xs font-medium text-muted-foreground">Fecha Hasta</label>
                    <input
                        id="end_date_input"
                        type="date"
                        bind:value={endDate}
                        onchange={applyFilters}
                        class="w-full rounded-md border border-input bg-background px-2.5 py-1.5 text-xs shadow-xs focus:outline-none focus:ring-1 focus:ring-primary"
                    />
                </div>

                <!-- Grado -->
                <div class="space-y-1">
                    <label for="grade_select" class="text-xs font-medium text-muted-foreground">Grado</label>
                    <select
                        id="grade_select"
                        value={gradeId}
                        onchange={handleGradeChange}
                        class="w-full rounded-md border border-input bg-background px-2.5 py-1.5 text-xs shadow-xs focus:outline-none focus:ring-1 focus:ring-primary"
                    >
                        <option value="all">Todos los grados</option>
                        {#each grades as g}
                            <option value={g.id}>{g.name}</option>
                        {/each}
                    </select>
                </div>

                <!-- Sección -->
                <div class="space-y-1">
                    <label for="section_select" class="text-xs font-medium text-muted-foreground">Sección</label>
                    <select
                        id="section_select"
                        bind:value={sectionId}
                        onchange={applyFilters}
                        class="w-full rounded-md border border-input bg-background px-2.5 py-1.5 text-xs shadow-xs focus:outline-none focus:ring-1 focus:ring-primary"
                    >
                        <option value="all">Todas las secciones</option>
                        {#each filteredSections as s}
                            <option value={s.id}>{s.name} ({s.shift})</option>
                        {/each}
                    </select>
                </div>

                <!-- Estudiante -->
                <div class="space-y-1">
                    <label for="student_select" class="text-xs font-medium text-muted-foreground">Estudiante</label>
                    <select
                        id="student_select"
                        bind:value={studentId}
                        onchange={applyFilters}
                        class="w-full rounded-md border border-input bg-background px-2.5 py-1.5 text-xs shadow-xs focus:outline-none focus:ring-1 focus:ring-primary"
                    >
                        <option value="all">Todos los estudiantes</option>
                        {#each students as st}
                            <option value={st.id}>{st.name}</option>
                        {/each}
                    </select>
                </div>

                <!-- Tipo de Movimiento -->
                <div class="space-y-1">
                    <label for="type_select" class="text-xs font-medium text-muted-foreground">Movimiento</label>
                    <select
                        id="type_select"
                        bind:value={typeVal}
                        onchange={applyFilters}
                        class="w-full rounded-md border border-input bg-background px-2.5 py-1.5 text-xs shadow-xs focus:outline-none focus:ring-1 focus:ring-primary"
                    >
                        <option value="all">Todos los registros</option>
                        <option value="punctual">Ingresos Puntuales</option>
                        <option value="late">Tardanzas</option>
                        <option value="absent">Inasistencias</option>
                        <option value="exits">Salidas Registradas</option>
                        <option value="early_exits">Salidas Anticipadas</option>
                    </select>
                </div>
            </div>
        </CardContent>
    </Card>

    <!-- Resumen Cuantitativo en Tiempo Real (PostgreSQL) -->
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <div class="rounded-xl border border-border/60 bg-card p-3 shadow-xs">
            <div class="text-[11px] font-medium text-muted-foreground flex items-center justify-between">
                <span>Total Evaluados</span>
                <Users class="size-3.5 text-primary" />
            </div>
            <div class="text-xl font-bold tracking-tight text-foreground mt-1">
                {summary.total_evaluated}
            </div>
            <div class="text-[10px] text-muted-foreground mt-0.5">En el periodo seleccionado</div>
        </div>

        <div class="rounded-xl border border-border/60 bg-card p-3 shadow-xs">
            <div class="text-[11px] font-medium text-muted-foreground flex items-center justify-between">
                <span>Ingresos Puntuales</span>
                <CheckCircle2 class="size-3.5 text-emerald-600" />
            </div>
            <div class="text-xl font-bold tracking-tight text-emerald-600 mt-1">
                {summary.total_punctual}
            </div>
            <div class="text-[10px] text-muted-foreground mt-0.5">{summary.punctuality_rate}% puntualidad</div>
        </div>

        <div class="rounded-xl border border-border/60 bg-card p-3 shadow-xs">
            <div class="text-[11px] font-medium text-muted-foreground flex items-center justify-between">
                <span>Tardanzas</span>
                <Clock class="size-3.5 text-amber-600" />
            </div>
            <div class="text-xl font-bold tracking-tight text-amber-600 mt-1">
                {summary.total_late}
            </div>
            <div class="text-[10px] text-muted-foreground mt-0.5">Llegadas fuera de hora</div>
        </div>

        <div class="rounded-xl border border-border/60 bg-card p-3 shadow-xs">
            <div class="text-[11px] font-medium text-muted-foreground flex items-center justify-between">
                <span>Inasistencias</span>
                <AlertCircle class="size-3.5 text-rose-600" />
            </div>
            <div class="text-xl font-bold tracking-tight text-rose-600 mt-1">
                {summary.total_absent}
            </div>
            <div class="text-[10px] text-muted-foreground mt-0.5">Faltas registradas</div>
        </div>

        <div class="rounded-xl border border-border/60 bg-card p-3 shadow-xs">
            <div class="text-[11px] font-medium text-muted-foreground flex items-center justify-between">
                <span>Salidas Registradas</span>
                <LogOut class="size-3.5 text-blue-600" />
            </div>
            <div class="text-xl font-bold tracking-tight text-blue-600 mt-1">
                {summary.total_exits}
            </div>
            <div class="text-[10px] text-muted-foreground mt-0.5">Marcadas en terminal</div>
        </div>

        <div class="rounded-xl border border-border/60 bg-card p-3 shadow-xs">
            <div class="text-[11px] font-medium text-muted-foreground flex items-center justify-between">
                <span>Salidas Anticipadas</span>
                <TrendingUp class="size-3.5 text-orange-600" />
            </div>
            <div class="text-xl font-bold tracking-tight text-orange-600 mt-1">
                {summary.total_early_exits}
            </div>
            <div class="text-[10px] text-muted-foreground mt-0.5">Con motivo y autorización</div>
        </div>
    </div>

    <!-- Accesos Rápidos de Reportes Preconfigurados -->
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <button
            type="button"
            class="text-left w-full rounded-lg border border-border/60 p-3 bg-card hover:border-primary/50 transition-all shadow-xs cursor-pointer focus:outline-none focus:ring-1 focus:ring-primary"
            onclick={() => quickFilterType('all')}
        >
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-foreground">Consolidado Completo</span>
                <Calendar class="size-3.5 text-primary" />
            </div>
            <p class="text-[11px] text-muted-foreground mt-1 line-clamp-2">
                Todos los ingresos, salidas, puntualidad y faltas del periodo.
            </p>
            <div class="mt-2 text-[10px] font-semibold text-primary flex items-center gap-1">
                <span>Filtrar y exportar</span> &rarr;
            </div>
        </button>

        <button
            type="button"
            class="text-left w-full rounded-lg border border-border/60 p-3 bg-card hover:border-rose-500/50 transition-all shadow-xs cursor-pointer focus:outline-none focus:ring-1 focus:ring-rose-500"
            onclick={() => quickFilterType('absent')}
        >
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-foreground">Reporte de Inasistencias</span>
                <Users class="size-3.5 text-rose-500" />
            </div>
            <p class="text-[11px] text-muted-foreground mt-1 line-clamp-2">
                Filtra exclusivamente a estudiantes con faltas registradas.
            </p>
            <div class="mt-2 text-[10px] font-semibold text-rose-600 flex items-center gap-1">
                <span>Ver sólo inasistencias</span> &rarr;
            </div>
        </button>

        <button
            type="button"
            class="text-left w-full rounded-lg border border-border/60 p-3 bg-card hover:border-amber-500/50 transition-all shadow-xs cursor-pointer focus:outline-none focus:ring-1 focus:ring-amber-500"
            onclick={() => quickFilterType('late')}
        >
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-foreground">Reporte de Tardanzas</span>
                <Clock class="size-3.5 text-amber-500" />
            </div>
            <p class="text-[11px] text-muted-foreground mt-1 line-clamp-2">
                Estudiantes que ingresaron después de la hora oficial sin tolerancia.
            </p>
            <div class="mt-2 text-[10px] font-semibold text-amber-600 flex items-center gap-1">
                <span>Ver sólo tardanzas</span> &rarr;
            </div>
        </button>

        <button
            type="button"
            class="text-left w-full rounded-lg border border-border/60 p-3 bg-card hover:border-orange-500/50 transition-all shadow-xs cursor-pointer focus:outline-none focus:ring-1 focus:ring-orange-500"
            onclick={() => quickFilterType('early_exits')}
        >
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-foreground">Salidas Anticipadas</span>
                <LogOut class="size-3.5 text-orange-500" />
            </div>
            <p class="text-[11px] text-muted-foreground mt-1 line-clamp-2">
                Salidas antes del fin de jornada escolar con justificación.
            </p>
            <div class="mt-2 text-[10px] font-semibold text-orange-600 flex items-center gap-1">
                <span>Ver retiros anticipados</span> &rarr;
            </div>
        </button>
    </div>

    <!-- Tabla Detallada de Registros con Vista Previa -->
    <Card class="border-border/60 shadow-xs">
        <CardHeader class="flex flex-row items-center justify-between pb-3 border-b border-border/40">
            <div>
                <CardTitle class="text-sm font-semibold">Detalle de Registros de Asistencia ({records.length})</CardTitle>
                <p class="text-xs text-muted-foreground">
                    Información detallada lista para generar documento oficial en PDF o descargar en Excel
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Badge variant="outline" class="font-mono text-xs">
                    Tasa General: {summary.attendance_rate}%
                </Badge>
            </div>
        </CardHeader>
        <CardContent class="p-0">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs border-collapse min-w-[880px]">
                    <thead>
                        <tr class="border-b border-border/60 bg-muted/40 text-muted-foreground text-[11px] font-medium">
                            <th class="p-3">Fecha</th>
                            <th class="p-3">Estudiante</th>
                            <th class="p-3">Grado y Sección</th>
                            <th class="p-3">Turno</th>
                            <th class="p-3">Hora Ingreso</th>
                            <th class="p-3">Estado / Puntualidad</th>
                            <th class="p-3">Hora Salida</th>
                            <th class="p-3">Salida Anticipada</th>
                            <th class="p-3">Observación</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/40">
                        {#if records.length === 0}
                            <tr>
                                <td colspan="9" class="p-8 text-center text-muted-foreground">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <FileSpreadsheet class="size-8 text-muted-foreground/40" />
                                        <p class="text-sm font-medium">No se encontraron registros para los filtros seleccionados.</p>
                                        <p class="text-xs">Prueba ampliando el rango de fechas o seleccionando otro grado o sección.</p>
                                    </div>
                                </td>
                            </tr>
                        {:else}
                            {#each records as r}
                                <tr class="hover:bg-muted/20 transition-colors">
                                    <td class="p-3 font-mono font-medium text-foreground whitespace-nowrap">
                                        {r.date}
                                    </td>
                                    <td class="p-3">
                                        <div class="font-semibold text-foreground">{r.student_name}</div>
                                        <div class="text-[11px] text-muted-foreground font-mono">
                                            {r.student_code} · DNI: {r.student_dni}
                                        </div>
                                    </td>
                                    <td class="p-3 whitespace-nowrap text-foreground">
                                        {r.classroom}
                                    </td>
                                    <td class="p-3 whitespace-nowrap text-muted-foreground">
                                        {r.shift}
                                    </td>
                                    <td class="p-3 font-mono font-semibold whitespace-nowrap">
                                        {#if r.entry_time}
                                            <span class="text-emerald-700 dark:text-emerald-400">{r.entry_time}</span>
                                        {:else}
                                            <span class="text-muted-foreground">—</span>
                                        {/if}
                                    </td>
                                    <td class="p-3 whitespace-nowrap">
                                        {#if r.status === 'present'}
                                            <Badge class="bg-emerald-600/15 text-emerald-700 dark:text-emerald-400 border-emerald-600/30 text-[10px]">
                                                Puntual
                                            </Badge>
                                        {:else if r.status === 'late'}
                                            <Badge class="bg-amber-600/15 text-amber-700 dark:text-amber-400 border-amber-600/30 text-[10px]">
                                                Tardanza
                                            </Badge>
                                        {:else if r.status === 'absent'}
                                            <Badge class="bg-rose-600/15 text-rose-700 dark:text-rose-400 border-rose-600/30 text-[10px]">
                                                Inasistencia
                                            </Badge>
                                        {:else}
                                            <Badge variant="outline" class="text-[10px]">
                                                {r.status_label}
                                            </Badge>
                                        {/if}
                                    </td>
                                    <td class="p-3 font-mono font-semibold whitespace-nowrap">
                                        {#if r.exit_time}
                                            <span class="text-blue-700 dark:text-blue-400">{r.exit_time}</span>
                                        {:else}
                                            <span class="text-muted-foreground">—</span>
                                        {/if}
                                    </td>
                                    <td class="p-3 whitespace-nowrap">
                                        {#if r.is_early_exit}
                                            <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-orange-600">
                                                <LogOut class="size-3" />
                                                Sí {r.early_exit_reason ? `(${r.early_exit_reason})` : ''}
                                            </span>
                                        {:else}
                                            <span class="text-muted-foreground text-[11px]">No</span>
                                        {/if}
                                    </td>
                                    <td class="p-3 text-muted-foreground text-[11px] max-w-[180px] truncate" title={r.observation ?? ''}>
                                        {#if r.is_justified}
                                            <span class="text-blue-600 font-semibold">[Justificado]</span>
                                        {/if}
                                        {r.observation ?? '—'}
                                    </td>
                                </tr>
                            {/each}
                        {/if}
                    </tbody>
                </table>
            </div>
        </CardContent>
    </Card>
</div>
