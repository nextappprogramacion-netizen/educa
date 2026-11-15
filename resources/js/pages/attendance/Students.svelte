<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            {
                title: 'Asistencia',
                href: '/attendance/overview',
            },
            {
                title: 'Estudiantes',
                href: '/attendance/students',
            },
        ],
    };
</script>

<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import Camera from '@lucide/svelte/icons/camera';
    import CheckCircle2 from '@lucide/svelte/icons/check-circle-2';
    import Edit2 from '@lucide/svelte/icons/edit-2';
    import Filter from '@lucide/svelte/icons/filter';
    import Plus from '@lucide/svelte/icons/plus';
    import RefreshCw from '@lucide/svelte/icons/refresh-cw';
    import Search from '@lucide/svelte/icons/search';
    import UserCheck from '@lucide/svelte/icons/user-check';
    import UserMinus from '@lucide/svelte/icons/user-minus';
    import Users from '@lucide/svelte/icons/users';
    import UserX from '@lucide/svelte/icons/user-x';
    import X from '@lucide/svelte/icons/x';
    import { toast } from 'svelte-sonner';
    import AppHead from '@/components/AppHead.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Card, CardContent } from '@/components/ui/card';
    import { Input } from '@/components/ui/input';

    interface SectionItem {
        id: number;
        name: string;
        grade_id: number;
        grade_name: string;
        level: string;
        shift: string;
        label: string;
    }

    interface GradeItem {
        id: number;
        name: string;
        level: string;
    }

    interface StudentItem {
        id: number;
        code: string;
        first_name: string;
        last_name: string;
        full_name: string;
        document_number: string;
        gender: string | null;
        birth_date: string | null;
        photo_url: string | null;
        is_active: boolean;
        section_id: number | null;
        section_name: string | null;
        grade_id: number | null;
        grade_name: string | null;
        level: string;
        shift: string;
        classroom: string;
    }

    interface Stats {
        total: number;
        active: number;
        inactive: number;
    }

    interface Filters {
        search: string;
        level: string;
        grade_id: number | string;
        section_id: number | string;
        shift?: string;
        status: string;
    }

    let {
        students = [],
        sections = [],
        grades = [],
        levels = [],
        filters,
        stats,
    }: {
        students: StudentItem[];
        sections: SectionItem[];
        grades?: GradeItem[];
        levels: string[];
        filters: Filters;
        stats: Stats;
    } = $props();

    // Filtros locales
    let searchVal = $state('');
    let levelVal = $state('all');
    let gradeVal = $state('all');
    let sectionVal = $state('all');
    let shiftVal = $state('all');
    let statusVal = $state('all');

    // Estado del modal de Formulario (Crear / Editar)
    let isModalOpen = $state(false);
    let isEditing = $state(false);
    let editingStudentId = $state<number | null>(null);
    let isSubmitting = $state(false);

    // Campos del Formulario
    let formFirstName = $state('');
    let formLastName = $state('');
    let formDni = $state('');
    let formCode = $state('');
    let formGender = $state<'M' | 'F'>('M');
    let formBirthDate = $state('');
    let formSectionId = $state<number | ''>('');
    let formPhotoUrl = $state('');
    let formErrors = $state<Record<string, string>>({});

    // Sincronizar filtros desde servidor
    $effect(() => {
        searchVal = filters?.search ?? '';
        levelVal = filters?.level ?? 'all';
        gradeVal = filters?.grade_id ? String(filters.grade_id) : 'all';
        sectionVal = filters?.section_id ? String(filters.section_id) : 'all';
        shiftVal = filters?.shift ?? 'all';
        statusVal = filters?.status ?? 'all';
    });

    // Grados filtrados según el nivel seleccionado para el filtro
    let filteredGrades = $derived(
        levelVal === 'all'
            ? (grades || [])
            : (grades || []).filter((g) => g.level.toLowerCase() === levelVal.toLowerCase()),
    );

    // Secciones filtradas según el nivel y grado seleccionados para el filtro
    let filteredSections = $derived(
        sections.filter((s) => {
            if (levelVal !== 'all' && s.level.toLowerCase() !== levelVal.toLowerCase()) {
                return false;
            }
            if (gradeVal !== 'all' && s.grade_id !== Number(gradeVal)) {
                return false;
            }
            return true;
        }),
    );

    // Secciones filtrables para el formulario según nivel seleccionado
    let selectedFormSection = $derived(
        sections.find((s) => s.id === Number(formSectionId)),
    );

    function applyFilters() {
        router.get(
            '/attendance/students',
            {
                search: searchVal.trim() || undefined,
                level: levelVal !== 'all' ? levelVal : undefined,
                grade_id: gradeVal !== 'all' ? gradeVal : undefined,
                section_id: sectionVal !== 'all' ? sectionVal : undefined,
                shift: shiftVal !== 'all' ? shiftVal : undefined,
                status: statusVal !== 'all' ? statusVal : undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
            },
        );
    }

    function handleLevelChange() {
        gradeVal = 'all';
        sectionVal = 'all';
        applyFilters();
    }

    function handleGradeChange() {
        sectionVal = 'all';
        applyFilters();
    }

    function resetFilters() {
        searchVal = '';
        levelVal = 'all';
        gradeVal = 'all';
        sectionVal = 'all';
        shiftVal = 'all';
        statusVal = 'all';
        applyFilters();
    }

    function openCreateModal() {
        isEditing = false;
        editingStudentId = null;
        formFirstName = '';
        formLastName = '';
        formDni = '';
        formCode = '';
        formGender = 'M';
        formBirthDate = '';
        formSectionId = sections.length > 0 ? sections[0].id : '';
        formPhotoUrl = '';
        formErrors = {};
        isModalOpen = true;
    }

    function openEditModal(student: StudentItem) {
        isEditing = true;
        editingStudentId = student.id;
        formFirstName = student.first_name;
        formLastName = student.last_name;
        formDni = student.document_number;
        formCode = student.code;
        formGender = (student.gender as 'M' | 'F') || 'M';
        formBirthDate = student.birth_date || '';
        formSectionId = student.section_id || (sections.length > 0 ? sections[0].id : '');
        formPhotoUrl = student.photo_url || '';
        formErrors = {};
        isModalOpen = true;
    }

    function validateClient(): boolean {
        const errors: Record<string, string> = {};

        if (!formFirstName.trim()) {
            errors.first_name = 'El nombre es obligatorio.';
        }
        if (!formLastName.trim()) {
            errors.last_name = 'Los apellidos son obligatorios.';
        }
        if (!formDni.trim()) {
            errors.document_number = 'El DNI es obligatorio.';
        } else if (!/^\d{8}$/.test(formDni.trim())) {
            errors.document_number = 'El DNI debe tener exactamente 8 dígitos.';
        }
        if (!formSectionId) {
            errors.section_id = 'Seleccione una sección y grado.';
        }

        formErrors = errors;
        return Object.keys(errors).length === 0;
    }

    function handleSubmit() {
        if (!validateClient()) return;

        isSubmitting = true;
        const payload = {
            first_name: formFirstName.trim(),
            last_name: formLastName.trim(),
            document_number: formDni.trim(),
            code: formCode.trim() || undefined,
            gender: formGender,
            birth_date: formBirthDate || undefined,
            section_id: Number(formSectionId),
            photo_url: formPhotoUrl.trim() || undefined,
        };

        if (isEditing && editingStudentId) {
            router.put(`/attendance/students/${editingStudentId}`, payload, {
                onSuccess: () => {
                    isSubmitting = false;
                    isModalOpen = false;
                    toast.success('Estudiante actualizado exitosamente.');
                },
                onError: (errs) => {
                    isSubmitting = false;
                    formErrors = errs as Record<string, string>;
                    const msg = Object.values(errs)[0] as string;
                    toast.error(msg || 'Error al actualizar estudiante.');
                },
            });
        } else {
            router.post('/attendance/students', payload, {
                onSuccess: () => {
                    isSubmitting = false;
                    isModalOpen = false;
                    toast.success('Estudiante registrado y matriculado con éxito.');
                },
                onError: (errs) => {
                    isSubmitting = false;
                    formErrors = errs as Record<string, string>;
                    const msg = Object.values(errs)[0] as string;
                    toast.error(msg || 'Error al registrar estudiante.');
                },
            });
        }
    }

    // Estado del modal de confirmación para Desactivar / Reactivar estudiante
    let isConfirmModalOpen = $state(false);
    let studentToToggle = $state<StudentItem | null>(null);
    let isTogglingStatus = $state(false);

    function openConfirmModal(student: StudentItem) {
        studentToToggle = student;
        isConfirmModalOpen = true;
    }

    function closeConfirmModal() {
        if (isTogglingStatus) return;
        isConfirmModalOpen = false;
        studentToToggle = null;
    }

    function executeToggleStatus() {
        if (!studentToToggle) return;
        isTogglingStatus = true;
        const target = studentToToggle;

        router.patch(`/attendance/students/${target.id}/toggle-status`, {}, {
            preserveScroll: true,
            onSuccess: () => {
                isTogglingStatus = false;
                isConfirmModalOpen = false;
                const wasActive = target.is_active;
                studentToToggle = null;
                toast.success(
                    wasActive
                        ? `Estudiante ${target.full_name} desactivado. Su historial se conserva intacto.`
                        : `Estudiante ${target.full_name} reactivado para el control de asistencia.`,
                );
            },
            onError: (errs) => {
                isTogglingStatus = false;
                const msg = Object.values(errs)[0] as string;
                toast.error(msg || 'No se pudo cambiar el estado del alumno.');
            },
        });
    }
