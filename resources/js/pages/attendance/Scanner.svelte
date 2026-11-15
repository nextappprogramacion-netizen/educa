<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            {
                title: 'Asistencia',
                href: '/attendance/overview',
            },
            {
                title: 'Registro de Acceso QR',
                href: '/attendance/scanner',
            },
        ],
    };
</script>

<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import AlertCircle from '@lucide/svelte/icons/alert-circle';
    import ArrowDownRight from '@lucide/svelte/icons/arrow-down-right';
    import ArrowUpRight from '@lucide/svelte/icons/arrow-up-right';
    import Camera from '@lucide/svelte/icons/camera';
    import CheckCircle2 from '@lucide/svelte/icons/check-circle-2';
    import Clock from '@lucide/svelte/icons/clock';
    import Copy from '@lucide/svelte/icons/copy';
    import Keyboard from '@lucide/svelte/icons/keyboard';
    import QrCode from '@lucide/svelte/icons/qr-code';
    import RefreshCw from '@lucide/svelte/icons/refresh-cw';
    import ShieldBan from '@lucide/svelte/icons/shield-ban';
    import UserX from '@lucide/svelte/icons/user-x';
    import Volume2 from '@lucide/svelte/icons/volume-2';
    import VolumeX from '@lucide/svelte/icons/volume-x';
    import { toast } from 'svelte-sonner';
    import AppHead from '@/components/AppHead.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
    import { Input } from '@/components/ui/input';

    interface Terminal {
        id: number;
        name: string;
        code: string;
        location: string | null;
        mode: string; // 'entry' | 'exit'
        mode_label?: string;
    }

    interface RecentScan {
        id: number;
        student_name: string;
        student_code: string | null;
        classroom: string;
        entry_time: string | null;
        exit_time?: string | null;
        status: 'present' | 'late';
        status_label: string;
        exit_type?: 'regular' | 'early' | null;
        is_early_exit?: boolean;
        early_exit_reason?: string | null;
        latest_action?: 'entry' | 'exit';
    }

    interface ScanStudentInfo {
        id: number;
        first_name: string;
        last_name: string;
        full_name: string;
        code: string;
        document_number: string | null;
        avatar_url: string | null;
        classroom: string;
        shift: string;
    }

    interface ScanRecordInfo {
        id: number;
        action?: 'entry' | 'exit';
        status?: 'present' | 'late';
        status_label?: string;
        entry_time?: string;
        entry_time_formatted?: string;
        official_entry_time?: string;
        is_late?: boolean;
        exit_time?: string;
        exit_time_formatted?: string;
        official_exit_time?: string;
        exit_type?: 'regular' | 'early';
        exit_type_label?: string;
        is_early_exit?: boolean;
        early_exit_reason?: string | null;
        terminal_name: string;
        terminal_code: string;
        date: string;
    }

    interface FlashScanData {
        action: 'entry' | 'exit';
        student: ScanStudentInfo;
        record: ScanRecordInfo;
    }

    interface ScanErrorDetail {
        type: string;
        title: string;
        message: string;
    }

    interface EarlyExitPromptData {
        requires_authorization: boolean;
        student: ScanStudentInfo;
        qr_code: string;
        terminal_id: number;
        server_time: string;
        official_exit_time: string;
    }

    let {
        terminals = [],
        entry_terminals = [],
        recent_scans = [],
        flash_scan = null,
        scan_error_detail = null,
        early_exit_prompt = null,
    }: {
        terminals?: Terminal[];
        entry_terminals?: Terminal[];
        recent_scans?: RecentScan[];
        flash_scan?: FlashScanData | null;
        scan_error_detail?: ScanErrorDetail | null;
        early_exit_prompt?: EarlyExitPromptData | null;
    } = $props();

    let inputMethod = $state<'usb' | 'camera'>('usb');
    let soundEnabled = $state(true);
    let qrInputValue = $state('');
    let selectedTerminalId = $state<number>(0);
    let isProcessing = $state(false);
    let scanResult = $state<FlashScanData | null>(null);
    let scanError = $state<string | null>(null);
    let scanErrorDetail = $state<ScanErrorDetail | null>(null);

    // Estado del modal de Autorización de Salida Anticipada
    let isEarlyExitModalOpen = $state(false);
    let pendingEarlyExit = $state<EarlyExitPromptData | null>(null);
    let earlyExitReason = $state('');
    let earlyExitError = $state<string | null>(null);

    let availableTerminals = $derived(terminals.length > 0 ? terminals : entry_terminals);
    let activeTerminal = $derived(availableTerminals.find((t) => t.id === selectedTerminalId));
    let isExitMode = $derived(activeTerminal?.mode === 'exit');

    let errorCategory = $derived.by(() => {
        if (scanErrorDetail?.type) {
            return scanErrorDetail.type;
        }
        if (!scanError) return null;
        const lower = scanError.toLowerCase();
        if (lower.includes('bloqueada')) return 'blocked_credential';
        if (lower.includes('invalidada') || lower.includes('inhabilitada')) return 'invalidated_credential';
        if (lower.includes('inactivo') || lower.includes('deshabilitad')) return 'inactive_student';
        if (lower.includes('duplicad')) return 'duplicate_scan';
        return 'invalid_code';
    });

    $effect(() => {
        if (availableTerminals.length > 0 && (!selectedTerminalId || !availableTerminals.some((t) => t.id === selectedTerminalId))) {
            selectedTerminalId = availableTerminals[0].id;
        }
    });

    $effect(() => {
        if (flash_scan) {
            scanResult = flash_scan;
            scanError = null;
            scanErrorDetail = null;
        }
    });

    $effect(() => {
        if (scan_error_detail) {
            scanErrorDetail = scan_error_detail;
            scanError = scan_error_detail.message;
            scanResult = null;
        }
    });

    $effect(() => {
        if (early_exit_prompt?.requires_authorization) {
            pendingEarlyExit = early_exit_prompt;
            earlyExitReason = '';
            earlyExitError = null;
            isEarlyExitModalOpen = true;
        }
    });

    function playBeep() {
        try {
            const ctx = new (window.AudioContext || (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext)();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, ctx.currentTime);
            gain.gain.setValueAtTime(0.2, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.15);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.15);
        } catch {
            // AudioContext no disponible
        }
    }

    function playErrorBeep() {
        try {
            const ctx = new (window.AudioContext || (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext)();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(220, ctx.currentTime);
            gain.gain.setValueAtTime(0.3, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.3);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.3);
        } catch {
            // AudioContext no disponible
        }
    }

    function processScan(overrideCode?: string) {
        const code = (overrideCode || qrInputValue).trim();
        if (!code) {
            scanError = 'Código QR inválido: El código proporcionado está vacío o no tiene el formato esperado.';
            scanErrorDetail = {
                type: 'invalid_code',
                title: 'Código QR Vacío o Inválido',
                message: scanError,
            };
            scanResult = null;
            toast.error(scanError);
            if (soundEnabled) {
                playErrorBeep();
            }
            return;
        }

        if (!selectedTerminalId) {
            toast.error('Debe seleccionar una terminal autorizada.');
            return;
        }

        isProcessing = true;
        scanError = null;
        scanErrorDetail = null;

        router.post(
            '/attendance/scanner/scan',
            {
                qr_code: code,
                terminal_id: selectedTerminalId,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    isProcessing = false;
                    qrInputValue = '';
                    if (soundEnabled) {
                        playBeep();
                    }
                },
                onError: (errs) => {
                    isProcessing = false;
                    const msg = (errs.qr_code || errs.early_exit_required || Object.values(errs)[0]) as string;
                    scanError = msg || 'Error al procesar el código QR.';
                    scanResult = null; // Limpiar resultado previo para priorizar mensaje de rechazo
                    if (!errs.early_exit_required) {
                        toast.error(scanError);
                    }
                    if (soundEnabled) {
                        playErrorBeep();
                    }
                },
            },
        );
    }

    function confirmEarlyExit() {
        if (!pendingEarlyExit) return;
        const reason = earlyExitReason.trim();
        if (!reason) {
            earlyExitError = 'Debe indicar el motivo o justificación de la salida anticipada.';
            return;
        }

        isProcessing = true;
        earlyExitError = null;

        router.post(
            '/attendance/scanner/scan',
            {
                qr_code: pendingEarlyExit.qr_code,
                terminal_id: pendingEarlyExit.terminal_id,
                authorized: true,
                authorization_reason: reason,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    isProcessing = false;
                    isEarlyExitModalOpen = false;
                    pendingEarlyExit = null;
                    earlyExitReason = '';
                    qrInputValue = '';
                    if (soundEnabled) {
                        playBeep();
                    }
                    toast.success('Salida anticipada autorizada y registrada en PostgreSQL.');
                },
                onError: (errs) => {
                    isProcessing = false;
                    const msg = (errs.qr_code || Object.values(errs)[0]) as string;
                    earlyExitError = msg || 'Error al autorizar salida anticipada.';
                    toast.error(earlyExitError);
                    if (soundEnabled) {
                        playErrorBeep();
                    }
                },
            },
        );
    }

    function cancelEarlyExit() {
        isEarlyExitModalOpen = false;
        pendingEarlyExit = null;
        earlyExitReason = '';
        earlyExitError = null;
    }

    function handleKeydown(event: KeyboardEvent) {
        if (event.key === 'Enter') {
            event.preventDefault();
            processScan();
        }
    }
