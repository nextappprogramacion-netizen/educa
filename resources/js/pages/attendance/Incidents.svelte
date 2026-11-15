<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            {
                title: 'Asistencia',
                href: '/attendance/overview',
            },
            {
                title: 'Gestión de Incidencias',
                href: '/attendance/incidents',
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
    import FileText from '@lucide/svelte/icons/file-text';
    import Filter from '@lucide/svelte/icons/filter';
    import LogOut from '@lucide/svelte/icons/log-out';
    import Plus from '@lucide/svelte/icons/plus';
    import RotateCcw from '@lucide/svelte/icons/rotate-ccw';
    import Search from '@lucide/svelte/icons/search';
    import ShieldCheck from '@lucide/svelte/icons/shield-check';
    import UserCheck from '@lucide/svelte/icons/user-check';
    import UserX from '@lucide/svelte/icons/user-x';
    import X from '@lucide/svelte/icons/x';
    import AppHead from '@/components/AppHead.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
    import { Input } from '@/components/ui/input';

    interface IncidentStudent {
        id: number;
        code: string;
        document_number: string | null;
        full_name: string;
        first_name: string;
        last_name: string;
        avatar_url: string | null;
        classroom: string;
        shift: string;
    }

    interface IncidentItem {
        id: number;
        type: 'tardiness' | 'card_forgotten' | 'early_exit' | string;
        type_label: string;
        date: string | null;
        date_formatted: string;
        time: string | null;
        reason: string;
        responsible_name: string;
        responsible_relationship: string | null;
        responsible_display: string;
        status: string;
        is_justified: boolean;
        status_label: string;
        justification_reason: string | null;
        justified_at: string | null;
        justified_by: string | null;
        registered_by: string | null;
        created_at_formatted: string | null;
        student: IncidentStudent;
        has_attendance_record: boolean;
    }

    interface StudentCatalogItem {
        id: number;
        code: string;
        document_number: string | null;
        full_name: string;
        classroom: string;
        shift: string;
    }

    interface IncidentStats {
        total: number;
        tardiness: number;
        card_forgotten: number;
        early_exit: number;
        justified: number;
        pending: number;
    }

    interface IncidentFilters {
        type: string;
        status: string;
        date: string;
        search: string;
    }

    let {
        incidents = [],
        students = [],
        stats = {
            total: 0,
            tardiness: 0,
            card_forgotten: 0,
            early_exit: 0,
            justified: 0,
            pending: 0,
        },
        filters = {
            type: 'all',
            status: 'all',
            date: '',
            search: '',
        },
    }: {
        incidents?: IncidentItem[];
        students?: StudentCatalogItem[];
        stats?: IncidentStats;
        filters?: IncidentFilters;
    } = $props();

    // Filtros de búsqueda
    let typeVal = $state(filters.type ?? 'all');
    let statusVal = $state(filters.status ?? 'all');
    let dateVal = $state(filters.date ?? '');
    let searchVal = $state(filters.search ?? '');

    $effect(() => {
        typeVal = filters.type ?? 'all';
        statusVal = filters.status ?? 'all';
        dateVal = filters.date ?? '';
        searchVal = filters.search ?? '';
    });

    let hasActiveFilters = $derived.by(() => {
        return (
            typeVal !== 'all' ||
            statusVal !== 'all' ||
            Boolean(dateVal) ||
            Boolean(searchVal.trim())
        );
    });

    function applyFilters() {
        router.get(
            '/attendance/incidents',
            {
                type: typeVal !== 'all' ? typeVal : undefined,
                status: statusVal !== 'all' ? statusVal : undefined,
                date: dateVal || undefined,
                search: searchVal.trim() || undefined,
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

    function clearFilters() {
        typeVal = 'all';
        statusVal = 'all';
        dateVal = '';
        searchVal = '';

        router.get(
            '/attendance/incidents',
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }

    // Modal: Registrar Nueva Incidencia
    let isRegisterModalOpen = $state(false);
    let formStudentId = $state<number | ''>('');
    let formStudentSearch = $state('');
    let formType = $state<'tardiness' | 'card_forgotten' | 'early_exit'>('tardiness');
    let formDate = $state('');
    let formTime = $state('');
    let formReason = $state('');
    let formResponsibleName = $state('');
    let formResponsibleRelationship = $state('Madre');
    let formIsJustified = $state(false);
    let formJustificationReason = $state('');
    let isSubmitting = $state(false);
    let formErrors = $state<Record<string, string>>({});

    let filteredCatalogStudents = $derived.by(() => {
        const query = formStudentSearch.toLowerCase().trim();
        if (!query) return students;
        return students.filter(
            (s) =>
                s.full_name.toLowerCase().includes(query) ||
                (s.document_number && s.document_number.includes(query)) ||
                (s.code && s.code.toLowerCase().includes(query)),
        );
    });

    function getNowDateString() {
        const now = new Date();
        const y = now.getFullYear();
        const m = String(now.getMonth() + 1).padStart(2, '0');
        const d = String(now.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }

    function getNowTimeString() {
        const now = new Date();
        const h = String(now.getHours()).padStart(2, '0');
        const min = String(now.getMinutes()).padStart(2, '0');
        return `${h}:${min}`;
    }

    function openRegisterModal(preselectedType?: 'tardiness' | 'card_forgotten' | 'early_exit') {
        formStudentId = '';
        formStudentSearch = '';
        formType = preselectedType ?? 'tardiness';
        formDate = getNowDateString();
        formTime = getNowTimeString();
        formReason = '';
        formResponsibleName = '';
        formResponsibleRelationship = 'Madre';
        formIsJustified = false;
        formJustificationReason = '';
        formErrors = {};
        isRegisterModalOpen = true;
    }

    function handleRegisterSubmit() {
        formErrors = {};

        if (!formStudentId) {
            formErrors.student_id = 'Debe seleccionar un estudiante.';
        }
        if (!formDate) {
            formErrors.date = 'La fecha de la incidencia es obligatoria.';
        }
        if (!formReason.trim()) {
            formErrors.reason = 'El motivo de la incidencia es obligatorio.';
        }
        if (!formResponsibleName.trim()) {
            formErrors.responsible_name = 'Indique la persona responsable o apoderado.';
        }

        if (Object.keys(formErrors).length > 0) {
            return;
        }

        isSubmitting = true;
        router.post(
            '/attendance/incidents',
            {
                student_id: formStudentId,
                type: formType,
                date: formDate,
                time: formTime || undefined,
                reason: formReason.trim(),
                responsible_name: formResponsibleName.trim(),
                responsible_relationship: formResponsibleRelationship || undefined,
                is_justified: formIsJustified,
                justification_reason: formIsJustified
                    ? (formJustificationReason.trim() || formReason.trim())
                    : undefined,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    isRegisterModalOpen = false;
                },
                onError: (errors) => {
                    formErrors = errors;
                },
                onFinish: () => {
                    isSubmitting = false;
                },
            },
        );
    }

    // Modal: Justificar Incidencia Existente
    let isJustifyModalOpen = $state(false);
    let selectedIncident = $state<IncidentItem | null>(null);
    let justifyReason = $state('');
    let justifyResponsible = $state('');
    let isJustifying = $state(false);
    let justifyErrors = $state<Record<string, string>>({});

    function openJustifyModal(incident: IncidentItem) {
        selectedIncident = incident;
        justifyReason = incident.justification_reason || '';
        justifyResponsible = incident.responsible_name || '';
        justifyErrors = {};
        isJustifyModalOpen = true;
    }

    function handleJustifySubmit() {
        if (!selectedIncident) return;
        justifyErrors = {};

        if (!justifyReason.trim()) {
            justifyErrors.justification_reason = 'El motivo de la justificación es obligatorio.';
            return;
        }

        isJustifying = true;
        router.patch(
            `/attendance/incidents/${selectedIncident.id}/justify`,
            {
                justification_reason: justifyReason.trim(),
                responsible_name: justifyResponsible.trim() || undefined,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    isJustifyModalOpen = false;
                    selectedIncident = null;
                },
                onError: (errors) => {
                    justifyErrors = errors;
                },
                onFinish: () => {
                    isJustifying = false;
                },
            },
        );
    }
</script>

<AppHead title="Gestión de Incidencias de Asistencia - PostgreSQL" />

<div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
    <!-- Encabezado de Incidencias -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight text-foreground">
                    Gestión de Incidencias y Excepciones
                </h1>
                <Badge variant="outline" class="border-primary/40 bg-primary/10 text-primary text-xs font-semibold">
                    PostgreSQL
                </Badge>
            </div>
            <p class="text-sm text-muted-foreground">
                Registro y justificación de tardanzas, olvidos de carnet QR y salidas anticipadas, conservando los registros originales de asistencia.
            </p>
        </div>

        <div class="flex items-center gap-2">
            <Button onclick={() => openRegisterModal()} class="bg-primary text-white shadow-xs text-xs">
                <Plus class="mr-1.5 size-4" /> Registrar Nueva Incidencia
            </Button>
        </div>
    </div>

    <!-- Tarjetas de Resumen de Incidencias -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
        <!-- Tardanzas -->
        <Card class="border-amber-500/20 bg-amber-500/5 shadow-xs cursor-pointer hover:border-amber-500/40 transition-colors" onclick={() => { typeVal = 'tardiness'; applyFilters(); }}>
            <CardHeader class="flex flex-row items-center justify-between pb-2">
                <CardTitle class="text-xs font-semibold uppercase text-amber-700 dark:text-amber-400">Tardanzas</CardTitle>
                <Clock class="size-4 text-amber-600 dark:text-amber-400" />
            </CardHeader>
            <CardContent>
                <div class="text-2xl font-bold text-amber-600 dark:text-amber-400">{stats.tardiness}</div>
                <p class="text-[11px] text-muted-foreground mt-0.5">Ingresos posteriores al horario</p>
            </CardContent>
        </Card>

        <!-- Olvidos de Carnet QR -->
        <Card class="border-blue-500/20 bg-blue-500/5 shadow-xs cursor-pointer hover:border-blue-500/40 transition-colors" onclick={() => { typeVal = 'card_forgotten'; applyFilters(); }}>
            <CardHeader class="flex flex-row items-center justify-between pb-2">
                <CardTitle class="text-xs font-semibold uppercase text-blue-700 dark:text-blue-400">Olvidos de Carnet</CardTitle>
                <FileText class="size-4 text-blue-600 dark:text-blue-400" />
            </CardHeader>
            <CardContent>
                <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">{stats.card_forgotten}</div>
                <p class="text-[11px] text-muted-foreground mt-0.5">Accesos manuales en portería</p>
            </CardContent>
        </Card>

        <!-- Salidas Anticipadas -->
        <Card class="border-indigo-500/20 bg-indigo-500/5 shadow-xs cursor-pointer hover:border-indigo-500/40 transition-colors" onclick={() => { typeVal = 'early_exit'; applyFilters(); }}>
            <CardHeader class="flex flex-row items-center justify-between pb-2">
                <CardTitle class="text-xs font-semibold uppercase text-indigo-700 dark:text-indigo-400">Salidas Anticipadas</CardTitle>
                <LogOut class="size-4 text-indigo-600 dark:text-indigo-400" />
            </CardHeader>
            <CardContent>
                <div class="text-2xl font-bold text-indigo-600 dark:text-indigo-400">{stats.early_exit}</div>
                <p class="text-[11px] text-muted-foreground mt-0.5">Retiros antes del fin de jornada</p>
            </CardContent>
        </Card>

        <!-- Justificadas -->
        <Card class="border-emerald-500/20 bg-emerald-500/5 shadow-xs cursor-pointer hover:border-emerald-500/40 transition-colors" onclick={() => { statusVal = 'justified'; applyFilters(); }}>
            <CardHeader class="flex flex-row items-center justify-between pb-2">
                <CardTitle class="text-xs font-semibold uppercase text-emerald-700 dark:text-emerald-400">Justificadas</CardTitle>
                <CheckCircle2 class="size-4 text-emerald-600 dark:text-emerald-400" />
            </CardHeader>
            <CardContent>
                <div class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">{stats.justified}</div>
                <p class="text-[11px] text-muted-foreground mt-0.5">Con sustento aprobado</p>
            </CardContent>
        </Card>

        <!-- Pendientes -->
        <Card class="border-rose-500/20 bg-rose-500/5 shadow-xs cursor-pointer hover:border-rose-500/40 transition-colors" onclick={() => { statusVal = 'pending'; applyFilters(); }}>
            <CardHeader class="flex flex-row items-center justify-between pb-2">
                <CardTitle class="text-xs font-semibold uppercase text-rose-700 dark:text-rose-400">Por Justificar</CardTitle>
                <AlertCircle class="size-4 text-rose-600 dark:text-rose-400" />
            </CardHeader>
            <CardContent>
                <div class="text-2xl font-bold text-rose-600 dark:text-rose-400">{stats.pending}</div>
                <p class="text-[11px] text-muted-foreground mt-0.5">Pendientes de descargo</p>
            </CardContent>
        </Card>
    </div>

    <!-- Barra de Filtros -->
    <Card class="border-border/60 shadow-xs">
        <CardContent class="p-4 space-y-3">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-1.5 text-xs font-semibold text-foreground">
                    <Filter class="size-3.5 text-primary" />
                    <span>Filtros de Búsqueda</span>
                </div>
                {#if hasActiveFilters}
                    <Button variant="ghost" size="sm" onclick={clearFilters} class="text-xs h-7 px-2 text-muted-foreground hover:text-foreground">
                        <RotateCcw class="mr-1 size-3" /> Limpiar Filtros
                    </Button>
                {/if}
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <!-- 1. Tipo de Incidencia -->
                <div>
                    <label for="filter-type" class="block text-[11px] font-semibold text-muted-foreground mb-1">
                        Tipo de Incidencia:
                    </label>
                    <select
                        id="filter-type"
                        bind:value={typeVal}
                        onchange={applyFilters}
                        class="w-full rounded-md border border-input bg-background px-2.5 py-1 text-xs shadow-xs focus:outline-none focus:ring-1 focus:ring-primary h-9 font-medium"
                    >
                        <option value="all">Todas las Incidencias</option>
                        <option value="tardiness">Solo Tardanzas</option>
                        <option value="card_forgotten">Solo Olvidos de Carnet</option>
                        <option value="early_exit">Solo Salidas Anticipadas</option>
                    </select>
                </div>

                <!-- 2. Estado de Justificación -->
                <div>
                    <label for="filter-status" class="block text-[11px] font-semibold text-muted-foreground mb-1">
                        Estado de Justificación:
                    </label>
                    <select
                        id="filter-status"
                        bind:value={statusVal}
                        onchange={applyFilters}
                        class="w-full rounded-md border border-input bg-background px-2.5 py-1 text-xs shadow-xs focus:outline-none focus:ring-1 focus:ring-primary h-9 font-medium"
                    >
                        <option value="all">Todos los Estados</option>
                        <option value="justified">Justificadas</option>
                        <option value="pending">Pendientes de Justificar</option>
                    </select>
                </div>

                <!-- 3. Filtro por Fecha -->
                <div>
                    <label for="filter-date" class="block text-[11px] font-semibold text-muted-foreground mb-1">
                        Fecha:
                    </label>
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

                <!-- 4. Buscador -->
                <div>
                    <label for="filter-search" class="block text-[11px] font-semibold text-muted-foreground mb-1">
                        Buscar Alumno o Responsable:
                    </label>
                    <div class="relative">
                        <Search class="absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                        <Input
                            id="filter-search"
                            type="text"
                            placeholder="Nombre, DNI o responsable..."
                            bind:value={searchVal}
                            oninput={handleSearchInput}
                            class="pl-8 text-xs h-9"
                        />
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>

    <!-- Bandeja de Casos de Incidencias desde PostgreSQL -->
    <div class="overflow-hidden rounded-xl border border-border/70 bg-card shadow-xs">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs min-w-[780px]">
                <thead class="border-b border-border/70 bg-muted/40 font-semibold text-muted-foreground uppercase text-[11px]">
                    <tr>
                        <th class="px-4 py-3">Estudiante</th>
                        <th class="px-4 py-3">Tipo de Incidencia</th>
                        <th class="px-4 py-3">Fecha y Hora</th>
                        <th class="px-4 py-3">Motivo Registrado</th>
                        <th class="px-4 py-3">Persona Responsable</th>
                        <th class="px-4 py-3 text-center">Estado</th>
                        <th class="px-4 py-3 text-right">Acción</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/60">
                    {#if incidents.length === 0}
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-muted-foreground">
                                <div class="max-w-sm mx-auto space-y-2">
                                    <FileText class="mx-auto size-12 text-muted-foreground/30 animate-pulse" />
                                    <p class="font-semibold text-foreground text-sm">
                                        No se encontraron incidencias
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        No existen registros en PostgreSQL que coincidan con los filtros seleccionados.
                                    </p>
                                    <div class="pt-2">
                                        <Button
                                            size="sm"
                                            onclick={() => openRegisterModal()}
                                            class="bg-primary text-white text-xs h-8 shadow-xs"
                                        >
                                            <Plus class="mr-1.5 size-3" /> Registrar Nueva Incidencia
                                        </Button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    {:else}
                        {#each incidents as incident (incident.id)}
                            <tr class="hover:bg-muted/30 transition-colors">
                                <!-- Estudiante -->
                                <td class="px-4 py-3">
                                    <div class="font-medium text-foreground">
                                        {incident.student.full_name}
                                    </div>
                                    <div class="text-[10px] text-muted-foreground font-mono flex items-center gap-1.5">
                                        <span>{incident.student.classroom}</span>
                                        <span>•</span>
                                        <span>DNI: {incident.student.document_number || '—'}</span>
                                        <span>•</span>
                                        <span>Turno {incident.student.shift}</span>
                                    </div>
                                </td>

                                <!-- Tipo de Incidencia -->
                                <td class="px-4 py-3 whitespace-nowrap">
                                    {#if incident.type === 'tardiness'}
                                        <Badge variant="outline" class="border-amber-500/40 text-amber-700 bg-amber-500/10 font-semibold text-[10px]">
                                            <Clock class="mr-1 size-3" /> Tardanza
                                        </Badge>
                                    {:else if incident.type === 'card_forgotten'}
                                        <Badge variant="outline" class="border-blue-500/40 text-blue-700 bg-blue-500/10 font-semibold text-[10px]">
                                            <FileText class="mr-1 size-3" /> Olvido de Carnet QR
                                        </Badge>
                                    {:else if incident.type === 'early_exit'}
                                        <Badge variant="outline" class="border-indigo-500/40 text-indigo-700 bg-indigo-500/10 font-semibold text-[10px]">
                                            <LogOut class="mr-1 size-3" /> Salida Anticipada
                                        </Badge>
                                    {:else}
                                        <Badge variant="outline" class="text-[10px]">
                                            {incident.type_label}
                                        </Badge>
                                    {/if}
                                </td>

                                <!-- Fecha y Hora -->
                                <td class="px-4 py-3 whitespace-nowrap font-mono">
                                    <div class="font-medium text-foreground">{incident.date_formatted}</div>
                                    <div class="text-[10px] text-muted-foreground">
                                        {incident.time ? `${incident.time} hrs` : '—'}
                                    </div>
                                </td>

                                <!-- Motivo -->
                                <td class="px-4 py-3 min-w-[160px] max-w-[240px]">
                                    <div class="text-foreground truncate" title={incident.reason}>
                                        {incident.reason}
                                    </div>
                                    {#if incident.justification_reason && incident.is_justified}
                                        <div class="text-[10px] text-emerald-700 dark:text-emerald-400 italic truncate mt-0.5" title={incident.justification_reason}>
                                            Sustento: "{incident.justification_reason}"
                                        </div>
                                    {/if}
                                </td>

                                <!-- Persona Responsable -->
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="font-medium text-foreground">
                                        {incident.responsible_name}
                                    </div>
                                    {#if incident.responsible_relationship}
                                        <div class="text-[10px] text-muted-foreground">
                                            {incident.responsible_relationship}
                                        </div>
                                    {/if}
                                </td>

                                <!-- Estado -->
                                <td class="px-4 py-3 text-center whitespace-nowrap">
                                    {#if incident.is_justified}
                                        <Badge variant="outline" class="border-emerald-500/40 text-emerald-700 bg-emerald-500/10 font-semibold text-[10px]" title={incident.justified_by ? `Justificado por ${incident.justified_by}` : ''}>
                                            <CheckCircle2 class="mr-1 size-3" /> Justificado
                                        </Badge>
                                    {:else}
                                        <Badge variant="outline" class="border-amber-500/40 text-amber-700 bg-amber-500/10 font-semibold text-[10px]">
                                            <Clock class="mr-1 size-3" /> Pendiente
                                        </Badge>
                                    {/if}
                                </td>

                                <!-- Acciones -->
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    {#if !incident.is_justified}
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onclick={() => openJustifyModal(incident)}
                                            class="h-7 text-xs border-emerald-500/40 text-emerald-700 hover:bg-emerald-50 dark:hover:bg-emerald-950/20"
                                        >
                                            <ShieldCheck class="mr-1 size-3" /> Justificar
                                        </Button>
                                    {:else}
                                        <span class="text-[10px] text-muted-foreground font-mono">
                                            {incident.justified_at || 'Justificado'}
                                        </span>
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

<!-- Modal: Registrar Nueva Incidencia -->
{#if isRegisterModalOpen}
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-xs overflow-y-auto">
        <div class="w-full max-w-lg rounded-2xl border border-border bg-card p-6 shadow-2xl space-y-4 my-8">
            <!-- Título del Modal -->
            <div class="flex items-center justify-between border-b border-border/60 pb-3">
                <div class="space-y-0.5">
                    <h2 class="text-lg font-bold text-foreground">
                        Registrar Nueva Incidencia
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        Guarde tardanzas, olvidos de carnet QR o salidas anticipadas en PostgreSQL conservando los registros originales.
                    </p>
                </div>
                <button
                    type="button"
                    onclick={() => (isRegisterModalOpen = false)}
                    class="rounded-lg p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                >
                    <X class="size-5" />
                </button>
            </div>

            <!-- Formulario de Registro -->
            <form onsubmit={(e) => { e.preventDefault(); handleRegisterSubmit(); }} class="space-y-3.5 text-xs">
                <!-- Tipo de Incidencia -->
                <div>
                    <label for="inc-type" class="block font-semibold text-foreground mb-1">
                        Tipo de Incidencia <span class="text-rose-500">*</span>
                    </label>
                    <div class="grid grid-cols-3 gap-2">
                        <button
                            type="button"
                            onclick={() => (formType = 'tardiness')}
                            class="flex flex-col items-center gap-1 rounded-lg border p-2.5 text-center transition-all {formType === 'tardiness' ? 'border-amber-500 bg-amber-500/10 text-amber-700 font-bold dark:text-amber-400' : 'border-border text-muted-foreground hover:bg-muted/40'}"
                        >
                            <Clock class="size-4" />
                            <span class="text-[11px]">Tardanza</span>
                        </button>
                        <button
                            type="button"
                            onclick={() => (formType = 'card_forgotten')}
                            class="flex flex-col items-center gap-1 rounded-lg border p-2.5 text-center transition-all {formType === 'card_forgotten' ? 'border-blue-500 bg-blue-500/10 text-blue-700 font-bold dark:text-blue-400' : 'border-border text-muted-foreground hover:bg-muted/40'}"
                        >
                            <FileText class="size-4" />
                            <span class="text-[11px]">Olvido Carnet</span>
                        </button>
                        <button
                            type="button"
                            onclick={() => (formType = 'early_exit')}
                            class="flex flex-col items-center gap-1 rounded-lg border p-2.5 text-center transition-all {formType === 'early_exit' ? 'border-indigo-500 bg-indigo-500/10 text-indigo-700 font-bold dark:text-indigo-400' : 'border-border text-muted-foreground hover:bg-muted/40'}"
                        >
                            <LogOut class="size-4" />
                            <span class="text-[11px]">Salida Anticip.</span>
                        </button>
                    </div>
                </div>

                <!-- Selección de Estudiante -->
                <div>
                    <label for="inc-student" class="block font-semibold text-foreground mb-1">
                        Estudiante <span class="text-rose-500">*</span>
                    </label>
                    <div class="space-y-1.5">
                        <Input
                            placeholder="Buscar alumno por nombre o DNI..."
                            bind:value={formStudentSearch}
                            class="h-8 text-xs"
                        />
                        <select
                            id="inc-student"
                            bind:value={formStudentId}
                            class="w-full rounded-md border border-input bg-background px-2.5 py-1.5 text-xs shadow-xs focus:outline-none focus:ring-1 focus:ring-primary h-9 font-medium"
                        >
                            <option value="">-- Seleccione un estudiante --</option>
                            {#each filteredCatalogStudents as st (st.id)}
                                <option value={st.id}>
                                    {st.full_name} ({st.classroom} - Turno {st.shift})
                                </option>
                            {/each}
                        </select>
                    </div>
                    {#if formErrors.student_id}
                        <p class="text-[11px] text-rose-500 mt-1">{formErrors.student_id}</p>
                    {/if}
                </div>

                <!-- Fecha y Hora -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="inc-date" class="block font-semibold text-foreground mb-1">
                            Fecha <span class="text-rose-500">*</span>
                        </label>
                        <Input
                            id="inc-date"
                            type="date"
                            bind:value={formDate}
                            class="h-9 text-xs font-mono"
                        />
                        {#if formErrors.date}
                            <p class="text-[11px] text-rose-500 mt-1">{formErrors.date}</p>
                        {/if}
                    </div>
                    <div>
                        <label for="inc-time" class="block font-semibold text-foreground mb-1">
                            Hora (HH:mm)
                        </label>
                        <Input
                            id="inc-time"
                            type="time"
                            bind:value={formTime}
                            class="h-9 text-xs font-mono"
                        />
                    </div>
                </div>

                <!-- Motivo de la Incidencia -->
                <div>
                    <label for="inc-reason" class="block font-semibold text-foreground mb-1">
                        Motivo / Observación Detallada <span class="text-rose-500">*</span>
                    </label>
                    <textarea
                        id="inc-reason"
                        rows="2"
                        placeholder="Ej. Cita médica odontológica previa, desperfecto en transporte escolar, etc."
                        bind:value={formReason}
                        class="w-full rounded-md border border-input bg-background px-2.5 py-1.5 text-xs shadow-xs focus:outline-none focus:ring-1 focus:ring-primary"
                    ></textarea>
                    {#if formErrors.reason}
                        <p class="text-[11px] text-rose-500 mt-1">{formErrors.reason}</p>
                    {/if}
                </div>

                <!-- Persona Responsable y Parentesco -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label for="inc-resp-name" class="block font-semibold text-foreground mb-1">
                            Persona Responsable <span class="text-rose-500">*</span>
                        </label>
                        <Input
                            id="inc-resp-name"
                            placeholder="Ej. Carmen Morales"
                            bind:value={formResponsibleName}
                            class="h-9 text-xs"
                        />
                        {#if formErrors.responsible_name}
                            <p class="text-[11px] text-rose-500 mt-1">{formErrors.responsible_name}</p>
                        {/if}
                    </div>
                    <div>
                        <label for="inc-resp-rel" class="block font-semibold text-foreground mb-1">
                            Parentesco / Cargo
                        </label>
                        <select
                            id="inc-resp-rel"
                            bind:value={formResponsibleRelationship}
                            class="w-full rounded-md border border-input bg-background px-2.5 py-1 text-xs shadow-xs focus:outline-none focus:ring-1 focus:ring-primary h-9 font-medium"
                        >
                            <option value="Madre">Madre</option>
                            <option value="Padre">Padre</option>
                            <option value="Apoderado">Apoderado Legal</option>
                            <option value="Auxiliar">Auxiliar de Portería</option>
                            <option value="Tutor">Tutor / Docente</option>
                            <option value="Familiar">Familiar Autorizado</option>
                            <option value="Otro">Otro</option>
                        </select>
                    </div>
                </div>

                <!-- Checkbox de Justificación Inmediata -->
                <div class="rounded-lg border border-border/80 bg-muted/20 p-3 space-y-2">
                    <label class="flex items-center gap-2 cursor-pointer font-semibold text-foreground">
                        <input
                            type="checkbox"
                            bind:checked={formIsJustified}
                            class="size-4 rounded border-input text-primary focus:ring-primary"
                        />
                        <span>Aprobar justificación inmediatamente</span>
                    </label>

                    {#if formIsJustified}
                        <div class="pt-1">
                            <label for="inc-just-reason" class="block text-[11px] text-muted-foreground mb-1">
                                Sustento de justificación (opcional si es igual al motivo):
                            </label>
                            <Input
                                id="inc-just-reason"
                                placeholder="Ej. Certificado médico presentado en secretaría"
                                bind:value={formJustificationReason}
                                class="h-8 text-xs"
                            />
                        </div>
                    {/if}
                </div>

                <!-- Botones de Acción -->
                <div class="flex items-center justify-end gap-2 pt-2 border-t border-border/60">
                    <Button
                        type="button"
                        variant="outline"
                        onclick={() => (isRegisterModalOpen = false)}
                        class="text-xs h-9"
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="submit"
                        disabled={isSubmitting}
                        class="bg-primary text-white text-xs h-9 shadow-xs"
                    >
                        {isSubmitting ? 'Guardando en PostgreSQL...' : 'Guardar Incidencia'}
                    </Button>
                </div>
            </form>
        </div>
    </div>
{/if}

<!-- Modal: Justificar Incidencia Existente -->
{#if isJustifyModalOpen && selectedIncident}
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-xs overflow-y-auto">
        <div class="w-full max-w-md rounded-2xl border border-border bg-card p-6 shadow-2xl space-y-4 my-8">
            <div class="flex items-center justify-between border-b border-border/60 pb-3">
                <div>
                    <h2 class="text-base font-bold text-foreground">
                        Justificar Incidencia #{selectedIncident.id}
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        {selectedIncident.student.full_name} • {selectedIncident.type_label}
                    </p>
                </div>
                <button
                    type="button"
                    onclick={() => (isJustifyModalOpen = false)}
                    class="rounded-lg p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                >
                    <X class="size-5" />
                </button>
            </div>

            <div class="rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-900 dark:text-amber-200">
                <strong>Motivo original:</strong> {selectedIncident.reason} ({selectedIncident.date_formatted})
            </div>

            <form onsubmit={(e) => { e.preventDefault(); handleJustifySubmit(); }} class="space-y-3 text-xs">
                <div>
                    <label for="just-reason" class="block font-semibold text-foreground mb-1">
                        Motivo / Sustento de la Justificación <span class="text-rose-500">*</span>
                    </label>
                    <textarea
                        id="just-reason"
                        rows="3"
                        placeholder="Ej. Se recepcionó certificado médico oficial del MINSA o sustento de apoderado."
                        bind:value={justifyReason}
                        class="w-full rounded-md border border-input bg-background px-2.5 py-1.5 text-xs shadow-xs focus:outline-none focus:ring-1 focus:ring-primary"
                    ></textarea>
                    {#if justifyErrors.justification_reason}
                        <p class="text-[11px] text-rose-500 mt-1">{justifyErrors.justification_reason}</p>
                    {/if}
                </div>

                <div>
                    <label for="just-resp" class="block font-semibold text-foreground mb-1">
                        Responsable que presenta / autoriza
                    </label>
                    <Input
                        id="just-resp"
                        placeholder="Ej. Carmen Morales (Madre)"
                        bind:value={justifyResponsible}
                        class="h-9 text-xs"
                    />
                </div>

                <div class="flex items-center justify-end gap-2 pt-2 border-t border-border/60">
                    <Button
                        type="button"
                        variant="outline"
                        onclick={() => (isJustifyModalOpen = false)}
                        class="text-xs h-9"
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="submit"
                        disabled={isJustifying}
                        class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs h-9 shadow-xs"
                    >
                        {isJustifying ? 'Guardando...' : 'Aprobar Justificación'}
                    </Button>
                </div>
            </form>
        </div>
    </div>
{/if}