</script>

<AppHead title="Gestión de Estudiantes - Asistencia Escolar" />

<div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
    <!-- Encabezado con Botón Principal "Nuevo estudiante" -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight text-foreground">
                    Directorio de Estudiantes
                </h1>
                <Badge variant="outline" class="border-primary/40 text-primary">
                    {stats?.total ?? students.length} Matriculados
                </Badge>
            </div>
            <p class="text-sm text-muted-foreground">
                Registro, edición de aulas, consulta y control de altas o bajas de alumnos de la institución.
            </p>
        </div>

        <div class="flex items-center gap-2">
            <Button onclick={openCreateModal} class="bg-primary text-white shadow-xs">
                <Plus class="mr-1.5 size-4" /> Nuevo estudiante
            </Button>
        </div>
    </div>

    <!-- Tarjetas de Resumen Rápido -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <Card class="border-border/60 shadow-xs">
            <CardContent class="p-4 flex items-center justify-between">
                <div>
                    <span class="text-xs font-semibold text-muted-foreground uppercase">Total General</span>
                    <div class="text-2xl font-extrabold text-foreground mt-0.5">{stats?.total ?? students.length}</div>
                </div>
                <div class="p-2.5 rounded-full bg-primary/10 text-primary">
                    <Users class="size-5" />
                </div>
            </CardContent>
        </Card>

        <Card class="border-emerald-500/20 bg-emerald-500/5 shadow-xs">
            <CardContent class="p-4 flex items-center justify-between">
                <div>
                    <span class="text-xs font-semibold text-emerald-700 dark:text-emerald-400 uppercase">Alumnos Activos</span>
                    <div class="text-2xl font-extrabold text-emerald-600 dark:text-emerald-400 mt-0.5">{stats?.active ?? 0}</div>
                </div>
                <div class="p-2.5 rounded-full bg-emerald-500/20 text-emerald-600 dark:text-emerald-400">
                    <UserCheck class="size-5" />
                </div>
            </CardContent>
        </Card>

        <Card class="border-rose-500/20 bg-rose-500/5 shadow-xs">
            <CardContent class="p-4 flex items-center justify-between">
                <div>
                    <span class="text-xs font-semibold text-rose-700 dark:text-rose-400 uppercase">Inactivos / Bajas</span>
                    <div class="text-2xl font-extrabold text-rose-600 dark:text-rose-400 mt-0.5">{stats?.inactive ?? 0}</div>
                </div>
                <div class="p-2.5 rounded-full bg-rose-500/20 text-rose-600 dark:text-rose-400">
                    <UserX class="size-5" />
                </div>
            </CardContent>
        </Card>
    </div>

    <!-- Barra de Búsqueda y Filtros -->
    <Card class="border-border/60 shadow-xs">
        <CardContent class="p-4 space-y-3">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6">
                <!-- Buscador por texto: nombre, apellido, DNI o código -->
                <div class="relative sm:col-span-2 lg:col-span-2">
                    <Search class="absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                    <Input
                        type="search"
                        placeholder="Buscar por nombre, apellido, DNI o código..."
                        bind:value={searchVal}
                        onkeydown={(e) => e.key === 'Enter' && applyFilters()}
                        class="pl-8 text-xs h-9"
                    />
                </div>

                <!-- Filtro Nivel -->
                <div>
                    <select
                        bind:value={levelVal}
                        onchange={handleLevelChange}
                        class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs shadow-xs focus:outline-none focus:ring-2 focus:ring-primary h-9"
                    >
                        <option value="all">Todos los Niveles</option>
                        {#each levels as lvl}
                            <option value={lvl}>{lvl}</option>
                        {/each}
                    </select>
                </div>

                <!-- Filtro Grado -->
                <div>
                    <select
                        bind:value={gradeVal}
                        onchange={handleGradeChange}
                        class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs shadow-xs focus:outline-none focus:ring-2 focus:ring-primary h-9"
                    >
                        <option value="all">Todos los Grados</option>
                        {#each filteredGrades as g (g.id)}
                            <option value={String(g.id)}>{g.name}</option>
                        {/each}
                    </select>
                </div>

                <!-- Filtro Sección -->
                <div>
                    <select
                        bind:value={sectionVal}
                        onchange={applyFilters}
                        class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs shadow-xs focus:outline-none focus:ring-2 focus:ring-primary h-9"
                    >
                        <option value="all">Todas las Secciones</option>
                        {#each filteredSections as sec (sec.id)}
                            <option value={String(sec.id)}>{sec.label}</option>
                        {/each}
                    </select>
                </div>

                <!-- Filtro Estado -->
                <div>
                    <select
                        bind:value={statusVal}
                        onchange={applyFilters}
                        class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs shadow-xs focus:outline-none focus:ring-2 focus:ring-primary h-9"
                    >
                        <option value="all">Todos los Estados</option>
                        <option value="active">Solo Activos</option>
                        <option value="inactive">Solo Inactivos (Bajas)</option>
                    </select>
                </div>
            </div>

            <div class="flex items-center justify-between text-xs text-muted-foreground pt-1 border-t border-border/40">
                <span>Resultados: <strong>{students.length} estudiantes encontrados</strong></span>
                <div class="flex items-center gap-2">
                    <Button variant="ghost" size="sm" onclick={resetFilters} class="h-7 text-xs">
                        Restablecer filtros
                    </Button>
                    <Button size="sm" variant="secondary" onclick={applyFilters} class="h-7 text-xs">
                        <Filter class="mr-1 size-3.5" /> Filtrar / Buscar
                    </Button>
                </div>
            </div>
        </CardContent>
    </Card>

    <!-- Tabla Principal de Estudiantes -->
    <div class="overflow-hidden rounded-xl border border-border/70 bg-card shadow-xs">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm min-w-[800px]">
                <thead class="border-b border-border/70 bg-muted/40 text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                    <tr>
                        <th class="w-12 px-4 py-3 text-center">N.º</th>
                        <th class="px-4 py-3 min-w-[240px]">Estudiante / DNI</th>
                        <th class="w-28 px-4 py-3">Código</th>
                        <th class="px-4 py-3">Nivel y Grado</th>
                        <th class="px-4 py-3">Sección y Turno</th>
                        <th class="w-28 px-4 py-3 text-center">Estado</th>
                        <th class="w-36 px-4 py-3 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/60">
                    {#if students.length === 0}
                        <tr>
                            <td colspan="7" class="px-4 py-16 text-center text-muted-foreground">
                                <div class="flex flex-col items-center justify-center gap-3 max-w-sm mx-auto">
                                    <div class="rounded-full bg-muted/60 p-4 border border-border/80">
                                        <Users class="size-10 text-muted-foreground/50" />
                                    </div>
                                    <div class="space-y-1">
                                        <h3 class="text-sm font-semibold text-foreground">
                                            {stats?.total === 0
                                                ? 'No hay estudiantes registrados'
                                                : 'No se encontraron estudiantes'}
                                        </h3>
                                        <p class="text-xs text-muted-foreground">
                                            {stats?.total === 0
                                                ? 'Aún no se han registrado alumnos en la institución. Haga clic en el botón inferior para incorporar al primer estudiante.'
                                                : 'No existen alumnos que coincidan con los criterios de búsqueda y filtros seleccionados.'}
                                        </p>
                                    </div>
                                    {#if stats?.total === 0}
                                        <Button size="sm" onclick={openCreateModal} class="bg-primary text-white text-xs mt-1 shadow-xs">
                                            <Plus class="mr-1.5 size-4" /> Registrar primer estudiante
                                        </Button>
                                    {:else}
                                        <Button variant="outline" size="sm" onclick={resetFilters} class="text-xs mt-1">
                                            Limpiar búsqueda y filtros
                                        </Button>
                                    {/if}
                                </div>
                            </td>
                        </tr>
                    {:else}
                        {#each students as student, i (student.id)}
                            <tr class="transition-colors hover:bg-muted/30 {!student.is_active ? 'opacity-60 bg-muted/20' : ''}">
                                <!-- Número correlativo -->
                                <td class="px-4 py-3 text-center text-xs font-medium text-muted-foreground">
                                    {i + 1}
                                </td>

                                <!-- Estudiante: Foto, Nombre completo y DNI -->
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        {#if student.photo_url}
                                            <img
                                                src={student.photo_url}
                                                alt={student.full_name}
                                                class="size-10 rounded-full object-cover border border-border shadow-2xs shrink-0"
                                            />
                                        {:else}
                                            <div class="size-10 rounded-full bg-primary/10 border border-primary/20 text-primary font-bold flex items-center justify-center text-xs shrink-0 shadow-2xs">
                                                {student.first_name.charAt(0)}{student.last_name.charAt(0)}
                                            </div>
                                        {/if}
                                        <div>
                                            <div class="font-bold text-foreground text-sm flex items-center gap-1.5">
                                                <span>{student.full_name}</span>
                                                {#if student.gender}
                                                    <span class="text-[10px] text-muted-foreground font-normal">
                                                        ({student.gender === 'M' ? 'Masc' : 'Fem'})
                                                    </span>
                                                {/if}
                                            </div>
                                            <div class="text-xs text-muted-foreground font-mono">
                                                DNI: <strong>{student.document_number}</strong>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Código Escolar -->
                                <td class="px-4 py-3 font-mono text-xs text-muted-foreground">
                                    {student.code}
                                </td>

                                <!-- Nivel y Grado -->
                                <td class="px-4 py-3">
                                    <div class="text-xs font-semibold text-foreground">{student.grade_name || 'Sin grado'}</div>
                                    <div class="text-[11px] text-muted-foreground">{student.level}</div>
                                </td>

                                <!-- Sección y Turno -->
                                <td class="px-4 py-3">
                                    <div class="text-xs font-medium text-foreground">Sección {student.section_name || 'A'}</div>
                                    <Badge variant="outline" class="text-[10px] border-border/80 text-muted-foreground mt-0.5">
                                        Turno {student.shift}
                                    </Badge>
                                </td>

                                <!-- Estado Activo / Inactivo -->
                                <td class="px-4 py-3 text-center">
                                    {#if student.is_active}
                                        <Badge variant="outline" class="border-emerald-500/40 bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300 text-xs font-medium">
                                            <span class="mr-1 size-1.5 rounded-full bg-emerald-500"></span> Activo
                                        </Badge>
                                    {:else}
                                        <Badge variant="outline" class="border-rose-500/40 bg-rose-50 text-rose-700 dark:bg-rose-950/30 dark:text-rose-300 text-xs font-medium">
                                            <span class="mr-1 size-1.5 rounded-full bg-rose-500"></span> Inactivo
                                        </Badge>
                                    {/if}
                                </td>

                                <!-- Botones de Acción: Editar y Desactivar -->
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <!-- Botón Editar -->
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onclick={() => openEditModal(student)}
                                            class="h-7 px-2 text-xs"
                                            title="Editar estudiante"
                                        >
                                            <Edit2 class="size-3.5 mr-1" />
                                            <span>Editar</span>
                                        </Button>

                                        <!-- Botón Desactivar / Reactivar con confirmación previa -->
                                        {#if student.is_active}
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onclick={() => openConfirmModal(student)}
                                                class="h-7 px-2 text-xs text-rose-600 border-rose-200 hover:bg-rose-50 hover:text-rose-700 dark:border-rose-900/50 dark:hover:bg-rose-950/30"
                                                title="Desactivar estudiante"
                                            >
                                                <UserMinus class="size-3.5 mr-1 text-rose-500" />
                                                <span>Desactivar</span>
                                            </Button>
                                        {:else}
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onclick={() => openConfirmModal(student)}
                                                class="h-7 px-2 text-xs text-emerald-600 border-emerald-200 hover:bg-emerald-50 hover:text-emerald-700 dark:border-emerald-900/50 dark:hover:bg-emerald-950/30"
                                                title="Reactivar estudiante"
                                            >
                                                <UserCheck class="size-3.5 mr-1 text-emerald-500" />
                                                <span>Reactivar</span>
                                            </Button>
                                        {/if}
                                    </div>
                                </td>
                            </tr>
                        {/each}
                    {/if}
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Registro / Edición de Estudiante -->
{#if isModalOpen}
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-xs overflow-y-auto">
        <div class="w-full max-w-lg rounded-2xl border border-border bg-card p-6 shadow-2xl space-y-5 my-8">
            <!-- Título del Modal -->
            <div class="flex items-center justify-between border-b border-border/60 pb-3">
                <div class="space-y-0.5">
                    <h2 class="text-lg font-bold text-foreground">
                        {isEditing ? 'Editar Datos del Estudiante' : 'Registrar Nuevo Estudiante'}
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        {isEditing
                            ? 'Modifique la información académica o personal del alumno.'
                            : 'Complete el formulario para incorporar al estudiante a la nómina oficial.'}
                    </p>
                </div>
                <button
                    type="button"
                    onclick={() => (isModalOpen = false)}
                    class="rounded-lg p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                >
                    <X class="size-5" />
                </button>
            </div>

            <!-- Formulario -->
            <form onsubmit={(e) => { e.preventDefault(); handleSubmit(); }} class="space-y-4">
                <!-- Nombres y Apellidos -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label for="student-first-name" class="block text-xs font-semibold text-foreground mb-1">
                            Nombres <span class="text-rose-500">*</span>
                        </label>
                        <Input
                            id="student-first-name"
                            placeholder="Ej. Mateo Benjamín"
                            bind:value={formFirstName}
                            class="h-9 {formErrors.first_name ? 'border-rose-500 focus-visible:ring-rose-500' : ''}"
                        />
                        {#if formErrors.first_name}
                            <p class="text-[11px] text-rose-500 mt-1">{formErrors.first_name}</p>
                        {/if}
                    </div>

                    <div>
                        <label for="student-last-name" class="block text-xs font-semibold text-foreground mb-1">
                            Apellidos <span class="text-rose-500">*</span>
                        </label>
                        <Input
                            id="student-last-name"
                            placeholder="Ej. Álvarez Quispe"
                            bind:value={formLastName}
                            class="h-9 {formErrors.last_name ? 'border-rose-500 focus-visible:ring-rose-500' : ''}"
                        />
                        {#if formErrors.last_name}
                            <p class="text-[11px] text-rose-500 mt-1">{formErrors.last_name}</p>
                        {/if}
                    </div>
                </div>

                <!-- DNI y Código Escolar -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label for="student-dni" class="block text-xs font-semibold text-foreground mb-1">
                            DNI (8 dígitos) <span class="text-rose-500">*</span>
                        </label>
                        <Input
                            id="student-dni"
                            placeholder="Ej. 78451230"
                            maxlength={8}
                            bind:value={formDni}
                            class="h-9 font-mono {formErrors.document_number ? 'border-rose-500 focus-visible:ring-rose-500' : ''}"
                        />
                        {#if formErrors.document_number}
                            <p class="text-[11px] text-rose-500 mt-1">{formErrors.document_number}</p>
                        {/if}
                    </div>

                    <div>
                        <label for="student-code" class="block text-xs font-semibold text-foreground mb-1">
                            Código de Estudiante
                        </label>
                        <Input
                            id="student-code"
                            placeholder="Ej. EST-1001 (Opcional)"
                            bind:value={formCode}
                            class="h-9 font-mono {formErrors.code ? 'border-rose-500 focus-visible:ring-rose-500' : ''}"
                        />
                        {#if formErrors.code}
                            <p class="text-[11px] text-rose-500 mt-1">{formErrors.code}</p>
                        {:else}
                            <p class="text-[10px] text-muted-foreground mt-0.5">Se autogenera si se deja vacío</p>
                        {/if}
                    </div>
                </div>

                <!-- Asignación Académica: Nivel, Grado, Sección y Turno -->
                <div class="space-y-2 rounded-xl border border-border/80 bg-muted/20 p-3">
                    <div class="text-xs font-bold text-foreground flex items-center justify-between">
                        <span>Asignación Académica</span>
                        {#if selectedFormSection}
                            <Badge variant="outline" class="text-[10px]">
                                Turno: {selectedFormSection.shift}
                            </Badge>
                        {/if}
                    </div>

                    <div>
                        <label for="student-section" class="block text-xs font-medium text-muted-foreground mb-1">
                            Nivel, Grado y Sección <span class="text-rose-500">*</span>
                        </label>
                        <select
                            id="student-section"
                            bind:value={formSectionId}
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-xs shadow-xs focus:outline-none focus:ring-2 focus:ring-primary {formErrors.section_id ? 'border-rose-500' : ''}"
                        >
                            <option value="">-- Seleccione aula --</option>
                            {#each sections as s (s.id)}
                                <option value={s.id}>
                                    [{s.level}] {s.grade_name} - Sección {s.name} ({s.shift})
                                </option>
                            {/each}
                        </select>
                        {#if formErrors.section_id}
                            <p class="text-[11px] text-rose-500 mt-1">{formErrors.section_id}</p>
                        {/if}
                    </div>
                </div>

                <!-- Género y Fecha de Nacimiento -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label for="student-gender" class="block text-xs font-semibold text-foreground mb-1">
                            Género
                        </label>
                        <select
                            id="student-gender"
                            bind:value={formGender}
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-xs shadow-xs focus:outline-none focus:ring-2 focus:ring-primary h-9"
                        >
                            <option value="M">Masculino</option>
                            <option value="F">Femenino</option>
                        </select>
                    </div>

                    <div>
                        <label for="student-birth-date" class="block text-xs font-semibold text-foreground mb-1">
                            Fecha de Nacimiento
                        </label>
                        <Input
                            id="student-birth-date"
                            type="date"
                            bind:value={formBirthDate}
                            class="h-9 text-xs"
                        />
                    </div>
                </div>

                <!-- URL o Fotografía del Alumno -->
                <div>
                    <label for="student-photo-url" class="block text-xs font-semibold text-foreground mb-1">
                        URL de Fotografía / Avatar
                    </label>
                    <div class="flex items-center gap-2">
                        <Input
                            id="student-photo-url"
                            type="url"
                            placeholder="https://ejemplo.com/foto.jpg (Opcional)"
                            bind:value={formPhotoUrl}
                            class="h-9 text-xs flex-1"
                        />
                        {#if formPhotoUrl.trim()}
                            <img
                                src={formPhotoUrl}
                                alt="Vista previa"
                                class="size-9 rounded-full object-cover border border-border shrink-0"
                            />
                        {:else}
                            <div class="size-9 rounded-full bg-muted border border-border flex items-center justify-center text-muted-foreground shrink-0">
                                <Camera class="size-4" />
                            </div>
                        {/if}
                    </div>
                </div>

                <!-- Botones de Acción del Modal -->
                <div class="flex items-center justify-end gap-2 pt-4 border-t border-border/60">
                    <Button
                        type="button"
                        variant="ghost"
                        onclick={() => (isModalOpen = false)}
                        disabled={isSubmitting}
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="submit"
                        disabled={isSubmitting}
                        class="bg-primary text-white shadow-xs"
                    >
                        {#if isSubmitting}
                            <RefreshCw class="mr-1.5 size-4 animate-spin" />
                            <span>Guardando...</span>
                        {:else}
                            <CheckCircle2 class="mr-1.5 size-4" />
                            <span>{isEditing ? 'Guardar Cambios' : 'Registrar Estudiante'}</span>
                        {/if}
                    </Button>
                </div>
            </form>
        </div>
    </div>
{/if}

<!-- Modal de Confirmación Previa: Desactivar o Reactivar Estudiante -->
{#if isConfirmModalOpen && studentToToggle}
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-xs">
        <div class="w-full max-w-md rounded-2xl border border-border bg-card p-6 shadow-2xl space-y-4 my-8">
            <!-- Icono y Encabezado -->
            <div class="flex items-start gap-3.5">
                <div class="size-10 rounded-full flex items-center justify-center shrink-0 {studentToToggle.is_active ? 'bg-rose-500/10 text-rose-600' : 'bg-emerald-500/10 text-emerald-600'}">
                    {#if studentToToggle.is_active}
                        <UserMinus class="size-5" />
                    {:else}
                        <UserCheck class="size-5" />
                    {/if}
                </div>
                <div class="space-y-1">
                    <h3 class="text-base font-bold text-foreground">
                        {studentToToggle.is_active ? '¿Desactivar estudiante?' : '¿Reactivar estudiante?'}
                    </h3>
                    <p class="text-xs text-muted-foreground">
                        {studentToToggle.is_active
                            ? 'Esta acción dará de baja temporal al alumno en la plataforma.'
                            : 'El estudiante volverá a estar habilitado para el control de asistencia escolar.'}
                    </p>
                </div>
            </div>

            <!-- Ficha del Alumno -->
            <div class="rounded-xl border border-border/80 bg-muted/30 p-3 text-xs space-y-1">
                <div class="font-bold text-foreground text-sm">{studentToToggle.full_name}</div>
                <div class="text-muted-foreground font-mono flex items-center gap-2">
                    <span>DNI: <strong>{studentToToggle.document_number}</strong></span>
                    <span>•</span>
                    <span>Cód: {studentToToggle.code}</span>
                </div>
                <div class="text-muted-foreground text-[11px]">
                    {studentToToggle.classroom}
                </div>
            </div>

            <!-- Mensaje Informativo: Conservación de Historial y Asistencia -->
            {#if studentToToggle.is_active}
                <div class="rounded-xl border border-rose-500/20 bg-rose-500/5 p-3 text-xs space-y-1.5">
                    <div class="font-semibold text-rose-700 dark:text-rose-400">
                        Consecuencias de la desactivación:
                    </div>
                    <ul class="text-[11px] text-muted-foreground space-y-1 list-disc list-inside">
                        <li>
                            <strong>Historial protegido:</strong> Se conservan intactos todos sus registros previos de asistencia, incidencias y matrícula.
                        </li>
                        <li>
                            <strong>Control de asistencia:</strong> No figurará como estudiante activo para el registro de asistencia diaria ni en el escáner QR.
                        </li>
                        <li>
                            <strong>Reversible:</strong> Podrá reactivar al estudiante en cualquier momento desde esta misma pantalla.
                        </li>
                    </ul>
                </div>
            {:else}
                <div class="rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-3 text-xs space-y-1 text-emerald-800 dark:text-emerald-300">
                    <div class="font-semibold">Reactivación de alumno:</div>
                    <p class="text-[11px] text-muted-foreground leading-relaxed">
                        El estudiante volverá a aparecer como <strong>activo en la nómina para el control de asistencia</strong> y se reanudará la validez de su credencial QR.
                    </p>
                </div>
            {/if}

            <!-- Botones de Acción -->
            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border/60">
                <Button
                    type="button"
                    variant="ghost"
                    onclick={closeConfirmModal}
                    disabled={isTogglingStatus}
                    class="text-xs"
                >
                    Cancelar
                </Button>
                <Button
                    type="button"
                    onclick={executeToggleStatus}
                    disabled={isTogglingStatus}
                    class="text-xs font-semibold {studentToToggle.is_active ? 'bg-rose-600 hover:bg-rose-700 text-white' : 'bg-emerald-600 hover:bg-emerald-700 text-white'}"
                >
                    {#if isTogglingStatus}
                        <RefreshCw class="mr-1.5 size-3.5 animate-spin" />
                        <span>Procesando...</span>
                    {:else if studentToToggle.is_active}
                        <UserMinus class="mr-1.5 size-3.5" />
                        <span>Sí, desactivar estudiante</span>
                    {:else}
                        <UserCheck class="mr-1.5 size-3.5" />
                        <span>Sí, reactivar estudiante</span>
                    {/if}
                </Button>
            </div>
        </div>
    </div>
{/if}
