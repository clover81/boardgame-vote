
<script setup lang="ts">
import PlayingCardsFanIcon from '@/components/icons/PlayingCardsFanIcon.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import ScrollToTopProgress from '@/components/ScrollToTopProgress.vue';

interface Player {
    id: number;
    name: string;
    is_active: boolean;
}

defineProps<{
    players: Player[];
}>();

const togglePlayer = (player: Player) => {
    router.patch(
        `/players/${player.id}/toggle`,
        {},
        { preserveScroll: true }
    );
};
</script>

<template>
    <Head title="Jugadores" />

    <AppLayout>
        <div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">

            <!-- Cabecera -->
            <div
                class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <p class="font-semibold text-[#5200FF]">
                        JUGADORES
                    </p>

                    <h1 class="text-2xl font-bold sm:text-3xl">
                        Jugadores habituales
                    </h1>

                    <p class="mt-2 text-zinc-500">
                        Personas que suelen participar en tus partidas.
                    </p>
                </div>

                <Link
                    href="/players/create"
                    class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-[#5200FF] px-5 py-3 font-semibold text-white shadow-sm transition-all duration-200 hover:bg-[#4000cc] hover:shadow-md hover:shadow-violet-500/20 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF] sm:w-auto"
                >
                    + Añadir jugador
                </Link>
            </div>

            <!-- Listado de jugadores -->
            <div
                v-if="players.length > 0"
                class="space-y-3"
            >
                <div
                    v-for="player in players"
                    :key="player.id"
                    class="group flex flex-col items-stretch gap-4 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm transition-all duration-200 motion-safe:hover:-translate-y-0.5 hover:border-violet-400 hover:shadow-lg hover:shadow-violet-500/10 focus-within:ring-2 focus-within:ring-violet-500/30 dark:border-zinc-800 dark:bg-zinc-950 dark:hover:border-violet-500 sm:flex-row sm:items-center sm:justify-between sm:p-5"
                >
                    <div class="flex min-w-0 items-start gap-3 sm:items-center">
                        <span
                            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border border-violet-200 bg-violet-100 text-lg font-black text-[#5200FF] shadow-sm transition-colors duration-200 group-hover:border-violet-300 group-hover:bg-violet-200 dark:border-violet-900/70 dark:bg-violet-950 dark:text-violet-200 dark:group-hover:bg-violet-900"
                            aria-hidden="true"
                        >
                            {{ player.name.trim().charAt(0).toLocaleUpperCase() || '<PlayingCardsFanIcon class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-violet-600 dark:text-violet-300" aria-hidden="true" />' }}
                        </span>

                        <div class="min-w-0">
                            <h2 class="break-words text-lg font-bold text-zinc-900 dark:text-zinc-100">
                                {{ player.name }}
                            </h2>

                            <span
                                class="mt-2 inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset"
                                :class="player.is_active
                                    ? 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/50 dark:text-emerald-300 dark:ring-emerald-900'
                                    : 'bg-zinc-100 text-zinc-600 ring-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:ring-zinc-700'"
                            >
                                <span
                                    aria-hidden="true"
                                    class="h-2 w-2 rounded-full"
                                    :class="player.is_active ? 'bg-emerald-500' : 'bg-zinc-400'"
                                ></span>
                                {{ player.is_active ? 'Activo' : 'Inactivo' }}
                            </span>

                            <Link
                                :href="`/players/${player.id}`"
                                class="mt-3 block w-fit rounded font-semibold text-[#5200FF] transition-colors hover:text-[#4000cc] hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF] dark:text-violet-300 dark:hover:text-violet-200"
                            >
                                Ver estadísticas →
                            </Link>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="min-h-11 w-full shrink-0 rounded-xl border px-4 py-2 text-sm font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF] sm:w-auto"
                        :class="player.is_active
                            ? 'border-zinc-300 text-zinc-700 hover:border-amber-300 hover:bg-amber-50 hover:text-amber-800 dark:border-zinc-700 dark:text-zinc-200 dark:hover:border-amber-700 dark:hover:bg-amber-950/40 dark:hover:text-amber-200'
                            : 'border-violet-200 bg-violet-50 text-[#5200FF] hover:border-violet-400 hover:bg-violet-100 dark:border-violet-800 dark:bg-violet-950/30 dark:text-violet-200 dark:hover:bg-violet-900/40'"
                        :aria-label="`${player.is_active ? 'Desactivar' : 'Activar'} a ${player.name}`"
                        @click="togglePlayer(player)"
                    >
                        {{
                            player.is_active
                                ? 'Desactivar'
                                : 'Activar'
                        }}
                    </button>
                </div>
            </div>

            <!-- Sin jugadores -->
            <div
                v-else
                class="rounded-2xl border border-dashed p-10 text-center text-zinc-500"
            >
                Todavía no hay jugadores registrados.
            </div>

        </div>
        <ScrollToTopProgress :remember-position="false" />
    </AppLayout>
</template>