</script>

<AppHead title="Estación de Escaneo QR - Registro de Acceso" />

<div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
    <!-- Encabezado de la Estación de Escaneo -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight text-foreground">
                    Estación de Registro de Acceso QR
                </h1>
                {#if isExitMode}
                    <Badge variant="outline" class="border-blue-500/40 bg-blue-500/10 text-blue-600 font-semibold">
                        <ArrowUpRight class="mr-1 size-3.5" />
                        Terminal de Salida
                    </Badge>
                {:else}
                    <Badge variant="outline" class="border-emerald-500/40 bg-emerald-500/10 text-emerald-600 font-semibold">
                        <ArrowDownRight class="mr-1 size-3.5" />
                        Terminal de Entrada
                    </Badge>
                {/if}
            </div>
            <p class="text-sm text-muted-foreground">
                {#if isExitMode}
                    Escanee el carnet QR en una terminal autorizada de salida. Valida credenciales activas, evita duplicados y distingue salidas regulares de anticipadas.
                {:else}
                    Escanee el carnet QR en una terminal autorizada de entrada. Rechaza códigos inválidos, credenciales bloqueadas, alumnos inactivos y lecturas repetidas.
                {/if}
            </p>
        </div>

        <!-- Controles rápidos: Selector de Terminal y Sonido -->
        <div class="flex flex-wrap items-center gap-2">
            <!-- Selector de Terminal Autorizada (Ingreso o Salida) -->
            <div class="flex items-center gap-1.5 bg-muted/40 p-1 rounded-lg border border-border">
                <label for="active-terminal" class="text-[11px] font-semibold text-muted-foreground px-1">
                    Terminal:
                </label>
                <select
                    id="active-terminal"
                    bind:value={selectedTerminalId}
                    class="h-8 rounded-md border border-input bg-background px-2.5 py-0.5 text-xs shadow-xs focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring font-medium"
                >
                    {#each availableTerminals as t (t.id)}
                        <option value={t.id}>
                            {t.name} ({t.code}) - {t.mode === 'exit' ? 'Salida' : 'Ingreso'}
                        </option>
                    {/each}
                </select>
            </div>

            <!-- Botón de Audio -->
            <Button
                variant={soundEnabled ? 'outline' : 'ghost'}
                size="sm"
                onclick={() => (soundEnabled = !soundEnabled)}
                class="text-xs h-9"
            >
                {#if soundEnabled}
                    <Volume2 class="mr-1.5 size-4 text-primary" />
                    <span>Sonido Activado</span>
                {:else}
                    <VolumeX class="mr-1.5 size-4 text-muted-foreground" />
                    <span>Silencio</span>
                {/if}
            </Button>
        </div>
    </div>

    <!-- Panel de Operación Principal -->
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <!-- Columna Izquierda: Capturador (Lector USB o Cámara) y Lecturas Recientes -->
        <div class="lg:col-span-7 space-y-4">
            <Card class="border-border/60 shadow-xs">
                <CardHeader class="flex flex-row items-center justify-between pb-3">
                    <CardTitle class="text-sm font-semibold">Método de Captura de Carnet QR</CardTitle>
                    <div class="flex items-center gap-1">
                        <Button
                            variant={inputMethod === 'usb' ? 'secondary' : 'ghost'}
                            size="sm"
                            onclick={() => (inputMethod = 'usb')}
                            class="text-xs h-7"
                        >
                            <Keyboard class="mr-1 size-3.5" /> Lector USB / Óptico
                        </Button>
                        <Button
                            variant={inputMethod === 'camera' ? 'secondary' : 'ghost'}
                            size="sm"
                            onclick={() => (inputMethod = 'camera')}
                            class="text-xs h-7"
                        >
                            <Camera class="mr-1 size-3.5" /> Cámara Web
                        </Button>
                    </div>
                </CardHeader>
                <CardContent class="space-y-4">
                    {#if inputMethod === 'usb'}
                        <div class="rounded-xl border-2 border-dashed border-primary/30 bg-primary/5 p-8 text-center space-y-3">
                            <QrCode class="mx-auto size-14 text-primary {isProcessing ? 'animate-spin' : 'animate-pulse'}" />
                            <h3 class="text-base font-bold text-foreground">
                                {isProcessing
                                    ? 'Validando con el servidor PostgreSQL...'
                                    : isExitMode
                                      ? 'Lector de Salida QR Listo'
                                      : 'Lector de Ingreso QR Listo'}
                            </h3>
                            <p class="text-xs text-muted-foreground max-w-sm mx-auto">
                                Enfoque el carnet del estudiante con la pistola lectora o ingrese el código único.
                            </p>
                            <div class="max-w-xs mx-auto pt-2 flex items-center gap-2">
                                <Input
                                    type="text"
                                    placeholder="Esperando lectura de carnet..."
                                    bind:value={qrInputValue}
                                    onkeydown={handleKeydown}
                                    disabled={isProcessing}
                                    class="text-center font-mono text-sm tracking-wider bg-background h-10"
                                    autofocus
                                />
                                <Button
                                    size="sm"
                                    onclick={() => processScan()}
                                    disabled={isProcessing || !qrInputValue.trim()}
                                    class="h-10 text-xs {isExitMode ? 'bg-blue-600 hover:bg-blue-700 text-white' : 'bg-primary'} shadow-xs"
                                >
                                    {#if isProcessing}
                                        <RefreshCw class="size-3.5 animate-spin" />
                                    {:else}
                                        {isExitMode ? 'Registrar Salida' : 'Registrar Ingreso'}
                                    {/if}
                                </Button>
                            </div>
                        </div>
                    {:else}
                        <div class="rounded-xl border border-border bg-black/90 p-12 text-center text-white space-y-3 aspect-video flex flex-col items-center justify-center">
                            <Camera class="size-12 text-muted-foreground animate-pulse" />
                            <p class="text-sm font-medium">Visor de Cámara en Espera</p>
                            <p class="text-xs text-muted-foreground">Coloque el código QR del carnet frente a la lente</p>
                            <Button size="sm" variant="secondary" class="mt-2 text-xs">Iniciar Cámara</Button>
                        </div>
                    {/if}

                    <div class="flex items-center justify-between text-xs text-muted-foreground pt-1 border-t border-border/40">
                        <span>
                            Terminal Autorizada:
                            <strong class="text-foreground">
                                {activeTerminal?.name || 'Terminal no seleccionada'}
                            </strong>
                        </span>
                        <span>
                            Modo Exclusivo:
                            {#if isExitMode}
                                <strong class="text-blue-600 font-semibold">Salida Oficial</strong>
                            {:else}
                                <strong class="text-emerald-600 font-semibold">Ingreso Oficial</strong>
                            {/if}
                        </span>
                    </div>
                </CardContent>
            </Card>

            <!-- Lista de Lecturas Recientes de la Sesión en PostgreSQL -->
            <Card class="border-border/60 shadow-xs">
                <CardHeader class="pb-2">
                    <CardTitle class="text-xs font-semibold uppercase tracking-wider text-muted-foreground flex items-center justify-between">
                        <span>Lecturas Registradas Hoy ({recent_scans.length})</span>
                        <span class="text-[10px] lowercase text-muted-foreground font-mono">PostgreSQL</span>
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    {#if recent_scans.length === 0}
                        <div class="py-6 text-center text-xs text-muted-foreground">
                            No se han registrado movimientos de asistencia el día de hoy todavía.
                        </div>
                    {:else}
                        <div class="divide-y divide-border/60 text-xs max-h-64 overflow-y-auto pr-1">
                            {#each recent_scans as scan (scan.id)}
                                <div class="flex items-center justify-between py-2">
                                    <div>
                                        <div class="font-medium text-foreground">{scan.student_name}</div>
                                        <div class="text-[10px] text-muted-foreground font-mono">{scan.student_code || '—'}</div>
                                    </div>
                                    <span class="font-mono text-muted-foreground">{scan.classroom}</span>
                                    {#if scan.latest_action === 'exit' || scan.exit_time}
                                        {#if scan.is_early_exit || scan.exit_type === 'early'}
                                            <Badge
                                                variant="outline"
                                                class="border-amber-500/40 text-amber-600 bg-amber-50/50 dark:bg-amber-950/20 text-[10px] font-semibold"
                                            >
                                                <ArrowUpRight class="mr-1 size-3" />
                                                Salida Anticipada {scan.exit_time}
                                            </Badge>
                                        {:else}
                                            <Badge
                                                variant="outline"
                                                class="border-blue-500/40 text-blue-600 bg-blue-50/50 dark:bg-blue-950/20 text-[10px] font-semibold"
                                            >
                                                <ArrowUpRight class="mr-1 size-3" />
                                                Salida Regular {scan.exit_time}
                                            </Badge>
                                        {/if}
                                    {:else}
                                        <Badge
                                            variant="outline"
                                            class={scan.status === 'present'
                                                ? 'border-emerald-500/40 text-emerald-600 bg-emerald-50/50 dark:bg-emerald-950/20 text-[10px] font-semibold'
                                                : 'border-amber-500/40 text-amber-600 bg-amber-50/50 dark:bg-amber-950/20 text-[10px] font-semibold'}
                                        >
                                            <ArrowDownRight class="mr-1 size-3" />
                                            {scan.status_label} {scan.entry_time}
                                        </Badge>
                                    {/if}
                                </div>
                            {/each}
                        </div>
                    {/if}
                </CardContent>
            </Card>
        </div>

        <!-- Columna Derecha: Tarjeta de Resultado del Estudiante Escaneado -->
        <div class="lg:col-span-5">
            <Card class="border-border/60 shadow-md h-full flex flex-col">
                <CardHeader class="bg-muted/30 border-b border-border/40 pb-3">
                    <CardTitle class="text-xs font-semibold uppercase tracking-wider text-muted-foreground flex items-center justify-between">
                        <span>Ficha de Verificación Oficial</span>
                        {#if scanResult}
                            {#if scanResult.record.action === 'exit'}
                                <Badge
                                    variant="outline"
                                    class={scanResult.record.is_early_exit
                                        ? 'border-amber-500/40 text-amber-600 bg-amber-500/10'
                                        : 'border-blue-500/40 text-blue-600 bg-blue-500/10'}
                                >
                                    {scanResult.record.exit_type_label || (scanResult.record.is_early_exit ? 'Salida Anticipada' : 'Salida Regular')}
                                </Badge>
                            {:else}
                                <Badge
                                    variant="outline"
                                    class={scanResult.record.status === 'present'
                                        ? 'border-emerald-500/40 text-emerald-600 bg-emerald-500/10'
                                        : 'border-amber-500/40 text-amber-600 bg-amber-500/10'}
                                >
                                    {scanResult.record.status_label}
                                </Badge>
                            {/if}
                        {:else if scanError}
                            <Badge
                                variant="outline"
                                class={errorCategory === 'duplicate_scan'
                                    ? 'border-amber-500/40 text-amber-600 bg-amber-500/10 font-bold'
                                    : 'border-rose-500/40 text-rose-600 bg-rose-500/10 font-bold'}
                            >
                                {errorCategory === 'duplicate_scan' ? 'Duplicado' : 'Rechazado'}
                            </Badge>
                        {/if}
                    </CardTitle>
                </CardHeader>
                <CardContent class="flex-1 flex flex-col justify-center items-center p-6 text-center space-y-4">
                    {#if scanResult}
                        <!-- Avatar / Foto del Alumno -->
                        <div class="size-24 rounded-full bg-primary/10 border-2 border-primary/20 flex items-center justify-center text-2xl font-bold text-primary shadow-inner overflow-hidden">
                            {#if scanResult.student.avatar_url}
                                <img src={scanResult.student.avatar_url} alt={scanResult.student.full_name} class="size-full object-cover" />
                            {:else}
                                {scanResult.student.first_name[0]}{scanResult.student.last_name[0]}
                            {/if}
                        </div>

                        <!-- Datos del Estudiante -->
                        <div class="space-y-1">
                            <h2 class="text-xl font-bold text-foreground">
                                {scanResult.student.last_name}, {scanResult.student.first_name}
                            </h2>
                            <div class="text-xs font-mono text-muted-foreground">
                                DNI: {scanResult.student.document_number || '—'} • Cód: {scanResult.student.code}
                            </div>
                            <div class="inline-flex items-center gap-1.5 rounded-full bg-muted px-3 py-1 text-xs font-medium text-foreground mt-1">
                                <span>{scanResult.student.classroom}</span> • <span>Turno {scanResult.student.shift}</span>
                            </div>
                        </div>

                        <!-- Banner de Estado: Ingreso vs Salida Regular vs Salida Anticipada -->
                        {#if scanResult.record.action === 'exit'}
                            {#if scanResult.record.is_early_exit}
                                <div class="w-full rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 space-y-1 text-center">
                                    <div class="flex items-center justify-center gap-1.5 text-amber-700 dark:text-amber-400 font-bold text-base">
                                        <Clock class="size-5" />
                                        <span>SALIDA ANTICIPADA AUTORIZADA</span>
                                    </div>
                                    <p class="text-xs text-muted-foreground">
                                        Hora oficial del servidor: <strong>{scanResult.record.exit_time_formatted}</strong>
                                    </p>
                                    {#if scanResult.record.early_exit_reason}
                                        <p class="text-xs text-amber-800 dark:text-amber-300 font-medium italic pt-1">
                                            Motivo: "{scanResult.record.early_exit_reason}"
                                        </p>
                                    {/if}
                                    <p class="text-[11px] text-muted-foreground pt-1">
                                        Horario oficial de salida: {scanResult.record.official_exit_time}
                                    </p>
                                </div>
                            {:else}
                                <div class="w-full rounded-xl border border-blue-500/30 bg-blue-500/10 p-4 space-y-1 text-center">
                                    <div class="flex items-center justify-center gap-1.5 text-blue-700 dark:text-blue-400 font-bold text-base">
                                        <ArrowUpRight class="size-5" />
                                        <span>SALIDA REGULAR REGISTRADA</span>
                                    </div>
                                    <p class="text-xs text-muted-foreground">
                                        Hora oficial del servidor: <strong>{scanResult.record.exit_time_formatted}</strong>
                                    </p>
                                    <p class="text-[11px] text-blue-600 dark:text-blue-400 font-medium">
                                        Cumplió horario oficial ({scanResult.record.official_exit_time})
                                    </p>
                                </div>
                            {/if}
                        {:else if scanResult.record.status === 'present'}
                            <div class="w-full rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4 space-y-1 text-center">
                                <div class="flex items-center justify-center gap-1.5 text-emerald-700 dark:text-emerald-400 font-bold text-base">
                                    <CheckCircle2 class="size-5" />
                                    <span>INGRESO PUNTUAL REGISTRADO</span>
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    Hora oficial del servidor: <strong>{scanResult.record.entry_time_formatted}</strong>
                                </p>
                                <p class="text-[11px] text-emerald-600 font-medium">
                                    Límite oficial: {scanResult.record.official_entry_time} • Sin tolerancia
                                </p>
                            </div>
                        {:else}
                            <div class="w-full rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 space-y-1 text-center">
                                <div class="flex items-center justify-center gap-1.5 text-amber-700 dark:text-amber-400 font-bold text-base">
                                    <Clock class="size-5" />
                                    <span>INGRESO CON TARDANZA REGISTRADO</span>
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    Hora oficial del servidor: <strong>{scanResult.record.entry_time_formatted}</strong>
                                </p>
                                <p class="text-[11px] text-rose-600 font-medium">
                                    Límite oficial: {scanResult.record.official_entry_time} • Superó la hora establecida (Sin tolerancia)
                                </p>
                            </div>
                        {/if}

                        <div class="text-[11px] text-muted-foreground pt-1">
                            Terminal: <strong>{scanResult.record.terminal_name}</strong> ({scanResult.record.terminal_code}) • Guardado en PostgreSQL
                        </div>
                    {:else if scanError}
                        <!-- Tarjeta de Rechazo con Clasificación y Mensaje Claro -->
                        <div class="w-full rounded-2xl border p-6 text-center space-y-4 animate-in fade-in duration-200 {errorCategory === 'duplicate_scan' ? 'border-amber-500/30 bg-amber-500/10' : 'border-rose-500/30 bg-rose-500/10'}">
                            <div class="size-16 rounded-full mx-auto flex items-center justify-center border {errorCategory === 'duplicate_scan' ? 'bg-amber-500/15 border-amber-500/30 text-amber-600' : 'bg-rose-500/15 border-rose-500/30 text-rose-600'}">
                                {#if errorCategory === 'blocked_credential'}
                                    <ShieldBan class="size-8" />
                                {:else if errorCategory === 'inactive_student'}
                                    <UserX class="size-8" />
                                {:else if errorCategory === 'duplicate_scan'}
                                    <Copy class="size-8" />
                                {:else}
                                    <AlertCircle class="size-8" />
                                {/if}
                            </div>

                            <div class="space-y-1">
                                <Badge
                                    variant="outline"
                                    class={errorCategory === 'duplicate_scan'
                                        ? 'border-amber-500/40 text-amber-600 bg-amber-500/10 text-xs font-bold'
                                        : 'border-rose-500/40 text-rose-600 bg-rose-500/10 text-xs font-bold'}
                                >
                                    {#if errorCategory === 'blocked_credential'}
                                        Credencial Bloqueada
                                    {:else if errorCategory === 'invalidated_credential'}
                                        Credencial Invalidada
                                    {:else if errorCategory === 'inactive_student'}
                                        Estudiante Inactivo
                                    {:else if errorCategory === 'duplicate_scan'}
                                        Lectura Duplicada
                                    {:else}
                                        Código QR Inválido
                                    {/if}
                                </Badge>
                                <h3 class="text-base font-bold text-foreground pt-1">
                                    {scanErrorDetail?.title || (errorCategory === 'duplicate_scan' ? 'Lectura Duplicada Detectada' : 'Acceso No Autorizado')}
                                </h3>
                                <p class="text-xs text-muted-foreground max-w-sm mx-auto leading-relaxed">
                                    {scanError}
                                </p>
                            </div>

                            <div class="rounded-lg bg-background/80 p-2.5 border border-border/50 text-[11px] text-muted-foreground flex items-center justify-center gap-1.5 font-medium">
                                <span class="size-1.5 rounded-full {errorCategory === 'duplicate_scan' ? 'bg-amber-500' : 'bg-rose-500'}"></span>
                                <span>No se generó ningún movimiento incorrecto en PostgreSQL</span>
                            </div>
                        </div>
                    {:else}
                        <div class="flex flex-col items-center justify-center py-12 text-center text-muted-foreground space-y-3">
                            <QrCode class="size-16 text-muted-foreground/30 animate-pulse" />
                            <div class="space-y-1">
                                <p class="text-sm font-semibold text-foreground">Esperando Escaneo de Carnet</p>
                                <p class="text-xs text-muted-foreground max-w-xs">
                                    Acerque el código QR a la terminal autorizada para registrar el movimiento oficial con sincronización inmediata en PostgreSQL.
                                </p>
                            </div>
                        </div>
                    {/if}
                </CardContent>
            </Card>
        </div>
    </div>
</div>

<!-- Modal de Autorización Obligatoria para Salida Anticipada -->
{#if isEarlyExitModalOpen && pendingEarlyExit}
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-xs">
        <div class="w-full max-w-lg rounded-2xl border border-amber-500/30 bg-card p-6 shadow-2xl space-y-4 my-8 animate-in fade-in zoom-in-95 duration-150">
            <!-- Encabezado con Icono -->
            <div class="flex items-start gap-3.5">
                <div class="size-11 rounded-full bg-amber-500/10 text-amber-600 flex items-center justify-center shrink-0 border border-amber-500/20">
                    <Clock class="size-6" />
                </div>
                <div class="space-y-1 flex-1">
                    <div class="flex flex-wrap items-center justify-between gap-1.5">
                        <h3 class="text-base font-bold text-foreground">
                            Salida Anticipada Detectada
                        </h3>
                        <Badge variant="outline" class="border-amber-500/40 text-amber-600 bg-amber-500/10 text-[11px] font-semibold">
                            Requiere Autorización
                        </Badge>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        El estudiante está registrando su salida antes del horario oficial establecido. Se requiere registrar la justificación en PostgreSQL.
                    </p>
                </div>
            </div>

            <!-- Ficha del Estudiante -->
            <div class="rounded-xl border border-border/80 bg-muted/40 p-3.5 text-xs space-y-2">
                <div class="flex items-center gap-3">
                    <div class="size-10 rounded-full bg-primary/10 border border-primary/20 flex items-center justify-center text-sm font-bold text-primary overflow-hidden shrink-0">
                        {#if pendingEarlyExit.student.avatar_url}
                            <img src={pendingEarlyExit.student.avatar_url} alt={pendingEarlyExit.student.full_name} class="size-full object-cover" />
                        {:else}
                            {pendingEarlyExit.student.full_name.slice(0, 2).toUpperCase()}
                        {/if}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="font-bold text-foreground truncate">{pendingEarlyExit.student.full_name}</div>
                        <div class="text-[11px] text-muted-foreground font-mono">
                            DNI: {pendingEarlyExit.student.document_number || '—'} • Cód: {pendingEarlyExit.student.code}
                        </div>
                    </div>
                    <Badge variant="secondary" class="text-[10px] shrink-0">
                        {pendingEarlyExit.student.classroom}
                    </Badge>
                </div>

                <!-- Comparador de Horas Oficiales -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 pt-2 border-t border-border/60 text-[11px]">
                    <div class="rounded-lg bg-background/80 p-2 border border-border/50">
                        <span class="text-muted-foreground block text-[10px] uppercase font-semibold">Hora de Intento:</span>
                        <span class="font-mono font-bold text-amber-600">{pendingEarlyExit.server_time}</span>
                    </div>
                    <div class="rounded-lg bg-background/80 p-2 border border-border/50">
                        <span class="text-muted-foreground block text-[10px] uppercase font-semibold">Horario Oficial de Salida:</span>
                        <span class="font-mono font-bold text-foreground">{pendingEarlyExit.official_exit_time}</span>
                    </div>
                </div>
            </div>

            <!-- Formulario de Motivo / Justificación -->
            <div class="space-y-1.5">
                <label for="early-exit-reason" class="block text-xs font-semibold text-foreground">
                    Motivo o Justificación del Retiro Anticipado <span class="text-rose-500">*</span>
                </label>
                <textarea
                    id="early-exit-reason"
                    rows="3"
                    bind:value={earlyExitReason}
                    placeholder="Ej. Retiro con apoderado por cita médica, urgencia familiar justificada, permiso de dirección..."
                    class="w-full rounded-md border border-input bg-background p-2.5 text-xs shadow-xs focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring {earlyExitError ? 'border-rose-500 focus-visible:ring-rose-500' : ''}"
                    disabled={isProcessing}
                ></textarea>
                {#if earlyExitError}
                    <p class="text-[11px] text-rose-500 font-medium">{earlyExitError}</p>
                {/if}
            </div>

            <!-- Botones de Acción -->
            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border/60">
                <Button
                    type="button"
                    variant="ghost"
                    onclick={cancelEarlyExit}
                    disabled={isProcessing}
                    class="text-xs"
                >
                    Cancelar
                </Button>
                <Button
                    type="button"
                    onclick={confirmEarlyExit}
                    disabled={isProcessing || !earlyExitReason.trim()}
                    class="text-xs font-semibold bg-amber-600 hover:bg-amber-700 text-white shadow-xs"
                >
                    {#if isProcessing}
                        <RefreshCw class="mr-1.5 size-3.5 animate-spin" />
                        <span>Autorizando...</span>
                    {:else}
                        <CheckCircle2 class="mr-1.5 size-3.5" />
                        <span>Autorizar y Registrar Salida</span>
                    {/if}
                </Button>
            </div>
        </div>
    </div>
{/if}
