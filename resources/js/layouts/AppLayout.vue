<script setup lang="ts">
import { inject, provide, watchEffect } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { setPlayerColors } from '@/lib/player-colors';
import FlashMessages from '@/components/FlashMessages.vue';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();

// Algunas páginas ya incluyen AppLayout aunque Inertia aplique otro exterior.
// Solo el primer AppLayout del árbol debe dibujar la barra lateral y los avisos.
const hasParentAppLayout = inject<boolean>('boardgame-vote-app-layout', false);
provide('boardgame-vote-app-layout', true);

const page = usePage();
watchEffect(() => {
    setPlayerColors(page.props.player_colors as Record<string, string> | undefined);
});
</script>

<template>
    <template v-if="hasParentAppLayout">
        <slot />
    </template>
    <template v-else>
        <AppLayout :breadcrumbs="breadcrumbs">
            <slot />
        </AppLayout>

        <FlashMessages />
    </template>
</template>
