<script setup lang="ts">
import {
    computed,
    ref,
} from 'vue';

import {
    Head,
    Link,
} from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';

interface Game {
    id: number;
    name: string;
    image_url: string | null;
}

interface SummaryGame {
    game: Game;
    value: number;
}

interface Summary {
    closed_polls: number;

    polls_with_play:
        number;

    candidate_appearances:
        number;

    most_first_votes:
        SummaryGame | null;

    most_wins:
        SummaryGame | null;

    most_played_after_poll:
        SummaryGame | null;

    biggest_plan_b:
        SummaryGame | null;
}

interface GameStatistics {
    game: Game;

    appearances: number;

    first_votes: number;
    second_votes: number;

    points: number;

    wins: number;

    played_as_candidate:
        number;

    wins_played:
        number;

    played_after_poll:
        number;

    played_from_outside:
        number;

    win_rate:
        number;

    candidate_conversion:
        number;

    winner_conversion:
        number | null;
}

const props = defineProps<{
    summary: Summary;

    games:
        GameStatistics[];
}>();

type SortType =
    | 'first_votes'
    | 'points'
    | 'wins'
    | 'appearances'
    | 'win_rate'
    | 'played'
    | 'plan_b';

const sort =
    ref<SortType>(
        'first_votes'
    );

const sortedGames =
    computed(() => {
        const games =
            [...props.games];

        if (
            sort.value ===
            'first_votes'
        ) {
            return games.sort(
                (a, b) =>
                    b.first_votes
                    -
                    a.first_votes
            );
        }

        if (
            sort.value ===
            'points'
        ) {
            return games.sort(
                (a, b) =>
                    b.points
                    -
                    a.points
            );
        }

        if (
            sort.value ===
            'wins'
        ) {
            return games.sort(
                (a, b) =>
                    b.wins
                    -
                    a.wins
            );
        }

        if (
            sort.value ===
            'appearances'
        ) {
            return games.sort(
                (a, b) =>
                    b.appearances
                    -
                    a.appearances
            );
        }

        if (
            sort.value ===
            'win_rate'
        ) {
            return games.sort(
                (a, b) =>
                    b.win_rate
                    -
                    a.win_rate
            );
        }

        if (
            sort.value ===
            'played'
        ) {
            return games.sort(
                (a, b) =>
                    b.played_after_poll
                    -
                    a.played_after_poll
            );
        }

        return games.sort(
            (a, b) =>
                b.played_from_outside
                -
                a.played_from_outside
        );
    });
</script>

