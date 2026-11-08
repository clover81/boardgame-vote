<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
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
        class="flex h-12 shrink-0 items-center gap-3 border-b border-border/60 bg-background px-4"
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

        <span
            class="ml-auto hidden text-xs font-medium text-muted-foreground/60 md:inline"
        >
            Juegos de mesa
        </span>
    </header>
</template>
