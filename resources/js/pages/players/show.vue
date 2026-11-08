<script setup lang="ts">
import { BuildingComplexPlus } from '@lucide/vue';
import PlayingCardsFanIcon from '@/components/icons/PlayingCardsFanIcon.vue';
import PencilSparklesIcon from '@/components/icons/PencilSparklesIcon.vue';
import MeepleIcon from '@/components/icons/MeepleIcon.vue';
import { playerNameColor } from '@/lib/player-colors';
import { CalendarHeart } from '@lucide/vue';
import {
    Head,
    Link,
} from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import ScrollToTopProgress from '@/components/ScrollToTopProgress.vue';

interface Player {
    id: number;
    name: string;
    color?: string | null;
    is_active: boolean;
}

interface Stats {
    total_plays: number;
    unique_games: number;

    last_played_at:
        string | null;
}

interface TopGame {
    id: number;
    name: string;

    image_url:
        string | null;

    plays_count: number;

    last_played_at:
        string;
}

interface FrequentPlayer {
    id: number;
    name: string;

    plays_together:
        number;
}

interface PlayPlayer {
    id: number;
    name: string;
}

interface RecentPlay {
    id: number;

    played_at: string;

    notes:
        string | null;

    game: {
        id: number;
        name: string;

        image_url:
            string | null;
    };

    expansion: {
        id: number;
        name: string;
    } | null;

    players:
        PlayPlayer[];
}

defineProps<{
    player: Player;

    stats: Stats;

    top_games:
        TopGame[];

    frequent_players:
        FrequentPlayer[];

    recent_plays:
        RecentPlay[];
}>();

const formatDate = (
    date: string | null
): string => {
    if (!date) {
        return 'Sin partidas';
    }

    return new Intl.DateTimeFormat(
        'es-ES',
        {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        }
    ).format(
        new Date(
            `${date}T12:00:00`
        )
    );
};
</script>

