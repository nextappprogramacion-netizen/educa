<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            {
                title: 'Asistencia',
                href: '/attendance/overview',
            },
            {
                title: 'Credenciales QR',
                href: '/attendance/credentials',
            },
        ],
    };
</script>

<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import Ban from '@lucide/svelte/icons/ban';
    import CheckCircle2 from '@lucide/svelte/icons/check-circle-2';
    import Copy from '@lucide/svelte/icons/copy';
    import History from '@lucide/svelte/icons/history';
    import IdCard from '@lucide/svelte/icons/id-card';
    import Lock from '@lucide/svelte/icons/lock';
    import Printer from '@lucide/svelte/icons/printer';
    import QrCode from '@lucide/svelte/icons/qr-code';
    import RefreshCw from '@lucide/svelte/icons/refresh-cw';
    import Search from '@lucide/svelte/icons/search';
    import ShieldCheck from '@lucide/svelte/icons/shield-check';
    import Unlock from '@lucide/svelte/icons/unlock';
    import UserX from '@lucide/svelte/icons/user-x';
    import { toast } from 'svelte-sonner';
    import AppHead from '@/components/AppHead.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
    import { Input } from '@/components/ui/input';

    interface CredentialData {
        id: number;
        qr_code: string;
        status: 'active' | 'blocked' | 'invalidated';
        issued_at: string;
        invalidated_at?: string | null;
        is_active: boolean;
        is_blocked: boolean;
        is_invalidated: boolean;
    }

    interface CredentialHistoryItem {
        id: number;
        qr_code: string;
        status: string;
        issued_at: string;
        invalidated_at: string | null;
    }

    interface StudentItem {
        id: number;
        code: string;
        first_name: string;
        last_name: string;
        full_name: string;
        document_number: string;
        classroom: string;
        shift: string;
        is_active: boolean;
        avatar_url: string | null;
        credential: CredentialData | null;
        history?: CredentialHistoryItem[];
    }

    interface Metrics {
        total_students: number;
        active_credentials: number;
        pending_credentials: number;
        blocked_credentials: number;
        invalidated_credentials: number;
    }

    let {
        students = [],
        metrics = {
            total_students: 0,
            active_credentials: 0,
            pending_credentials: 0,
            blocked_credentials: 0,
            invalidated_credentials: 0,
        },
        filters = {
            search: '',
            status: 'all',
        },
    }: {
        students: StudentItem[];
        metrics: Metrics;
        filters: { search: string; status: string };
    } = $props();

    let selectedStudent = $state<StudentItem | null>(null);
    let searchTerm = $state('');
    let statusFilter = $state('all');
    let isGenerating = $state(false);
    let isRenewing = $state(false);
    let isBatchGenerating = $state(false);
    let searchTimeout: ReturnType<typeof setTimeout> | null = null;

    $effect(() => {
        searchTerm = filters.search || '';
        statusFilter = filters.status || 'all';
    });

    $effect(() => {
        if (students.length > 0) {
            if (!selectedStudent || !students.some((s) => s.id === selectedStudent?.id)) {
                selectedStudent = students[0];
            } else {
                selectedStudent = students.find((s) => s.id === selectedStudent?.id) || students[0];
            }
        } else {
            selectedStudent = null;
        }
    });

    function handleSearch() {
        if (searchTimeout) {
            clearTimeout(searchTimeout);
        }
        searchTimeout = setTimeout(() => {
            applyFilters();
        }, 300);
    }

    function applyFilters() {
        router.get(
            '/attendance/credentials',
            {
                search: searchTerm.trim() || undefined,
                status: statusFilter !== 'all' ? statusFilter : undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }

    function generateQrForStudent(student: StudentItem) {
        if (!student.is_active) {
            toast.error('No se puede generar credencial para un estudiante inactivo.');
            return;
        }

        isGenerating = true;
        router.post(
            `/attendance/credentials/${student.id}/generate`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    isGenerating = false;
                    toast.success(`Código QR generado exitosamente para ${student.full_name}.`);
                },
                onError: (errs) => {
                    isGenerating = false;
                    const msg = Object.values(errs)[0] as string;
                    toast.error(msg || 'Error al generar el código QR.');
                },
            },
        );
    }

    function renewCredential(student: StudentItem) {
        if (!student.is_active) {
            toast.error('No se puede renovar credencial a un estudiante inactivo.');
            return;
        }

        const confirmMsg = `¿Está seguro de renovar el carnet QR de ${student.full_name}?\n\nEl código QR anterior quedará INVALIDADO INMEDIATAMENTE y no podrá utilizarse para acceder al colegio, conservando su historial en PostgreSQL.`;
        if (!confirm(confirmMsg)) {
            return;
        }

        isRenewing = true;
        router.post(
            `/attendance/credentials/${student.id}/renew`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    isRenewing = false;
                    toast.success(`Credencial QR renovada. El código QR anterior fue invalidado de inmediato en PostgreSQL.`);
                },
                onError: (errs) => {
                    isRenewing = false;
                    const msg = Object.values(errs)[0] as string;
                    toast.error(msg || 'Error al renovar la credencial.');
                },
            },
        );
    }

    function blockCredential(student: StudentItem) {
        if (!confirm(`¿Bloquear la credencial QR de ${student.full_name}?\n\nEl estudiante no podrá registrar asistencia mediante su carnet hasta que sea reactivado.`)) {
            return;
        }

        router.post(
            `/attendance/credentials/${student.id}/block`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(`Credencial QR bloqueada para ${student.full_name}.`);
                },
                onError: (errs) => {
                    const msg = Object.values(errs)[0] as string;
                    toast.error(msg || 'Error al bloquear la credencial.');
                },
            },
        );
    }

    function unblockCredential(student: StudentItem) {
        router.post(
            `/attendance/credentials/${student.id}/unblock`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(`Credencial QR desbloqueada y reactivada para ${student.full_name}.`);
                },
                onError: (errs) => {
                    const msg = Object.values(errs)[0] as string;
                    toast.error(msg || 'Error al desbloquear la credencial.');
                },
            },
        );
    }

    function generateBatch() {
        if (!confirm('¿Desea generar un código QR único y seguro para todos los estudiantes activos que aún no tienen carnet?')) {
            return;
        }

        isBatchGenerating = true;
        router.post(
            '/attendance/credentials/generate-batch',
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    isBatchGenerating = false;
                    toast.success('Códigos QR procesados exitosamente en PostgreSQL.');
                },
                onError: (errs) => {
                    isBatchGenerating = false;
                    const msg = Object.values(errs)[0] as string;
                    toast.error(msg || 'Error al procesar lote.');
                },
            },
        );
    }

    function copyToken(token: string) {
        navigator.clipboard.writeText(token);
        toast.success('Identificador de QR copiado al portapapeles');
    }

    function printCarnet() {
        window.print();
    }
