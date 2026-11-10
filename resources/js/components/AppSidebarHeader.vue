<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import MeepleIcon from '@/components/icons/MeepleIcon.vue';
import { playerColorOptions } from '@/lib/player-colors';
import {
    BarChart3,
    Gamepad2,
    History,
    LayoutGrid,
    LibraryBig,
    Users,
    Vote,
} from '@lucide/vue';

import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const page = usePage();

// El color pertenece a un jugador registrado por ESTA cuenta, no a otra cuenta.
// Si el nombre de usuario incluye apellidos, intentamos también el primer nombre.
const welcomeFirstName = computed(() => {
    const name = page.props.auth?.user?.name?.trim() ?? '';
    return name.split(/\s+/)[0] || 'jugador';
});

const welcomeMeepleColor = computed(() => {
    const fullName = (page.props.auth?.user?.name ?? '')
        .trim()
        .toLocaleLowerCase('es');
    const firstName = fullName.split(/\s+/)[0];
    const assigned = page.props.player_colors as Record<string, string> | undefined;
    const colorId = assigned?.[fullName] ?? assigned?.[firstName];

    // Reutilizamos la paleta validada de Jugadores; sin color asignado, neutro.
    return playerColorOptions.find((option) => option.id === colorId)?.text
        ?? 'text-zinc-600 dark:text-white';
});


const currentSection = computed(() => {
    const path = page.url.split('?')[0];

    if (path.startsWith('/games')) {
        return { title: 'Mi colección', icon: Gamepad2 };
    }
    if (path.startsWith('/polls')) {
        return { title: 'Votaciones', icon: Vote };
    }
    if (path.startsWith('/plays')) {
        return { title: 'Mis partidas', icon: History };
    }
    if (path.startsWith('/collections')) {
        return { title: 'Colecciones', icon: LibraryBig };
    }
    if (path.startsWith('/players')) {
        return { title: 'Jugadores', icon: Users };
    }
    if (path.startsWith('/statistics')) {
        return { title: 'Estadísticas', icon: BarChart3 };
    }

    return { title: 'Mi espacio', icon: LayoutGrid };
});
</script>

<template>
    <header
        class="flex h-14 shrink-0 items-center gap-3 border-b border-border/60 bg-background px-4 sm:px-6"
    >
        <SidebarTrigger
            class="-ml-1 shrink-0 rounded-lg hover:bg-[#5200FF]/10"
        />

        <span class="h-5 w-px shrink-0 bg-border/70"></span>

        <div class="flex min-w-0 items-center gap-2">
            <component
                :is="currentSection.icon"
                class="size-4 shrink-0 text-[#CCFF00]"
            />

            <span class="truncate text-sm font-semibold text-foreground/85">
                {{ currentSection.title }}
            </span>
        </div>

        <div class="ml-auto flex min-w-0 items-center gap-3 pl-2">
            <span
                class="flex size-9 shrink-0 items-center justify-center rounded-xl border border-violet-200 bg-violet-50 dark:border-zinc-700 dark:bg-zinc-900"
                aria-hidden="true"
            >
                <MeepleIcon class="size-6" :class="welcomeMeepleColor" />
            </span>
            <div class="hidden min-w-0 text-right lg:block">
                <span class="block truncate text-sm font-bold leading-tight text-foreground">
                    ¡Bienvenido, {{ welcomeFirstName }}!
                </span>
                <span class="block text-xs text-muted-foreground">
                    Tu ludoteca
                </span>
            </div>
        </div>
    </header>
</template>
