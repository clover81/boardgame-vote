<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    BarChart3,
    Dices,
    Gamepad2,
    History,
    LibraryBig,
    Users,
    Vote,
} from '@lucide/vue';

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

import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    { title: 'Mi colección', href: '/games', icon: Gamepad2 },
    { title: 'Votaciones', href: '/polls', icon: Vote },
    { title: 'Mis partidas', href: '/plays', icon: History },
    { title: 'Colecciones', href: '/collections', icon: LibraryBig },
    { title: 'Jugadores', href: '/players', icon: Users },
    {
        title: 'Estadísticas',
        href: '/statistics/decisions',
        icon: BarChart3,
    },
];
</script>

<template>
    <Sidebar
        collapsible="icon"
        variant="inset"
        class="border-r border-[#5200FF]/10 dark:border-[#CCFF00]/10"
    >
        <SidebarHeader class="gap-4 px-3 pb-4 pt-4">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link href="/games">
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-[#5200FF]"
                            >
                                <Dices class="size-5 text-[#CCFF00]" />
                            </div>

                            <div class="grid min-w-0 flex-1 text-left">
                                <span class="truncate text-sm font-extrabold">
                                    Juegos de mesa
                                </span>
                                <span class="truncate text-xs text-muted-foreground">
                                    Clover
                                </span>
                            </div>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>

            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        as-child
                        tooltip="Crear votación"
                        class="h-11 rounded-xl bg-[#5200FF] px-3 font-bold text-white hover:bg-[#4200D0] hover:text-white active:bg-[#4200D0] active:text-white"
                    >
                        <Link href="/polls/create">
                            <Vote class="size-4 text-[#CCFF00]" />
                            <span>Crear votación</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter class="border-t border-border pt-3">
            <NavUser />
        </SidebarFooter>
    </Sidebar>

    <slot />
</template>
