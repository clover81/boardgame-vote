<script setup lang="ts">
import { computed } from 'vue';
import { Trophy } from '@lucide/vue';

interface Player {
    id: number;
    name: string;
}

const props = withDefaults(defineProps<{
    players: Player[];
    participantIds: number[];
    modelValue: number[];
    heading?: string;
}>(), {
    heading: '¿Quién ganó?',
});

const emit = defineEmits<{
    'update:modelValue': [ids: number[]];
}>();

const availablePlayers = computed(() =>
    props.players.filter(player => props.participantIds.includes(player.id))
);

const toggleWinner = (id: number) => {
    if (!props.participantIds.includes(id)) return;

    const updated = props.modelValue.includes(id)
        ? props.modelValue.filter(value => value !== id)
        : [...props.modelValue, id];

    emit('update:modelValue', updated);
};
</script>

<template>
    <section class="rounded-2xl border border-zinc-200 bg-white p-5 sm:p-6 dark:border-zinc-800 dark:bg-zinc-950">
        <h2 class="text-xl font-bold text-zinc-900 dark:text-zinc-100">
            {{ heading }}
            <span class="text-sm font-normal text-zinc-500">(opcional)</span>
        </h2>

        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
            Puedes marcar uno o varios ganadores si hubo empate.
            Si prefieres no indicarlo, déjalos sin marcar.
        </p>

        <p v-if="!availablePlayers.length" class="mt-4 text-sm text-zinc-500">
            Primero selecciona quién participó en la partida.
        </p>

        <div v-else class="mt-4 grid gap-2 sm:grid-cols-2">
            <button
                v-for="player in availablePlayers"
                :key="player.id"
                type="button"
                :aria-pressed="modelValue.includes(player.id)"
                class="flex min-h-12 items-center justify-between gap-3 rounded-xl border-2 px-3 py-2.5 text-left transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF]"
                :class="modelValue.includes(player.id)
                    ? 'border-[#5200FF] bg-violet-50 text-[#5200FF] dark:border-[#CCFF00] dark:bg-violet-950/30 dark:text-[#CCFF00]'
                    : 'border-zinc-200 bg-white text-zinc-800 hover:border-violet-400 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100'"
                @click="toggleWinner(player.id)"
            >
                <span class="flex min-w-0 items-center gap-2">
                    <Trophy
                        class="size-4 shrink-0"
                        :class="modelValue.includes(player.id)
                            ? 'text-amber-500'
                            : 'text-zinc-400'"
                        aria-hidden="true"
                    />
                    <span class="break-words font-semibold">{{ player.name }}</span>
                </span>
                <span class="shrink-0 text-xs font-bold">
                    {{ modelValue.includes(player.id) ? 'Ganador' : 'Marcar' }}
                </span>
            </button>
        </div>
    </section>
</template>