<template>
    <Head :title="player.name" />

    <AppLayout>
        <div
            class="mx-auto w-full max-w-6xl px-4 py-6 sm:px-6"
        >
            <!-- VOLVER -->

            <Link
                href="/players"
                class="text-sm font-semibold text-[#5200FF] hover:underline"
            >
                ← Volver a jugadores
            </Link>

            <!-- CABECERA PERSONALIZADA -->
            <header class="mt-5 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-950 sm:p-6">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex min-w-0 items-center gap-4">
                        <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl border border-zinc-200 bg-zinc-100 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:h-20 sm:w-20" aria-hidden="true">
                            <MeepleIcon class="h-11 w-11 sm:h-14 sm:w-14" :class="playerNameColor(player.name, player.color)" />
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs font-extrabold uppercase tracking-wide text-[#5200FF] dark:text-violet-400">JUGADOR</p>
                            <h1 class="mt-1 break-words text-2xl font-black sm:text-3xl" :class="playerNameColor(player.name, player.color)">{{ player.name }}</h1>
                            <span
                                class="mt-2 inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold"
                                :class="player.is_active ? 'bg-lime-200 text-lime-950 dark:bg-[#CCFF00] dark:text-black' : 'bg-zinc-200 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300'"
                            >
                                <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true" />
                                {{ player.is_active ? 'Activo' : 'Inactivo' }}
                            </span>
                        </div>
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <Link
                            :href="`/players/${player.id}/edit`"
                            class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-zinc-300 px-4 py-2.5 text-sm font-bold text-zinc-800 transition hover:border-violet-500 hover:text-[#5200FF] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF] dark:border-zinc-700 dark:text-zinc-100 dark:hover:border-violet-400 dark:hover:text-violet-300"
                        >
                            <PencilSparklesIcon class="h-4 w-4 shrink-0" aria-hidden="true" />
                            Editar jugador
                        </Link>
                        <Link
                            href="/plays/create"
                            class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-[#5200FF] px-5 py-2.5 text-sm font-bold text-white transition hover:bg-[#4100CC] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF]"
                        >
                            <PlayingCardsFanIcon class="h-4 w-4 shrink-0 text-white" aria-hidden="true" />
                            Registrar partida
                        </Link>
                    </div>
                </div>
            </header>

            <!-- ESTADÍSTICAS -->

            <section
                class="mt-5 grid gap-3 sm:grid-cols-3"
            >
                <div
                    class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <p
                        class="text-sm font-bold uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                    >
                        Partidas
                    </p>

                    <p
                        class="mt-2 text-4xl font-black text-zinc-900 dark:text-zinc-100"
                    >
                        {{
                            stats.total_plays
                        }}
                    </p>
                </div>

                <div
                    class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <p
                        class="text-sm font-bold uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                    >
                        Juegos distintos
                    </p>

                    <p
                        class="mt-2 text-4xl font-black text-zinc-900 dark:text-zinc-100"
                    >
                        {{
                            stats.unique_games
                        }}
                    </p>
                </div>

                <div
                    class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <p
                        class="text-sm font-bold uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                    >
                        Última partida
                    </p>

                    <p
                        class="mt-3 text-xl font-black text-zinc-900 dark:text-zinc-100"
                    >
                        {{
                            formatDate(
                                stats.last_played_at
                            )
                        }}
                    </p>
                </div>
            </section>

            <!-- DOS COLUMNAS -->

            <div
                class="mt-6 grid gap-5 lg:grid-cols-2"
            >
                <!-- JUEGOS MÁS JUGADOS -->

                <section
                    class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <p
                        class="font-semibold text-[#5200FF]"
                    >
                        PREFERENCIAS
                    </p>

                    <h2
                        class="mt-1 text-xl font-bold text-zinc-900 dark:text-zinc-100"
                    >
                        Juegos más jugados
                    </h2>

                    <div
                        v-if="top_games.length"
                        class="mt-5 space-y-3"
                    >
                        <Link
                            v-for="(game, index) in top_games"
                            :key="game.id"
                            :href="`/games/${game.id}`"
                            class="group flex min-w-0 items-center gap-3 rounded-xl border border-zinc-200 bg-zinc-50 p-3 transition-all duration-200 motion-safe:hover:-translate-y-0.5 hover:border-violet-400 hover:shadow-md hover:shadow-violet-500/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF] dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-violet-500"
                        >
                            <div
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#5200FF] font-black text-white"
                            >
                                {{
                                    index + 1
                                }}
                            </div>

                            <div
                                class="h-14 w-14 shrink-0 overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800"
                            >
                                <img
                                    v-if="
                                        game.image_url
                                    "
                                    :src="
                                        game.image_url
                                    "
                                    :alt="
                                        game.name
                                    "
                                    class="h-full w-full object-contain"
                                />

                                <div
                                    v-else
                                    class="flex h-full items-center justify-center"
                                >
                                    <PlayingCardsFanIcon class="h-6 w-6 text-violet-600 dark:text-violet-300" aria-hidden="true" />
                                </div>
                            </div>

                            <div
                                class="min-w-0 flex-1"
                            >
                                <p
                                    class="truncate font-bold text-zinc-900 dark:text-zinc-100"
                                >
                                    {{
                                        game.name
                                    }}
                                </p>

                                <p
                                    class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"
                                >
                                    Última:
                                    {{
                                        formatDate(
                                            game.last_played_at
                                        )
                                    }}
                                </p>
                            </div>

                            <div
                                class="text-right"
                            >
                                <p
                                    class="text-xl font-black text-[#5200FF] dark:text-[#CCFF00]"
                                >
                                    {{
                                        game.plays_count
                                    }}
                                </p>

                                <p
                                    class="text-xs text-zinc-400"
                                >
                                    partidas
                                </p>
                            </div>
                        </Link>
                    </div>

                    <p
                        v-else
                        class="mt-5 text-sm text-zinc-500 dark:text-zinc-400"
                    >
                        Todavía no hay partidas
                        registradas para este jugador.
                    </p>
                </section>

                <!-- COMPAÑEROS -->
                <section class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-950 sm:p-6">
                    <p class="text-xs font-extrabold uppercase tracking-wide text-[#FF6B00]">GRUPO</p>
                    <h2 class="mt-1 text-xl font-bold text-zinc-900 dark:text-zinc-100">Suele jugar con</h2>
                    <div v-if="frequent_players.length" class="mt-5 space-y-3">
                        <Link
                            v-for="otherPlayer in frequent_players"
                            :key="otherPlayer.id"
                            :href="`/players/${otherPlayer.id}`"
                            class="group flex min-w-0 items-center justify-between gap-3 rounded-xl border border-zinc-200 bg-zinc-50 p-3.5 transition-all duration-200 motion-safe:hover:-translate-y-0.5 hover:border-violet-400 hover:shadow-md hover:shadow-violet-500/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF] dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-violet-500"
                        >
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-950" aria-hidden="true">
                                    <MeepleIcon class="h-7 w-7" :class="playerNameColor(otherPlayer.name)" />
                                </span>
                                <p class="min-w-0 truncate font-bold" :class="playerNameColor(otherPlayer.name)">{{ otherPlayer.name }}</p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-lg font-black text-zinc-900 dark:text-zinc-100">{{ otherPlayer.plays_together }}</p>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">juntos</p>
                            </div>
                        </Link>
                    </div>
                    <p v-else class="mt-5 text-sm text-zinc-500 dark:text-zinc-400">Todavía no hay suficientes partidas registradas.</p>
                </section>
            </div>

            <!-- HISTORIAL -->
            <section class="mt-6 rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-950 sm:p-6">
                <p class="text-xs font-extrabold uppercase tracking-wide text-[#5200FF] dark:text-violet-400">HISTORIAL</p>
                <h2 class="mt-1 text-xl font-bold text-zinc-900 dark:text-zinc-100">Últimas partidas</h2>

                <div v-if="recent_plays.length" class="mt-5 grid gap-3 lg:grid-cols-2">
                    <article
                        v-for="play in recent_plays"
                        :key="play.id"
                        class="min-w-0 rounded-xl border border-zinc-200 bg-zinc-50 p-3.5 transition-colors hover:border-violet-400 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-violet-500"
                    >
                        <div class="flex min-w-0 items-start gap-3">
                            <div class="flex h-20 w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                <img
                                    v-if="play.game.image_url"
                                    :src="play.game.image_url"
                                    :alt="play.game.name"
                                    loading="lazy"
                                    class="h-full w-full object-contain"
                                />
                                <PlayingCardsFanIcon v-else class="h-7 w-7 text-violet-600 dark:text-violet-300" aria-hidden="true" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <Link
                                    :href="`/games/${play.game.id}`"
                                    class="block break-words text-base font-bold text-zinc-900 hover:text-[#5200FF] hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF] dark:text-zinc-100 dark:hover:text-violet-300"
                                >{{ play.game.name }}</Link>
                                <p v-if="play.expansion" class="mt-1 flex items-start gap-1.5 text-xs font-semibold text-[#5200FF] dark:text-violet-300">
                                    <BuildingComplexPlus class="mt-0.5 h-4 w-4 shrink-0 text-[#6D28D9] dark:text-[#CCFF00]" :stroke-width="2" aria-hidden="true" />
                                    <span class="min-w-0 break-words">{{ play.expansion.name }}</span>
                                </p>
                                <p class="mt-1.5 flex items-center gap-1.5 text-xs text-zinc-600 dark:text-zinc-400">
                                    <CalendarHeart class="h-4 w-4 shrink-0 text-lime-700 dark:text-[#CCFF00]" :stroke-width="2.2" aria-hidden="true" />
                                    {{ formatDate(play.played_at) }}
                                </p>
                                <div class="mt-3 flex flex-wrap gap-1.5">
                                    <span
                                        v-for="playPlayer in play.players"
                                        :key="playPlayer.id"
                                        class="inline-flex min-w-0 items-center gap-1 rounded-full bg-zinc-100 px-2 py-1 text-xs font-semibold dark:bg-zinc-800"
                                        :class="playerNameColor(playPlayer.name)"
                                    >
                                        <MeepleIcon class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        <span class="break-words">{{ playPlayer.name }}</span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </article>
                </div>
                <div v-else class="mt-5 rounded-xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700">
                    <p class="font-semibold text-zinc-700 dark:text-zinc-300">No hay partidas registradas con {{ player.name }}.</p>
                </div>
            </section>
        </div>
        <ScrollToTopProgress :remember-position="false" />
    </AppLayout>
</template>
