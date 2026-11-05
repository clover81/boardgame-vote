<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

import {
    BarChart3,
    BookOpen,
    FolderGit2,
    Gamepad2,
    History,
    LayoutGrid,
    LibraryBig,
    Users,
    Vote,
} from '@lucide/vue';



import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';

import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';

import { dashboard } from '@/routes';

import type { NavItem } from '@/types';

/*
|--------------------------------------------------------------------------
| Navegación principal
|--------------------------------------------------------------------------
*/

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },

    {
        title: 'Mi colección',
        href: '/games',
        icon: Gamepad2,
    },

    {
        title: 'Votaciones',
        href: '/polls',
        icon: Vote,
    },

    {
        title: 'Historial',
        href: '/plays',
        icon: History,
    },

    {
        title: 'Colecciones',
        href: '/collections',
        icon: LibraryBig,
    },

    {
        title: 'Jugadores',
        href: '/players',
        icon: Users,
    },

    {
        title: 'Estadísticas',
        href: '/statistics/decisions',
        icon: BarChart3,
    },
];

/*
|--------------------------------------------------------------------------
| Enlaces del footer
|--------------------------------------------------------------------------
*/

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: FolderGit2,
    },

    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar
        collapsible="icon"
        variant="inset"
    >
        <!-- CABECERA -->

        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        size="lg"
                        as-child
                    >
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>

                <SidebarMenuItem>
                    <SidebarMenuButton
                        as-child
                        tooltip="Crear votación"
                        class="bg-[#5200FF] font-bold text-white hover:bg-[#5200FF]/90 hover:text-white active:bg-[#5200FF]/90 active:text-white"
                    >
                        <Link href="/polls/create">
                            <Vote />
                            <span>Crear votación</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <!-- NAVEGACIÓN PRINCIPAL -->

        <SidebarContent>
            <NavMain
                :items="mainNavItems"
            />
        </SidebarContent>

        <!-- FOOTER -->

        <SidebarFooter>
            <NavFooter
                :items="footerNavItems"
            />

            <NavUser />
        </SidebarFooter>
    </Sidebar>

    <slot />
</template>
