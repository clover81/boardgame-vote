<script setup lang="ts">
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

interface Stats {
    analysed_sessions: number;

    decisive_sessions: number;

    tie_sessions: number;

    winner_played: number;
    winner_played_percentage: number;

    other_candidate_played: number;
    other_candidate_percentage: number;

    outside_played: number;
    outside_percentage: number;
}

interface GameCount {
    game: Game;
}

interface DiscardedWinner
    extends GameCount {
    times_not_played: number;
}

interface OutsideChoice
    extends GameCount {
    times_chosen: number;
}

interface Decision {
    poll: {
        id: number;
        name: string;
    };

    played_at: string;

    decision_type:
        | 'winner'
        | 'other_candidate'
        | 'outside'
        | 'tie';

    has_clear_winner: boolean;

    winner:
        Game | null;

    tied_games:
        Game[];

    played_game:
        Game;

    expansion: {
        id: number;
        name: string;
    } | null;

    candidates_count:
        number;
}

defineProps<{
    stats: Stats;

    discarded_winners:
        DiscardedWinner[];

    outside_choices:
        OutsideChoice[];

    decisions:
        Decision[];
}>();

const formatDate = (
    date: string
): string => {
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

const decisionLabel = (
    type: Decision[
        'decision_type'
    ]
): string => {
    if (
        type === 'winner'
    ) {
        return 'Se jugó el ganador';
    }

    if (
        type ===
        'other_candidate'
    ) {
        return 'Otro candidato';
    }

    if (
        type === 'outside'
    ) {
        return 'Fuera de la votación';
    }

    return 'Empate';
};
</script>

<template>
    <Head
        title="Estadísticas de decisiones"
    />

    <AppLayout>
        <div
            class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6"
        >
            <!-- CABECERA -->

            <div>
                <p
                    class="font-semibold text-[#5200FF]"
                >
                    ESTADÍSTICAS
                </p>

                <h1
                    class="mt-1 text-2xl font-black text-zinc-900 dark:text-zinc-100 sm:text-3xl"
                >
                    ¿Votamos una cosa y
                    jugamos otra?
                </h1>

                <p
                    class="mt-2 max-w-3xl text-zinc-500 dark:text-zinc-400"
                >
                    Compara el resultado de
                    las votaciones con lo que
                    terminó saliendo realmente
                    a mesa.
                </p>
            </div>

            <div
                class="mt-6 flex flex-wrap gap-2"
            >
                <Link
                    href="/statistics/decisions"
                    class="rounded-xl bg-[#5200FF] px-4 py-2 font-bold text-white"
                >
                    Decisiones
                </Link>

                <Link
                    href="/statistics/games"
                    class="rounded-xl border border-zinc-300 px-4 py-2 font-bold text-zinc-600 transition hover:border-[#5200FF] dark:border-zinc-700 dark:text-zinc-300"
                >
                    Juegos
                </Link>
            </div>

            <!-- SIN DATOS -->

            <div
                v-if="
                    stats.analysed_sessions
                    === 0
                "
                class="mt-8 rounded-2xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700"
            >
                <p
                    class="text-lg font-bold text-zinc-900 dark:text-zinc-100"
                >
                    Todavía no hay decisiones
                    que podamos analizar.
                </p>

                <p
                    class="mt-2 text-sm text-zinc-500 dark:text-zinc-400"
                >
                    Cierra una votación y
                    registra después la partida
                    realmente jugada.
                </p>

                <Link
                    href="/polls"
                    class="mt-5 inline-block font-bold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
                >
                    Ir a votaciones →
                </Link>
            </div>

            <template v-else>
                <!-- MÉTRICAS -->

                <section
                    class="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                >
                    <!-- ANALIZADAS -->

                    <div
                        class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                    >
                        <p
                            class="text-sm font-bold uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                        >
                            Sesiones analizadas
                        </p>

                        <p
                            class="mt-3 text-3xl font-black sm:text-4xl text-zinc-900 dark:text-zinc-100"
                        >
                            {{
                                stats.analysed_sessions
                            }}
                        </p>

                        <p
                            v-if="
                                stats.tie_sessions
                            "
                            class="mt-2 text-sm text-zinc-500"
                        >
                            {{
                                stats.tie_sessions
                            }}
                            con empate
                        </p>
                    </div>

                    <!-- GANADOR -->

                    <div
                        class="rounded-2xl border border-[#CCFF00] bg-lime-50 p-6 dark:bg-lime-950/20"
                    >
                        <p
                            class="text-sm font-bold uppercase tracking-wide text-zinc-600 dark:text-zinc-300"
                        >
                            Se juega el ganador
                        </p>

                        <p
                            class="mt-3 text-3xl font-black sm:text-4xl text-zinc-900 dark:text-zinc-100"
                        >
                            {{
                                stats.winner_played_percentage
                            }}%
                        </p>

                        <p
                            class="mt-2 text-sm text-zinc-500 dark:text-zinc-400"
                        >
                            {{
                                stats.winner_played
                            }}
                            de
                            {{
                                stats.decisive_sessions
                            }}
                            votaciones con ganador
                            único
                        </p>
                    </div>

                    <!-- OTRO CANDIDATO -->

                    <div
                        class="rounded-2xl border border-orange-200 bg-orange-50 p-6 dark:border-orange-900 dark:bg-orange-950/20"
                    >
                        <p
                            class="text-sm font-bold uppercase tracking-wide text-zinc-600 dark:text-zinc-300"
                        >
                            Otro candidato
                        </p>

                        <p
                            class="mt-3 text-3xl font-black sm:text-4xl text-[#FF6B00]"
                        >
                            {{
                                stats.other_candidate_percentage
                            }}%
                        </p>

                        <p
                            class="mt-2 text-sm text-zinc-500 dark:text-zinc-400"
                        >
                            {{
                                stats.other_candidate_played
                            }}
                            sesiones
                        </p>
                    </div>

                    <!-- FUERA -->

                    <div
                        class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                    >
                        <p
                            class="text-sm font-bold uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                        >
                            Fuera de la votación
                        </p>

                        <p
                            class="mt-3 text-3xl font-black sm:text-4xl text-[#F900FF]"
                        >
                            {{
                                stats.outside_percentage
                            }}%
                        </p>

                        <p
                            class="mt-2 text-sm text-zinc-500 dark:text-zinc-400"
                        >
                            {{
                                stats.outside_played
                            }}
                            sesiones
                        </p>
                    </div>
                </section>

                <!-- BARRA RESUMEN -->

                <section
                    v-if="
                        stats.decisive_sessions
                    "
                    class="mt-8 rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <p
                        class="font-semibold text-[#5200FF]"
                    >
                        DECISIÓN FINAL
                    </p>

                    <h2
                        class="mt-1 text-xl font-bold text-zinc-900 dark:text-zinc-100"
                    >
                        De cada 100 votaciones
                        con ganador claro
                    </h2>

                    <div
                        class="mt-5 flex h-5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800"
                    >
                        <div
                            class="bg-[#CCFF00]"
                            :style="{
                                width:
                                    `${stats.winner_played_percentage}%`,
                            }"
                        />

                        <div
                            class="bg-[#FF6B00]"
                            :style="{
                                width:
                                    `${stats.other_candidate_percentage}%`,
                            }"
                        />

                        <div
                            class="bg-[#F900FF]"
                            :style="{
                                width:
                                    `${stats.outside_percentage}%`,
                            }"
                        />
                    </div>

                    <div
                        class="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-sm"
                    >
                        <span>
                            🟢 Ganador
                            {{
                                stats.winner_played_percentage
                            }}%
                        </span>

                        <span>
                            🟠 Otro candidato
                            {{
                                stats.other_candidate_percentage
                            }}%
                        </span>

                        <span>
                            🟣 Fuera
                            {{
                                stats.outside_percentage
                            }}%
                        </span>
                    </div>
                </section>

                <!-- RANKINGS -->

                <div
                    class="mt-8 grid gap-8 lg:grid-cols-2"
                >
                    <!-- GANADORES DESCARTADOS -->

                    <section
                        class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                    >
                        <p
                            class="font-semibold text-[#FF6B00]"
                        >
                            CAMBIO DE PLANES
                        </p>

                        <h2
                            class="mt-1 text-xl font-bold text-zinc-900 dark:text-zinc-100"
                        >
                            Ganadores que luego
                            no salieron
                        </h2>

                        <div
                            v-if="
                                discarded_winners.length
                            "
                            class="mt-5 space-y-3"
                        >
                            <Link
                                v-for="item in discarded_winners"
                                :key="
                                    item.game.id
                                "
                                :href="
                                    `/games/${item.game.id}`
                                "
                                class="flex items-center gap-4 rounded-xl border border-zinc-200 p-3 transition hover:border-[#5200FF] dark:border-zinc-800 dark:bg-zinc-900"
                            >
                                <div
                                    class="h-14 w-12 shrink-0 overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800"
                                >
                                    <img
                                        v-if="
                                            item.game.image_url
                                        "
                                        :src="
                                            item.game.image_url
                                        "
                                        :alt="
                                            item.game.name
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

                                <p
                                    class="min-w-0 flex-1 truncate font-bold text-zinc-900 dark:text-zinc-100"
                                >
                                    {{
                                        item.game.name
                                    }}
                                </p>

                                <div
                                    class="text-right"
                                >
                                    <p
                                        class="text-xl font-black text-[#FF6B00]"
                                    >
                                        {{
                                            item.times_not_played
                                        }}
                                    </p>

                                    <p
                                        class="text-xs text-zinc-400"
                                    >
                                        descartes
                                    </p>
                                </div>
                            </Link>
                        </div>

                        <p
                            v-else
                            class="mt-5 text-sm text-zinc-500"
                        >
                            De momento, cada
                            ganador terminó
                            jugándose.
                        </p>
                    </section>

                    <!-- FUERA DE VOTACIÓN -->

                    <section
                        class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                    >
                        <p
                            class="font-semibold text-[#F900FF]"
                        >
                            PLAN B
                        </p>

                        <h2
                            class="mt-1 text-xl font-bold text-zinc-900 dark:text-zinc-100"
                        >
                            Elegidos fuera de
                            la votación
                        </h2>

                        <div
                            v-if="
                                outside_choices.length
                            "
                            class="mt-5 space-y-3"
                        >
                            <Link
                                v-for="item in outside_choices"
                                :key="
                                    item.game.id
                                "
                                :href="
                                    `/games/${item.game.id}`
                                "
                                class="flex items-center gap-4 rounded-xl border border-zinc-200 p-3 transition hover:border-[#5200FF] dark:border-zinc-800 dark:bg-zinc-900"
                            >
                                <div
                                    class="h-14 w-12 shrink-0 overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800"
                                >
                                    <img
                                        v-if="
                                            item.game.image_url
                                        "
                                        :src="
                                            item.game.image_url
                                        "
                                        :alt="
                                            item.game.name
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

                                <p
                                    class="min-w-0 flex-1 truncate font-bold text-zinc-900 dark:text-zinc-100"
                                >
                                    {{
                                        item.game.name
                                    }}
                                </p>

                                <div
                                    class="text-right"
                                >
                                    <p
                                        class="text-xl font-black text-[#F900FF]"
                                    >
                                        {{
                                            item.times_chosen
                                        }}
                                    </p>

                                    <p
                                        class="text-xs text-zinc-400"
                                    >
                                        veces
                                    </p>
                                </div>
                            </Link>
                        </div>

                        <p
                            v-else
                            class="mt-5 text-sm text-zinc-500"
                        >
                            Nunca habéis acabado
                            jugando algo que no
                            estuviera entre los
                            candidatos.
                        </p>
                    </section>
                </div>

                <!-- HISTORIAL -->

                <section
                    class="mt-8 rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <p
                        class="font-semibold text-[#5200FF]"
                    >
                        HISTORIAL
                    </p>

                    <h2
                        class="mt-1 text-xl font-bold text-zinc-900 dark:text-zinc-100"
                    >
                        Decisiones recientes
                    </h2>

                    <div
                        class="mt-6 space-y-4"
                    >
                        <article
                            v-for="decision in decisions"
                            :key="
                                decision.poll.id
                            "
                            class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-800 dark:bg-zinc-900"
                        >
                            <div
                                class="flex flex-col gap-4 lg:flex-row lg:items-center"
                            >
                                <div
                                    class="min-w-0 flex-1"
                                >
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <Link
                                            :href="
                                                `/polls/${decision.poll.id}`
                                            "
                                            class="font-bold text-zinc-900 hover:text-[#5200FF] dark:text-zinc-100"
                                        >
                                            {{
                                                decision.poll.name
                                            }}
                                        </Link>

                                        <span
                                            class="rounded-full px-3 py-1 text-xs font-bold"
                                            :class="{
                                                'bg-[#CCFF00] text-black':
                                                    decision.decision_type ===
                                                    'winner',

                                                'bg-orange-100 text-orange-700 dark:bg-orange-950/30 dark:text-orange-300':
                                                    decision.decision_type ===
                                                    'other_candidate',

                                                'bg-fuchsia-100 text-fuchsia-700 dark:bg-fuchsia-950/30 dark:text-fuchsia-300':
                                                    decision.decision_type ===
                                                    'outside',

                                                'bg-zinc-200 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300':
                                                    decision.decision_type ===
                                                    'tie',
                                            }"
                                        >
                                            {{
                                                decisionLabel(
                                                    decision.decision_type
                                                )
                                            }}
                                        </span>
                                    </div>

                                    <p
                                        class="mt-2 text-sm text-zinc-500 dark:text-zinc-400"
                                    >
                                        {{
                                            formatDate(
                                                decision.played_at
                                            )
                                        }}
                                        ·
                                        {{
                                            decision.candidates_count
                                        }}
                                        candidatos
                                    </p>
                                </div>

                                <!-- GANADOR -->

                                <div
                                    class="lg:w-64"
                                >
                                    <p
                                        class="text-xs font-bold uppercase tracking-wide text-zinc-400"
                                    >
                                        {{
                                            decision.has_clear_winner
                                                ? 'Ganó la votación'
                                                : 'Resultado'
                                        }}
                                    </p>

                                    <p
                                        v-if="
                                            decision.winner
                                        "
                                        class="mt-1 font-bold text-zinc-800 dark:text-zinc-200"
                                    >
                                        {{
                                            decision.winner.name
                                        }}
                                    </p>

                                    <p
                                        v-else
                                        class="mt-1 text-sm font-semibold text-zinc-500"
                                    >
                                        Empate entre
                                        {{
                                            decision.tied_games
                                                .map(
                                                    game =>
                                                        game.name
                                                )
                                                .join(
                                                    ' · '
                                                )
                                        }}
                                    </p>
                                </div>

                                <!-- JUGADO -->

                                <div
                                    class="lg:w-64"
                                >
                                    <p
                                        class="text-xs font-bold uppercase tracking-wide text-zinc-400"
                                    >
                                        Finalmente
                                        jugado
                                    </p>

                                    <Link
                                        :href="
                                            `/games/${decision.played_game.id}`
                                        "
                                        class="mt-1 block font-black text-[#5200FF] hover:underline dark:text-[#CCFF00]"
                                    >
                                        {{
                                            decision.played_game.name
                                        }}
                                    </Link>

                                    <p
                                        v-if="
                                            decision.expansion
                                        "
                                        class="mt-1 text-xs text-zinc-500"
                                    >
                                        🧩
                                        {{
                                            decision.expansion.name
                                        }}
                                    </p>
                                </div>
                            </div>
                        </article>
                    </div>
                </section>
            </template>
        </div>
    </AppLayout>
</template>
