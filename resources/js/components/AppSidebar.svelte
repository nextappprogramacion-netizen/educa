<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import BarChart3 from '@lucide/svelte/icons/bar-chart-3';
    import BookOpen from '@lucide/svelte/icons/book-open';
    import Clock from '@lucide/svelte/icons/clock';
    import FolderGit2 from '@lucide/svelte/icons/folder-git-2';
    import IdCard from '@lucide/svelte/icons/id-card';
    import LayoutDashboard from '@lucide/svelte/icons/layout-dashboard';
    import LayoutGrid from '@lucide/svelte/icons/layout-grid';
    import QrCode from '@lucide/svelte/icons/qr-code';
    import Settings from '@lucide/svelte/icons/settings';
    import ShieldAlert from '@lucide/svelte/icons/shield-alert';
    import Users from '@lucide/svelte/icons/users';
    import type { Snippet } from 'svelte';
    import AppLogo from '@/components/AppLogo.svelte';
    import NavFooter from '@/components/NavFooter.svelte';
    import NavMain from '@/components/NavMain.svelte';
    import NavUser from '@/components/NavUser.svelte';
    import {
        Sidebar,
        SidebarContent,
        SidebarFooter,
        SidebarHeader,
        SidebarMenu,
        SidebarMenuButton,
        SidebarMenuItem,
    } from '@/components/ui/sidebar';
    import { toUrl } from '@/lib/utils';
    import { dashboard } from '@/routes';
    import type { NavItem } from '@/types';

    let {
        children,
    }: {
        children?: Snippet;
    } = $props();

    const mainNavItems: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
    ];

    const attendanceNavItems: NavItem[] = [
        {
            title: 'Panel general',
            href: '/attendance/overview',
            icon: LayoutDashboard,
        },
        {
            title: 'Registro de acceso',
            href: '/attendance/scanner',
            icon: QrCode,
        },
        {
            title: 'Estudiantes',
            href: '/attendance/students',
            icon: Users,
        },
        {
            title: 'Credenciales QR',
            href: '/attendance/credentials',
            icon: IdCard,
        },
        {
            title: 'Historial de asistencia',
            href: '/attendance/history',
            icon: Clock,
        },
        {
            title: 'Incidencias',
            href: '/attendance/incidents',
            icon: ShieldAlert,
        },
        {
            title: 'Reportes',
            href: '/attendance/reports',
            icon: BarChart3,
        },
        {
            title: 'Configuración',
            href: '/attendance/settings',
            icon: Settings,
        },
    ];

    const footerNavItems: NavItem[] = [
        {
            title: 'Repository',
            href: 'https://github.com/laravel/svelte-starter-kit',
            icon: FolderGit2,
        },
        {
            title: 'Documentation',
            href: 'https://laravel.com/docs/starter-kits#svelte',
            icon: BookOpen,
        },
    ];
</script>

<Sidebar collapsible="icon" variant="inset">
    <SidebarHeader>
        <SidebarMenu>
            <SidebarMenuItem>
                <SidebarMenuButton size="lg" asChild>
                    {#snippet children(props)}
                        <Link
                            {...props}
                            href={toUrl(dashboard())}
                            class={props.class}
                        >
                            <AppLogo />
                        </Link>
                    {/snippet}
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarHeader>

    <SidebarContent>
        <NavMain items={mainNavItems} label="Plataforma" />
        <NavMain items={attendanceNavItems} label="Asistencia" />
    </SidebarContent>

    <SidebarFooter>
        <NavFooter items={footerNavItems} />
        <NavUser />
    </SidebarFooter>
</Sidebar>
{@render children?.()}
