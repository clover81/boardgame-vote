<script setup lang="ts">
import MeepleIcon from '@/components/icons/MeepleIcon.vue';
import { playerColorOptions } from '@/lib/player-colors';

const props = defineProps<{ modelValue: string }>();
const emit = defineEmits<{ (event: 'update:modelValue', color: string): void }>();
</script>

<template>
    <fieldset>
        <legend class="mb-1 text-sm font-bold">Color del jugador</legend>
        <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">
            Identificarás a cada participante por su meeple y su color.
        </p>
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
            <label
                v-for="option in playerColorOptions"
                :key="option.id"
                class="relative flex min-h-16 cursor-pointer items-center gap-2 rounded-xl border bg-white px-3 py-3 transition hover:border-violet-400 focus-within:ring-2 focus-within:ring-violet-400 dark:bg-zinc-900"
                :class="props.modelValue === option.id
                    ? 'border-violet-500 bg-violet-50 ring-2 ring-violet-500/30 dark:bg-violet-950/30'
                    : 'border-zinc-300 dark:border-zinc-700'"
            >
                <input
                    type="radio"
                    name="player-color"
                    class="sr-only"
                    :value="option.id"
                    :checked="props.modelValue === option.id"
                    @change="emit('update:modelValue', option.id)"
                />
                <MeepleIcon class="h-6 w-6 shrink-0" :class="option.text" />
                <span class="text-sm font-semibold">{{ option.label }}</span>
                <span
                    v-if="props.modelValue === option.id"
                    class="ml-auto text-sm font-bold text-violet-700 dark:text-lime-300"
                    aria-hidden="true"
                >✓</span>
            </label>
        </div>
    </fieldset>
</template>
