<script setup lang="ts">
import { BuildingComplexPlus } from '@lucide/vue';
import PlayingCardsFanIcon from '@/components/icons/PlayingCardsFanIcon.vue';
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

            <!-- CABECERA -->

            <div
                class="mt-5 flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between"
            >
                <div>
                    <p
                        class="font-semibold text-[#5200FF]"
                    >
                        JUGADOR
                    </p>

                    <h1
                        class="mt-1 text-3xl font-black text-zinc-900 dark:text-zinc-100"
                    >
                        {{ player.name }}
                    </h1>

                    <span
                        class="mt-3 inline-flex rounded-full px-3 py-1 text-xs font-bold"
                        :class="
                            player.is_active
                                ? 'bg-[#CCFF00] text-black'
                                : 'bg-zinc-200 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400'
                        "
                    >
                        {{
                            player.is_active
                                ? 'Activo'
                                : 'Inactivo'
                        }}
                    </span>
                </div>

                <Link
                    href="/plays/create"
                    class="inline-flex items-center justify-center rounded-xl bg-[#5200FF] px-5 py-3 font-bold text-white transition hover:opacity-90"
                >
                    <PlayingCardsFanIcon class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-violet-600 dark:text-violet-300" aria-hidden="true" /> Registrar partida
                </Link>
            </div>

            <!-- ESTADÍSTICAS -->

            <section
                class="mt-8 grid gap-4 sm:grid-cols-3"
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
                class="mt-8 grid gap-8 lg:grid-cols-2"
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
                            class="flex items-center gap-4 rounded-xl border border-zinc-200 p-3 transition hover:border-[#5200FF] dark:border-zinc-800 dark:bg-zinc-900"
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
                                    class="h-full w-full object-cover"
                                />

                                <div
                                    v-else
                                    class="flex h-full items-center justify-center"
                                >
                                    <PlayingCardsFanIcon class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-violet-600 dark:text-violet-300" aria-hidden="true" />
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

                <section
                    class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <p
                        class="font-semibold text-[#FF6B00]"
                    >
                        GRUPO
                    </p>

                    <h2
                        class="mt-1 text-xl font-bold text-zinc-900 dark:text-zinc-100"
                    >
                        Suele jugar con
                    </h2>

                    <div
                        v-if="
                            frequent_players.length
                        "
                        class="mt-5 space-y-3"
                    >
                        <Link
                            v-for="otherPlayer in frequent_players"
                            :key="
                                otherPlayer.id
                            "
                            :href="
                                `/players/${otherPlayer.id}`
                            "
                            class="flex items-center justify-between rounded-xl border border-zinc-200 p-4 transition hover:border-[#5200FF] dark:border-zinc-800 dark:bg-zinc-900"
                        >
                            <div
                                class="flex items-center gap-3"
                            >
                                <div
                                    class="flex h-10 w-10 items-center justify-center rounded-full bg-violet-100 font-black text-[#5200FF] dark:bg-violet-950/40 dark:text-violet-300"
                                >
                                    {{
                                        otherPlayer
                                            .name
                                            .charAt(0)
                                            .toUpperCase()
                                    }}
                                </div>

                                <p
                                    class="font-bold text-zinc-900 dark:text-zinc-100"
                                >
                                    {{
                                        otherPlayer.name
                                    }}
                                </p>
                            </div>

                            <div
                                class="text-right"
                            >
                                <p
                                    class="font-black text-zinc-900 dark:text-zinc-100"
                                >
                                    {{
                                        otherPlayer
                                            .plays_together
                                    }}
                                </p>

                                <p
                                    class="text-xs text-zinc-400"
                                >
                                    juntos
                                </p>
                            </div>
                        </Link>
                    </div>

                    <p
                        v-else
                        class="mt-5 text-sm text-zinc-500 dark:text-zinc-400"
                    >
                        Todavía no hay suficientes
                        partidas registradas.
                    </p>
                </section>
            </div>

            <!-- HISTORIAL -->

            <section
                class="mt-8 rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
            >
                <div>
                    <p
                        class="font-semibold text-[#5200FF]"
                    >
                        HISTORIAL
                    </p>

                    <h2
                        class="mt-1 text-xl font-bold text-zinc-900 dark:text-zinc-100"
                    >
                        Últimas partidas
                    </h2>
                </div>

                <div
                    v-if="
                        recent_plays.length
                    "
                    class="mt-6 space-y-3"
                >
                    <article
                        v-for="play in recent_plays"
                        :key="play.id"
                        class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800 dark:bg-zinc-900"
                    >
                        <div
                            class="flex flex-col gap-4 sm:flex-row sm:items-center"
                        >
                            <div
                                class="h-20 w-16 shrink-0 overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800"
                            >
                                <img
                                    v-if="
                                        play.game.image_url
                                    "
                                    :src="
                                        play.game.image_url
                                    "
                                    :alt="
                                        play.game.name
                                    "
                                    class="h-full w-full object-cover"
                                />

                                <div
                                    v-else
                                    class="flex h-full items-center justify-center"
                                >
                                    <PlayingCardsFanIcon class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-violet-600 dark:text-violet-300" aria-hidden="true" />
                                </div>
                            </div>

                            <div
                                class="min-w-0 flex-1"
                            >
                                <Link
                                    :href="
                                        `/games/${play.game.id}`
                                    "
                                    class="text-lg font-bold text-zinc-900 hover:text-[#5200FF] dark:text-zinc-100"
                                >
                                    {{
                                        play.game.name
                                    }}
                                </Link>

                                <p
                                    v-if="
                                        play.expansion
                                    "
                                    class="mt-1 text-sm font-semibold text-[#5200FF] dark:text-violet-300"
                                >
                                    <BuildingComplexPlus class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-[#6D28D9] dark:text-[#CCFF00]" :stroke-width="2" aria-hidden="true" />
                                    {{
                                        play.expansion
                                            .name
                                    }}
                                </p>

                                <p
                                    class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"
                                >
                                    <CalendarHeart class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-lime-700 dark:text-[#CCFF00]" :stroke-width="2.2" aria-hidden="true" />
                                    {{
                                        formatDate(
                                            play.played_at
                                        )
                                    }}
                                </p>

                                <div
                                    class="mt-3 flex flex-wrap gap-2"
                                >
                                    <span
                                        v-for="playPlayer in play.players"
                                        :key="
                                            playPlayer.id
                                        "
                                        class="rounded-full bg-zinc-100 px-2 py-1 text-xs font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300"
                                    >
                                        {{
                                            playPlayer.name
                                        }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </article>
                </div>

                <div
                    v-else
                    class="mt-6 rounded-xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700"
                >
                    <p
                        class="font-semibold text-zinc-700 dark:text-zinc-300"
                    >
                        No hay partidas registradas
                        con {{ player.name }}.
                    </p>
                </div>
            </section>
        </div>
        <ScrollToTopProgress :remember-position="false" />
    </AppLayout>
</template>