<template>
    <Head
        title="Estadísticas de juegos"
    />

    <AppLayout>
        <div
            class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6"
        >
            <!-- ================================================== -->
            <!-- CABECERA                                           -->
            <!-- ================================================== -->

            <div>
                <p
                    class="font-semibold text-[#5200FF]"
                >
                    ESTADÍSTICAS
                </p>

                <h1
                    class="mt-1 text-2xl font-black text-zinc-900 dark:text-zinc-100 sm:text-3xl"
                >
                    Rendimiento en votaciones
                </h1>

                <p
                    class="mt-2 max-w-3xl text-zinc-500 dark:text-zinc-400"
                >
                    Descubre qué juegos reciben
                    más apoyo, cuáles ganan y cuáles
                    terminan realmente saliendo a
                    mesa.
                </p>
            </div>

            <!-- ================================================== -->
            <!-- SUBNAVEGACIÓN                                      -->
            <!-- ================================================== -->

            <div
                class="mt-6 flex flex-wrap gap-2"
            >
                <Link
                    href="/statistics/decisions"
                    class="rounded-xl border border-zinc-300 px-4 py-2 font-bold text-zinc-600 transition hover:border-[#5200FF] dark:border-zinc-700 dark:text-zinc-300"
                >
                    Decisiones
                </Link>

                <Link
                    href="/statistics/games"
                    class="rounded-xl bg-[#5200FF] px-4 py-2 font-bold text-white"
                >
                    Juegos
                </Link>
            </div>

            <!-- SIN DATOS -->

            <div
                v-if="
                    games.length === 0
                "
                class="mt-8 rounded-2xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700"
            >
                <p
                    class="font-bold text-zinc-900 dark:text-zinc-100"
                >
                    Todavía no tenemos
                    votaciones cerradas suficientes.
                </p>
            </div>

            <template v-else>
                <!-- ================================================== -->
                <!-- RESUMEN                                            -->
                <!-- ================================================== -->

                <section
                    class="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                >
                    <!-- PRIMERAS -->

                    <div
                        class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                    >
                        <p
                            class="text-xs font-black uppercase tracking-wide text-[#5200FF]"
                        >
                            Más primeras opciones
                        </p>

                        <template
                            v-if="
                                summary.most_first_votes
                            "
                        >
                            <Link
                                :href="
                                    `/games/${summary.most_first_votes.game.id}`
                                "
                                class="mt-3 block text-xl font-black text-zinc-900 hover:text-[#5200FF] dark:text-zinc-100"
                            >
                                {{
                                    summary
                                        .most_first_votes
                                        .game.name
                                }}
                            </Link>

                            <p
                                class="mt-2 text-3xl font-black text-[#5200FF]"
                            >
                                {{
                                    summary
                                        .most_first_votes
                                        .value
                                }}
                            </p>
                        </template>

                        <p
                            v-else
                            class="mt-3 text-sm text-zinc-500"
                        >
                            Sin votos todavía.
                        </p>
                    </div>

                    <!-- VICTORIAS -->

                    <div
                        class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                    >
                        <p
                            class="text-xs font-black uppercase tracking-wide text-[#FF6B00]"
                        >
                            Más victorias
                        </p>

                        <template
                            v-if="
                                summary.most_wins
                            "
                        >
                            <Link
                                :href="
                                    `/games/${summary.most_wins.game.id}`
                                "
                                class="mt-3 block text-xl font-black text-zinc-900 hover:text-[#5200FF] dark:text-zinc-100"
                            >
                                {{
                                    summary
                                        .most_wins
                                        .game.name
                                }}
                            </Link>

                            <p
                                class="mt-2 text-3xl font-black text-[#FF6B00]"
                            >
                                {{
                                    summary
                                        .most_wins
                                        .value
                                }}
                            </p>
                        </template>

                        <p
                            v-else
                            class="mt-3 text-sm text-zinc-500"
                        >
                            Sin ganador claro todavía.
                        </p>
                    </div>

                    <!-- JUGADO -->

                    <div
                        class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                    >
                        <p
                            class="text-xs font-black uppercase tracking-wide text-green-600"
                        >
                            Más jugado tras votar
                        </p>

                        <template
                            v-if="
                                summary
                                    .most_played_after_poll
                            "
                        >
                            <Link
                                :href="
                                    `/games/${summary.most_played_after_poll.game.id}`
                                "
                                class="mt-3 block text-xl font-black text-zinc-900 hover:text-[#5200FF] dark:text-zinc-100"
                            >
                                {{
                                    summary
                                        .most_played_after_poll
                                        .game.name
                                }}
                            </Link>

                            <p
                                class="mt-2 text-3xl font-black text-green-600"
                            >
                                {{
                                    summary
                                        .most_played_after_poll
                                        .value
                                }}
                            </p>
                        </template>

                        <p
                            v-else
                            class="mt-3 text-sm text-zinc-500"
                        >
                            Sin partidas vinculadas.
                        </p>
                    </div>

                    <!-- PLAN B -->

                    <div
                        class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                    >
                        <p
                            class="text-xs font-black uppercase tracking-wide text-[#F900FF]"
                        >
                            Mayor plan B
                        </p>

                        <template
                            v-if="
                                summary.biggest_plan_b
                            "
                        >
                            <Link
                                :href="
                                    `/games/${summary.biggest_plan_b.game.id}`
                                "
                                class="mt-3 block text-xl font-black text-zinc-900 hover:text-[#5200FF] dark:text-zinc-100"
                            >
                                {{
                                    summary
                                        .biggest_plan_b
                                        .game.name
                                }}
                            </Link>

                            <p
                                class="mt-2 text-3xl font-black text-[#F900FF]"
                            >
                                {{
                                    summary
                                        .biggest_plan_b
                                        .value
                                }}
                            </p>
                        </template>

                        <p
                            v-else
                            class="mt-3 text-sm text-zinc-500"
                        >
                            No habéis elegido juegos
                            externos.
                        </p>
                    </div>
                </section>

                <!-- ================================================== -->
                <!-- INFORMACIÓN GENERAL                                -->
                <!-- ================================================== -->

                <div
                    class="mt-6 flex flex-wrap gap-3 text-sm text-zinc-500 dark:text-zinc-400"
                >
                    <span>
                        🗳
                        {{
                            summary.closed_polls
                        }}
                        votaciones cerradas
                    </span>

                    <span>
                        🎲
                        {{
                            summary.polls_with_play
                        }}
                        con partida registrada
                    </span>

                    <span>
                        📋
                        {{
                            summary.candidate_appearances
                        }}
                        apariciones de candidatos
                    </span>
                </div>

                <!-- ================================================== -->
                <!-- ORDEN                                              -->
                <!-- ================================================== -->

                <section
                    class="mt-8 rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <div
                        class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div>
                            <p
                                class="font-bold text-zinc-900 dark:text-zinc-100"
                            >
                                Ordenar juegos
                            </p>

                            <p
                                class="mt-1 text-sm text-zinc-500"
                            >
                                Cambia el criterio
                                para analizar el
                                comportamiento.
                            </p>
                        </div>

                        <select
                            v-model="sort"
                            class="rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                        >
                            <option
                                value="first_votes"
                            >
                                🥇 Primeras opciones
                            </option>

                            <option
                                value="points"
                            >
                                Puntos
                            </option>

                            <option
                                value="wins"
                            >
                                Victorias
                            </option>

                            <option
                                value="appearances"
                            >
                                Apariciones
                            </option>

                            <option
                                value="win_rate"
                            >
                                % victorias
                            </option>

                            <option
                                value="played"
                            >
                                Veces jugado
                            </option>

                            <option
                                value="plan_b"
                            >
                                Plan B
                            </option>
                        </select>
                    </div>
                </section>

                <!-- ================================================== -->
                <!-- JUEGOS                                             -->
                <!-- ================================================== -->

                <section
                    class="mt-6 space-y-4"
                >
                    <article
                        v-for="(item, index) in sortedGames"
                        :key="
                            item.game.id
                        "
                        class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-950"
                    >
                        <div
                            class="flex flex-col gap-5 xl:flex-row xl:items-center"
                        >
                            <!-- POSICIÓN -->

                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-zinc-100 font-black text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300"
                            >
                                {{
                                    index + 1
                                }}
                            </div>

                            <!-- JUEGO -->

                            <div
                                class="flex min-w-0 gap-4 xl:w-64"
                            >
                                <div
                                    class="h-20 w-16 shrink-0 overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800"
                                >
                                    <img
                                        v-if="
                                            item
                                                .game
                                                .image_url
                                        "
                                        :src="
                                            item
                                                .game
                                                .image_url
                                        "
                                        :alt="
                                            item
                                                .game
                                                .name
                                        "
                                        class="h-full w-full object-cover"
                                    />

                                    <div
                                        v-else
                                        class="flex h-full items-center justify-center"
                                    >
                                        🎲
                                    </div>
                                </div>

                                <div>
                                    <Link
                                        :href="
                                            `/games/${item.game.id}`
                                        "
                                        class="font-black text-zinc-900 hover:text-[#5200FF] dark:text-zinc-100"
                                    >
                                        {{
                                            item
                                                .game
                                                .name
                                        }}
                                    </Link>

                                    <p
                                        class="mt-1 text-sm text-zinc-500"
                                    >
                                        {{
                                            item.appearances
                                        }}
                                        {{
                                            item.appearances === 1
                                                ? 'aparición'
                                                : 'apariciones'
                                        }}
                                    </p>
                                </div>
                            </div>

                            <!-- VOTOS -->

                            <div
                                class="grid flex-1 grid-cols-2 gap-3 sm:grid-cols-4"
                            >
                                <div>
                                    <p
                                        class="text-xs font-bold uppercase text-zinc-400"
                                    >
                                        Primeras
                                    </p>

                                    <p
                                        class="mt-1 text-xl font-black text-[#5200FF]"
                                    >
                                        {{
                                            item.first_votes
                                        }}
                                    </p>
                                </div>

                                <div>
                                    <p
                                        class="text-xs font-bold uppercase text-zinc-400"
                                    >
                                        Segundas
                                    </p>

                                    <p
                                        class="mt-1 text-xl font-black text-[#FF6B00]"
                                    >
                                        {{
                                            item.second_votes
                                        }}
                                    </p>
                                </div>

                                <div>
                                    <p
                                        class="text-xs font-bold uppercase text-zinc-400"
                                    >
                                        Puntos
                                    </p>

                                    <p
                                        class="mt-1 text-xl font-black text-zinc-900 dark:text-zinc-100"
                                    >
                                        {{
                                            item.points
                                        }}
                                    </p>
                                </div>

                                <div>
                                    <p
                                        class="text-xs font-bold uppercase text-zinc-400"
                                    >
                                        Victorias
                                    </p>

                                    <p
                                        class="mt-1 text-xl font-black text-green-600"
                                    >
                                        {{
                                            item.wins
                                        }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- TASAS -->

                        <div
                            class="mt-5 grid gap-3 border-t border-zinc-200 pt-5 sm:grid-cols-3 dark:border-zinc-800"
                        >
                            <div>
                                <p
                                    class="text-xs font-bold uppercase tracking-wide text-zinc-400"
                                >
                                    Tasa de victoria
                                </p>

                                <p
                                    class="mt-1 text-lg font-black text-zinc-900 dark:text-zinc-100"
                                >
                                    {{
                                        item.win_rate
                                    }}%
                                </p>
                            </div>

                            <div>
                                <p
                                    class="text-xs font-bold uppercase tracking-wide text-zinc-400"
                                >
                                    Candidato → jugado
                                </p>

                                <p
                                    class="mt-1 text-lg font-black text-zinc-900 dark:text-zinc-100"
                                >
                                    {{
                                        item.candidate_conversion
                                    }}%
                                </p>
                            </div>

                            <div>
                                <p
                                    class="text-xs font-bold uppercase tracking-wide text-zinc-400"
                                >
                                    Ganador → jugado
                                </p>

                                <p
                                    v-if="
                                        item.winner_conversion
                                        !== null
                                    "
                                    class="mt-1 text-lg font-black text-zinc-900 dark:text-zinc-100"
                                >
                                    {{
                                        item.winner_conversion
                                    }}%
                                </p>

                                <p
                                    v-else
                                    class="mt-1 text-sm text-zinc-400"
                                >
                                    Aún no ha ganado
                                </p>
                            </div>
                        </div>

                        <!-- RESULTADO REAL -->

                        <div
                            class="mt-4 flex flex-wrap gap-2"
                        >
                            <span
                                class="rounded-full bg-green-100 px-3 py-1 text-xs font-bold text-green-700 dark:bg-green-950/30 dark:text-green-300"
                            >
                                🎲 Jugado tras votar:
                                {{
                                    item.played_after_poll
                                }}
                            </span>

                            <span
                                v-if="
                                    item.played_from_outside
                                "
                                class="rounded-full bg-fuchsia-100 px-3 py-1 text-xs font-bold text-fuchsia-700 dark:bg-fuchsia-950/30 dark:text-fuchsia-300"
                            >
                                🟣 Plan B:
                                {{
                                    item.played_from_outside
                                }}
                            </span>
                        </div>
                    </article>
                </section>
            </template>
        </div>
    </AppLayout>
</template>
