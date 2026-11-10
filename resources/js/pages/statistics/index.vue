<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';

interface GameSummary {
    id: number;
    name: string;
    image_url: string | null;
    times_played: number;
    last_played_at: string | null;
}

interface Summary {
    total_plays: number;
    games_count: number;
    games_with_plays: number;
    games_without_plays: number;
}

const props = defineProps<{
    summary: Summary;
    mostPlayed: GameSummary[];
    withoutPlays: GameSummary[];
}>();

const brokenCoverIds = ref<number[]>([]);
const markCoverBroken = (id: number) => {
    if (!brokenCoverIds.value.includes(id)) brokenCoverIds.value.push(id);
};

const formatDate = (date: string | null): string => {
    if (!date) return 'Sin fecha';
    return new Intl.DateTimeFormat('es-ES', {
        day: 'numeric', month: 'short', year: 'numeric',
    }).format(new Date(`${date.slice(0, 10)}T12:00:00`));
};

const counters = [
    { key: 'games_count', label: 'Juegos en colección', href: '/games' },
    { key: 'total_plays', label: 'Partidas registradas', href: '/plays' },
    { key: 'games_with_plays', label: 'Juegos con partidas', href: '/games' },
    { key: 'games_without_plays', label: 'Juegos sin partidas registradas', href: '/games' },
] as const;
</script>

<template>
    <Head title="Estadísticas" />
    <AppLayout>
        <main class="mx-auto w-full max-w-6xl px-4 py-6 sm:px-6">
            <div>
                <p class="font-bold uppercase tracking-wider text-[#5200FF] dark:text-[#CCFF00]">Estadísticas</p>
                <h1 class="mt-1 text-3xl font-black text-zinc-900 dark:text-zinc-100">Tu colección y tus partidas</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-zinc-600 dark:text-zinc-300">
                                    </p>
            </div>

            <section aria-label="Resumen" class="mt-7 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Link
                    v-for="counter in counters"
                    :key="counter.key"
                    :href="counter.href"
                    class="rounded-2xl border border-zinc-200 bg-white p-4 transition hover:border-[#5200FF] hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF] dark:border-zinc-800 dark:bg-zinc-950 sm:p-5"
                >
                    <p class="text-3xl font-black text-[#5200FF] dark:text-[#CCFF00]">{{ props.summary[counter.key] }}</p>
                    <p class="mt-2 text-sm font-semibold leading-snug text-zinc-700 dark:text-zinc-300">{{ counter.label }}</p>
                </Link>
            </section>

            <div class="mt-8 grid gap-6 lg:grid-cols-2">
                <section class="min-w-0 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950 sm:p-6">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="text-xl font-black">Los más jugados</h2>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Tus 10 juegos con más partidas registradas.</p>
                        </div>
                        <Link href="/plays" class="text-sm font-bold text-[#5200FF] hover:underline dark:text-[#CCFF00]">Ver historial</Link>
                    </div>
                    <ol v-if="mostPlayed.length" class="mt-5 space-y-2">
                        <li v-for="(game, index) in mostPlayed" :key="game.id" class="flex items-center gap-3 rounded-xl bg-zinc-50 p-2.5 dark:bg-zinc-900">
                            <span class="w-5 shrink-0 text-center text-sm font-black text-zinc-500">{{ index + 1 }}</span>
                            <div class="flex h-14 w-12 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                <img v-if="game.image_url && !brokenCoverIds.includes(game.id)" :src="game.image_url" :alt="`Portada de ${game.name}`" loading="lazy" class="h-full w-full object-contain" @error="markCoverBroken(game.id)" />
                                <span v-else aria-hidden="true">🎲</span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <Link :href="`/games/${game.id}`" class="font-bold text-zinc-900 hover:text-[#5200FF] dark:text-zinc-100">{{ game.name }}</Link>
                                <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">Última: {{ formatDate(game.last_played_at) }}</p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-xl font-black text-[#5200FF] dark:text-[#CCFF00]">{{ game.times_played }}</p>
                                <p class="text-xs text-zinc-500">{{ game.times_played === 1 ? 'partida' : 'partidas' }}</p>
                            </div>
                        </li>
                    </ol>
                    <p v-else class="mt-5 rounded-xl bg-zinc-50 p-4 text-sm text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400">Todavía no has registrado partidas de los juegos de tu colección.</p>
                </section>

                <section class="min-w-0 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950 sm:p-6">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="text-xl font-black">Juegos sin partidas registradas</h2>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Para recuperar títulos que todavía no aparecen en tu historial.</p>
                        </div>
                        <Link href="/games" class="text-sm font-bold text-[#5200FF] hover:underline dark:text-[#CCFF00]">Ver colección</Link>
                    </div>
                    <ul v-if="withoutPlays.length" class="mt-5 space-y-2">
                        <li v-for="game in withoutPlays" :key="game.id" class="flex items-center gap-3 rounded-xl bg-zinc-50 p-2.5 dark:bg-zinc-900">
                            <div class="flex h-12 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                <img v-if="game.image_url && !brokenCoverIds.includes(game.id)" :src="game.image_url" :alt="`Portada de ${game.name}`" loading="lazy" class="h-full w-full object-contain" @error="markCoverBroken(game.id)" />
                                <span v-else aria-hidden="true">🎲</span>
                            </div>
                            <Link :href="`/games/${game.id}`" class="min-w-0 flex-1 font-bold text-zinc-900 hover:text-[#5200FF] dark:text-zinc-100">{{ game.name }}</Link>
                        </li>
                    </ul>
                    <p v-else class="mt-5 rounded-xl bg-zinc-50 p-4 text-sm text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400">Todos los juegos de tu colección tienen alguna partida registrada.</p>
                    <p v-if="summary.games_without_plays > withoutPlays.length" class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">Mostrando 10 de {{ summary.games_without_plays }}. Puedes consultar el resto en Mi colección.</p>
                </section>
            </div>
        </main>
    </AppLayout>
</template>
