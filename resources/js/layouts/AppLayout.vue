<script setup lang="ts">
import { inject, provide } from 'vue';
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
