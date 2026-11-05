<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { toast } from 'vue-sonner';

interface Flash {
    success?: string | null;
    error?: string | null;
}

const page = usePage();

const flash = computed(
    () => (page.props.flash as Flash | undefined) ?? {},
);

// Convertir los mensajes de Laravel en notificaciones Sonner.
watch(
    () => [flash.value.success, flash.value.error] as const,
    ([success, error]) => {
        if (error) {
            toast.error('Ha ocurrido un problema', {
                description: error,
                duration: 5000,
            });
        } else if (success) {
            toast.success('Todo listo', {
                description: success,
                duration: 4000,
            });
        }
    },
    { immediate: true },
);
</script>

<template>
    <!-- Las notificaciones se muestran mediante Sonner. -->
</template>