</script>

<AppHead title="Credenciales y Carnets Escolares QR" />

<div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6 print:p-0">
    <!-- Encabezado (Oculto en Impresión) -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between print:hidden">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground">
                Emisión y Gestión de Credenciales QR
            </h1>
            <p class="text-sm text-muted-foreground">
                Generación, bloqueo y renovación de credenciales escolares en PostgreSQL. La renovación invalida inmediatamente el QR anterior conservando el historial.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <Button
                variant="outline"
                size="sm"
                onclick={printCarnet}
                disabled={!selectedStudent || !selectedStudent.credential || selectedStudent.credential.is_invalidated}
                class="text-xs"
            >
                <Printer class="mr-1.5 size-4" /> Imprimir Carnet Actual
            </Button>
            <Button
                size="sm"
                onclick={generateBatch}
                disabled={isBatchGenerating || metrics.pending_credentials === 0}
                class="bg-primary text-xs shadow-xs"
            >
                {#if isBatchGenerating}
                    <RefreshCw class="mr-1.5 size-4 animate-spin" />
                    Generando Lote...
                {:else}
                    <RefreshCw class="mr-1.5 size-4" />
                    Generar Lote ({metrics.pending_credentials} Pendientes)
                {/if}
            </Button>
        </div>
    </div>

    <!-- Indicadores de Credenciales (Ocultos en Impresión) -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 print:hidden">
        <Card class="border-border/60 shadow-xs">
            <CardHeader class="pb-2">
                <CardTitle class="text-xs font-semibold uppercase text-muted-foreground">Total Carnets Vigentes</CardTitle>
            </CardHeader>
            <CardContent>
                <div class="text-2xl font-bold">
                    {metrics.active_credentials + metrics.blocked_credentials} / {metrics.total_students}
                </div>
                <p class="text-xs text-muted-foreground mt-1">
                    {metrics.total_students > 0
                        ? Math.round(((metrics.active_credentials + metrics.blocked_credentials) / metrics.total_students) * 100)
                        : 0}% de cobertura en alumnos activos
                </p>
            </CardContent>
        </Card>

        <Card class="border-emerald-500/20 bg-emerald-500/5 shadow-xs">
            <CardHeader class="pb-2">
                <CardTitle class="text-xs font-semibold uppercase text-emerald-700 dark:text-emerald-400">Carnets Activos</CardTitle>
            </CardHeader>
            <CardContent>
                <div class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                    {metrics.active_credentials}
                </div>
                <p class="text-xs text-muted-foreground mt-1">Habilitados para lectura QR en portería</p>
            </CardContent>
        </Card>

        <Card class="border-amber-500/20 bg-amber-500/5 shadow-xs">
            <CardHeader class="pb-2">
                <CardTitle class="text-xs font-semibold uppercase text-amber-700 dark:text-amber-400">Por Generar / Pendientes</CardTitle>
            </CardHeader>
            <CardContent>
                <div class="text-2xl font-bold text-amber-600 dark:text-amber-400">
                    {metrics.pending_credentials}
                </div>
                <p class="text-xs text-muted-foreground mt-1">Alumnos activos sin carnet emitido</p>
            </CardContent>
        </Card>

        <Card class="border-rose-500/20 bg-rose-500/5 shadow-xs">
            <CardHeader class="pb-2">
                <CardTitle class="text-xs font-semibold uppercase text-rose-700 dark:text-rose-400">Bloqueados / Extraviados</CardTitle>
            </CardHeader>
            <CardContent>
                <div class="text-2xl font-bold text-rose-600 dark:text-rose-400">
                    {metrics.blocked_credentials}
                </div>
                <p class="text-xs text-muted-foreground mt-1">
                    {metrics.invalidated_credentials > 0 ? `${metrics.invalidated_credentials} QR anteriores invalidados` : 'Invalidados para prevenir suplantación'}
                </p>
            </CardContent>
        </Card>
    </div>

    <!-- Área Principal: Vista Previa de Carnet y Tabla de Control -->
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12 print:block">
        <!-- Vista Previa de Credencial Escolar -->
        <div class="lg:col-span-4 print:w-full print:max-w-sm print:mx-auto space-y-4">
            <Card class="border-border/60 shadow-md">
                <CardHeader class="bg-muted/30 border-b border-border/40 pb-3 print:hidden">
                    <CardTitle class="text-xs font-semibold uppercase tracking-wider text-muted-foreground flex items-center justify-between">
                        <span>Vista Previa del Carnet</span>
                        {#if selectedStudent?.credential}
                            {#if selectedStudent.credential.is_active}
                                <Badge variant="outline" class="border-emerald-500/40 text-emerald-600 bg-emerald-50/50">
                                    Habilitado
                                </Badge>
                            {:else if selectedStudent.credential.is_blocked}
                                <Badge variant="outline" class="border-rose-500/40 text-rose-600 bg-rose-50/50">
                                    Bloqueado
                                </Badge>
                            {:else}
                                <Badge variant="outline" class="border-slate-500/40 text-slate-600 bg-slate-50/50">
                                    Invalidado
                                </Badge>
                            {/if}
                        {/if}
                    </CardTitle>
                </CardHeader>
                <CardContent class="p-6 flex flex-col items-center">
                    {#if selectedStudent}
                        <!-- Maqueta de Carnet Físico -->
                        <div id="printable-carnet" class="w-full max-w-[280px] rounded-2xl border-2 border-primary/30 bg-gradient-to-b from-primary/10 via-card to-card p-5 shadow-lg text-center space-y-4">
                            <div class="border-b border-border/60 pb-2">
                                <div class="text-xs font-extrabold tracking-wider uppercase text-primary">SISTEMA ESCOLAR EDUCA</div>
                                <div class="text-[10px] text-muted-foreground">Carnet de Estudiante 2026</div>
                            </div>

                            <!-- Foto del Alumno o Iniciales -->
                            <div class="size-20 mx-auto rounded-full bg-muted border-2 border-primary/40 flex items-center justify-center font-bold text-xl text-primary shadow-xs overflow-hidden">
                                {#if selectedStudent.avatar_url}
                                    <img src={selectedStudent.avatar_url} alt={selectedStudent.full_name} class="size-full object-cover" />
                                {:else}
                                    {selectedStudent.first_name[0]}{selectedStudent.last_name[0]}
                                {/if}
                            </div>

                            <div class="space-y-0.5">
                                <div class="font-bold text-sm text-foreground">
                                    {selectedStudent.first_name} {selectedStudent.last_name}
                                </div>
                                <div class="text-[11px] text-muted-foreground font-mono">
                                    DNI: {selectedStudent.document_number || '—'} • Cód: {selectedStudent.code}
                                </div>
                                <div class="text-[11px] font-semibold text-primary pt-0.5">
                                    {selectedStudent.classroom}
                                </div>
                            </div>

                            <!-- Código QR Único y Seguro -->
                            {#if selectedStudent.credential && !selectedStudent.credential.is_invalidated}
                                <div class="size-28 mx-auto bg-white p-2 rounded-xl border border-border shadow-xs flex flex-col items-center justify-center {selectedStudent.credential.is_blocked ? 'opacity-50 grayscale' : ''}">
                                    <QrCode class="size-24 text-black" />
                                </div>

                                <div class="space-y-1">
                                    <div class="text-[9px] font-mono text-muted-foreground truncate max-w-full px-2" title={selectedStudent.credential.qr_code}>
                                        {selectedStudent.credential.qr_code}
                                    </div>
                                    <div class="text-[9px] font-medium {selectedStudent.credential.is_blocked ? 'text-rose-600' : 'text-emerald-600'}">
                                        {#if selectedStudent.credential.is_blocked}
                                            CARNET TEMPORALMENTE BLOQUEADO
                                        {:else}
                                            CÓDIGO ÚNICO VIGENTE EN POSTGRESQL
                                        {/if}
                                    </div>
                                    <div class="text-[8px] text-muted-foreground">
                                        Sin datos personales expuestos en el QR
                                    </div>
                                </div>
                            {:else}
                                <div class="size-28 mx-auto bg-muted/30 p-2 rounded-xl border border-dashed border-border/80 flex flex-col items-center justify-center text-muted-foreground">
                                    <QrCode class="size-16 opacity-40 mb-1" />
                                    <span class="text-[10px] font-medium">Sin QR Vigente</span>
                                </div>

                                <div class="text-[10px] text-amber-600 font-medium">
                                    Pendiente de emisión o renovación
                                </div>
                            {/if}
                        </div>

                        <!-- Botones de Acción sobre el Estudiante Seleccionado (Ocultos en Impresión) -->
                        <div class="flex flex-col gap-2 pt-4 w-full print:hidden">
                            {#if selectedStudent.credential && !selectedStudent.credential.is_invalidated}
                                <div class="flex flex-wrap items-center gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onclick={printCarnet}
                                        disabled={selectedStudent.credential.is_blocked}
                                        class="flex-1 text-xs"
                                    >
                                        <Printer class="mr-1.5 size-3.5" /> Imprimir
                                    </Button>

                                    <!-- Botón de Renovar QR -->
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onclick={() => renewCredential(selectedStudent!)}
                                        disabled={isRenewing || !selectedStudent.is_active}
                                        class="flex-1 text-xs text-primary border-primary/30 hover:bg-primary/10"
                                        title="Genera un nuevo QR e invalida inmediatamente el anterior conservando historial"
                                    >
                                        <RefreshCw class="mr-1.5 size-3.5 {isRenewing ? 'animate-spin' : ''}" />
                                        Renovar QR
                                    </Button>
                                </div>

                                <!-- Botón de Bloqueo / Desbloqueo -->
                                {#if selectedStudent.credential.is_active}
                                    <Button
                                        variant="destructive"
                                        size="sm"
                                        onclick={() => blockCredential(selectedStudent!)}
                                        class="w-full text-xs shadow-xs"
                                    >
                                        <Lock class="mr-1.5 size-3.5" /> Bloquear Credencial
                                    </Button>
                                {:else if selectedStudent.credential.is_blocked}
                                    <Button
                                        size="sm"
                                        onclick={() => unblockCredential(selectedStudent!)}
                                        class="w-full bg-emerald-600 hover:bg-emerald-700 text-white text-xs shadow-xs"
                                    >
                                        <Unlock class="mr-1.5 size-3.5" /> Desbloquear Credencial
                                    </Button>
                                {/if}
                            {:else if selectedStudent.is_active}
                                <Button
                                    size="sm"
                                    onclick={() => generateQrForStudent(selectedStudent!)}
                                    disabled={isGenerating}
                                    class="w-full bg-primary text-xs shadow-xs"
                                >
                                    {#if isGenerating}
                                        <RefreshCw class="mr-1.5 size-3.5 animate-spin" />
                                        Generando...
                                    {:else}
                                        <QrCode class="mr-1.5 size-3.5" />
                                        Generar Código QR Único
                                    {/if}
                                </Button>
                            {:else}
                                <div class="rounded-md border border-amber-500/20 bg-amber-500/10 p-2 text-center text-xs text-amber-600">
                                    <UserX class="mx-auto size-4 mb-1" />
                                    Estudiante inactivo. No se permite emitir o renovar credenciales.
                                </div>
                            {/if}
                        </div>
                    {:else}
                        <div class="flex flex-col items-center justify-center p-8 text-center text-muted-foreground">
                            <IdCard class="size-10 text-muted-foreground/40 mb-2" />
                            <p class="text-xs">Selecciona un estudiante de la tabla para ver su carnet</p>
                        </div>
                    {/if}
                </CardContent>
            </Card>

            <!-- Historial de Renovaciones del Estudiante Seleccionado -->
            {#if selectedStudent?.history && selectedStudent.history.length > 0}
                <Card class="border-border/60 shadow-xs print:hidden">
                    <CardHeader class="pb-2">
                        <CardTitle class="text-xs font-semibold text-foreground flex items-center gap-1.5">
                            <History class="size-3.5 text-primary" />
                            <span>Historial de Credenciales ({selectedStudent.history.length})</span>
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="p-3 text-xs">
                        <div class="space-y-2 max-h-48 overflow-y-auto pr-1">
                            {#each selectedStudent.history as h (h.id)}
                                <div class="flex items-center justify-between rounded-md border border-border/50 p-2 text-[11px] bg-muted/20">
                                    <div class="space-y-0.5 max-w-[170px] truncate">
                                        <div class="font-mono text-foreground truncate" title={h.qr_code}>
                                            {h.qr_code}
                                        </div>
                                        <div class="text-[10px] text-muted-foreground">
                                            Emitido: {h.issued_at}
                                            {#if h.invalidated_at}
                                                • Invalidado: {h.invalidated_at}
                                            {/if}
                                        </div>
                                    </div>
                                    <Badge
                                        variant="outline"
                                        class={h.status === 'active'
                                            ? 'border-emerald-500/40 text-emerald-600 text-[10px]'
                                            : h.status === 'blocked'
                                              ? 'border-rose-500/40 text-rose-600 text-[10px]'
                                              : 'border-slate-500/40 text-slate-500 text-[10px]'}
                                    >
                                        {#if h.status === 'active'}
                                            Activo
                                        {:else if h.status === 'blocked'}
                                            Bloqueado
                                        {:else}
                                            Invalidado
                                        {/if}
                                    </Badge>
                                </div>
                            {/each}
                        </div>
                    </CardContent>
                </Card>
            {/if}
        </div>

        <!-- Tabla de Estado de Credenciales (Oculta en Impresión) -->
        <div class="lg:col-span-8 print:hidden">
            <Card class="border-border/60 shadow-xs">
                <CardHeader class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between pb-3">
                    <div>
                        <CardTitle class="text-sm font-semibold">Listado de Credenciales por Alumno</CardTitle>
                        <p class="text-xs text-muted-foreground">
                            Control de emisión, renovación e invalidación inmediata con historial en PostgreSQL.
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <!-- Filtro de Estado -->
                        <select
                            bind:value={statusFilter}
                            onchange={applyFilters}
                            class="h-8 rounded-md border border-input bg-background px-2.5 py-1 text-xs shadow-xs focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                        >
                            <option value="all">Todos los alumnos</option>
                            <option value="active_credential">Con QR Activo</option>
                            <option value="pending">Sin QR (Pendientes)</option>
                            <option value="blocked">Bloqueados</option>
                        </select>

                        <!-- Buscador -->
                        <div class="relative w-44 sm:w-52">
                            <Search class="absolute left-2.5 top-2.5 size-3.5 text-muted-foreground" />
                            <Input
                                bind:value={searchTerm}
                                oninput={handleSearch}
                                placeholder="Buscar por DNI, código o nombre..."
                                class="pl-8 text-xs h-8"
                            />
                        </div>
                    </div>
                </CardHeader>
                <CardContent class="p-0">
                    {#if students.length === 0}
                        <div class="flex flex-col items-center justify-center p-12 text-center text-muted-foreground">
                            <Search class="size-8 text-muted-foreground/30 mb-2" />
                            <p class="text-sm font-medium text-foreground">No se encontraron estudiantes</p>
                            <p class="text-xs text-muted-foreground mt-1 max-w-sm">
                                No hay resultados con los filtros actuales o no existen estudiantes registrados en PostgreSQL.
                            </p>
                            {#if searchTerm || statusFilter !== 'all'}
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onclick={() => {
                                        searchTerm = '';
                                        statusFilter = 'all';
                                        applyFilters();
                                    }}
                                    class="mt-3 text-xs"
                                >
                                    Limpiar Filtros
                                </Button>
                            {/if}
                        </div>
                    {:else}
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-xs min-w-[720px]">
                                <thead class="border-y border-border/70 bg-muted/40 font-semibold text-muted-foreground uppercase">
                                    <tr>
                                        <th class="px-4 py-3">Estudiante</th>
                                        <th class="px-4 py-3">Aula</th>
                                        <th class="px-4 py-3 text-center">Estado Carnet</th>
                                        <th class="px-4 py-3">Código QR Vigente</th>
                                        <th class="px-4 py-3">Última Emisión</th>
                                        <th class="px-4 py-3 text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border/60">
                                    {#each students as student (student.id)}
                                        <tr
                                            class="hover:bg-muted/30 cursor-pointer transition-colors {selectedStudent?.id === student.id ? 'bg-muted/40 font-medium' : ''}"
                                            onclick={() => (selectedStudent = student)}
                                        >
                                            <td class="px-4 py-3">
                                                <div class="font-medium text-foreground flex items-center gap-1.5">
                                                    <span>{student.last_name}, {student.first_name}</span>
                                                    {#if !student.is_active}
                                                        <Badge variant="outline" class="text-[10px] text-muted-foreground border-muted-foreground/30">
                                                            Inactivo
                                                        </Badge>
                                                    {/if}
                                                </div>
                                                <div class="text-[10px] text-muted-foreground font-mono">
                                                    DNI: {student.document_number || '—'} • Cód: {student.code}
                                                </div>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-muted-foreground">
                                                {student.classroom}
                                            </td>
                                            <td class="px-4 py-3 text-center whitespace-nowrap">
                                                {#if student.credential && !student.credential.is_invalidated}
                                                    {#if student.credential.is_active}
                                                        <Badge variant="outline" class="border-emerald-500/40 text-emerald-600 bg-emerald-50/40 dark:bg-emerald-950/20 text-[10px]">
                                                            Activo
                                                        </Badge>
                                                    {:else if student.credential.is_blocked}
                                                        <Badge variant="outline" class="border-rose-500/40 text-rose-600 bg-rose-50/40 dark:bg-rose-950/20 text-[10px]">
                                                            Bloqueado
                                                        </Badge>
                                                    {/if}
                                                {:else if student.is_active}
                                                    <Badge variant="outline" class="border-amber-500/40 text-amber-600 bg-amber-50/40 dark:bg-amber-950/20 text-[10px]">
                                                        Pendiente
                                                    </Badge>
                                                {:else}
                                                    <Badge variant="outline" class="text-muted-foreground text-[10px]">
                                                        Sin Carnet
                                                    </Badge>
                                                {/if}
                                            </td>
                                            <td class="px-4 py-3 font-mono text-[11px] whitespace-nowrap">
                                                {#if student.credential && !student.credential.is_invalidated}
                                                    <div class="flex items-center gap-1">
                                                        <span class="truncate max-w-[120px] text-muted-foreground" title={student.credential.qr_code}>
                                                            {student.credential.qr_code}
                                                        </span>
                                                        <button
                                                            type="button"
                                                            onclick={(e) => {
                                                                e.stopPropagation();
                                                                copyToken(student.credential!.qr_code);
                                                            }}
                                                            class="rounded p-0.5 text-muted-foreground hover:text-foreground"
                                                            title="Copiar código único"
                                                        >
                                                            <Copy class="size-3" />
                                                        </button>
                                                    </div>
                                                {:else}
                                                    <span class="text-muted-foreground/60">—</span>
                                                {/if}
                                            </td>
                                            <td class="px-4 py-3 text-muted-foreground whitespace-nowrap">
                                                {student.credential?.issued_at || '—'}
                                            </td>
                                            <td class="px-4 py-3 text-right whitespace-nowrap space-x-1" onclick={(e) => e.stopPropagation()}>
                                                {#if student.credential && !student.credential.is_invalidated}
                                                    <!-- Botón Renovar -->
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onclick={() => renewCredential(student)}
                                                        disabled={isRenewing || !student.is_active}
                                                        class="h-7 text-xs text-primary hover:bg-primary/10"
                                                        title="Renovar QR invalidando el anterior"
                                                    >
                                                        <RefreshCw class="mr-1 size-3 {isRenewing ? 'animate-spin' : ''}" />
                                                        Renovar
                                                    </Button>

                                                    <!-- Botón Bloquear / Desbloquear -->
                                                    {#if student.credential.is_active}
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onclick={() => blockCredential(student)}
                                                            class="h-7 text-xs text-rose-600 hover:text-rose-700 hover:bg-rose-50"
                                                            title="Bloquear credencial"
                                                        >
                                                            <Lock class="mr-1 size-3" />
                                                            Bloquear
                                                        </Button>
                                                    {:else if student.credential.is_blocked}
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onclick={() => unblockCredential(student)}
                                                            class="h-7 text-xs text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50"
                                                            title="Desbloquear credencial"
                                                        >
                                                            <Unlock class="mr-1 size-3" />
                                                            Desbloquear
                                                        </Button>
                                                    {/if}
                                                {:else if student.is_active}
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onclick={() => generateQrForStudent(student)}
                                                        disabled={isGenerating}
                                                        class="h-7 text-xs text-primary border-primary/30 hover:bg-primary/10"
                                                    >
                                                        Generar QR
                                                    </Button>
                                                {/if}
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
    </div>
</div>

<style>
    @media print {
        :global(body) {
            background: white !important;
            color: black !important;
        }
        :global(nav), :global(aside), :global(header) {
            display: none !important;
        }
    }
</style>
