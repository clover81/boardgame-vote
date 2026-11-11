<script setup lang="ts">
import PencilSparklesIcon from '@/components/icons/PencilSparklesIcon.vue';
import { playerNameColor } from '@/lib/player-colors';
import MeepleIcon from '@/components/icons/MeepleIcon.vue';
import { BuildingComplexPlus, LibraryBig, Link as ChainIcon, CalendarHeart, UsersRound, HourglassCog, Swords, BrainCog, NotebookPen } from '@lucide/vue';

import PlayingCardsFanIcon from '@/components/icons/PlayingCardsFanIcon.vue';


import { computed, ref } from 'vue';
import {
    Head,
    Link,
    router,
    usePage,
} from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import ScrollToTopProgress from '@/components/ScrollToTopProgress.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/*
|--------------------------------------------------------------------------
| Tipos
|--------------------------------------------------------------------------
*/

interface Participant {
    id: number;
    display_name: string;
    vote_token: string | null;
    has_voted: boolean;
}

interface Expansion {
    id: number;
    name: string;
}

interface Collection {
    id: number;
    name: string;
}

interface Game {
    id: number;
    name: string;
    image_url: string | null;

    min_players: number;
    max_players: number;

    min_playtime: number | null;
    max_playtime: number | null;

    complexity: string | number | null;
    game_mode: string;

    short_description: string | null;

    ideal_player_counts: number[];
}

interface Candidate {
    id: number;
    note: string | null;

    /*
     * Expansión prevista para esta
     * votación concreta.
     */
    expansion: Expansion | null;

    game: Game;
}

interface Result {
    candidate_id: number;

    game: {
        id: number;
        name: string;
        image_url: string | null;
    };

    first_votes: number;
    second_votes: number;

    points: number;

    rank: number;

    is_winner: boolean;
    is_top_tie: boolean;
}

interface RegisteredPlayPlayer {
    id: number;
    name: string;
}

interface RegisteredPlay {
    id: number;
    played_at: string;
    game: {
        id: number;
        name: string;
    };
    expansion: {
        id: number;
        name: string;
    } | null;
    players: RegisteredPlayPlayer[];
}

interface RegisteredPlayDetails extends RegisteredPlay {
    id: number;
    played_at: string;
    game: {
        id: number;
        name: string;
        image_url: string | null;
    };
    expansion: {
        id: number;
        name: string;
    } | null;
    was_candidate: boolean;
    was_winner: boolean;
}

interface Poll {
    id: number;
    name: string;
    token: string;

    status:
        | 'draft'
        | 'open'
        | 'closed';

    has_clear_winner: boolean;

    participants_count: number;
    voted_participants_count: number;
    required_votes: number;
    candidate_count: number;
    required_vote_count: number;

    participants: Participant[];
    candidates: Candidate[];
    collections: Collection[];
    plays: RegisteredPlay[];
    registered_play: RegisteredPlayDetails | null;
}

const props = defineProps<{
    poll: Poll;
    results: Result[];
}>();

const page = usePage();

// Si BGG no sirve una imagen, mostramos la alternativa sin iconos rotos.
const failedGameImageIds = ref<number[]>([]);

const markGameImageFailed = (gameId: number) => {
    if (!failedGameImageIds.value.includes(gameId)) {
        failedGameImageIds.value.push(gameId);
    }
};

const pollError = computed(() => {
    const errors = page.props.errors as Record<string, string>;
    return errors.poll ?? errors.play ?? null;
});

const resultExpansion = (candidateId: number): Expansion | null => {
    return props.poll.candidates.find(
        (candidate) => candidate.id === candidateId
    )?.expansion ?? null;
};

const pendingParticipants = computed(() => {
    return props.poll.participants.filter(
        (participant) => !participant.has_voted
    );
});

const votePercentage = computed(() => {
    if (props.poll.participants_count === 0) {
        return 0;
    }

    return Math.round(
        (props.poll.voted_participants_count / props.poll.participants_count) * 100
    );
});

const canOpenPoll = computed(() => {
    return (
        props.poll.participants.length >= 2 &&
        props.poll.candidates.length >= 1
    );
});

/*
|--------------------------------------------------------------------------
| Abrir votación
|--------------------------------------------------------------------------
*/

const openPoll = () => {
    if (!canOpenPoll.value) {
        return;
    }

    router.patch(
        `/polls/${props.poll.id}/open`,
        {},
        {
            preserveScroll: true,
        }
    );
};

/*
|--------------------------------------------------------------------------
| Cerrar votación
|--------------------------------------------------------------------------
*/

const closePollDialogOpen = ref(false);
const closingPoll = ref(false);

const closePoll = () => {
    if (closingPoll.value || props.poll.status !== 'open') {
        return;
    }

    closePollDialogOpen.value = true;
};

const confirmClosePoll = () => {
    if (closingPoll.value || props.poll.status !== 'open') {
        return;
    }

    router.patch(
        `/polls/${props.poll.id}/close`,
        {},
        {
            preserveScroll: true,

            onStart: () => {
                closingPoll.value = true;
            },

            onSuccess: () => {
                closePollDialogOpen.value = false;
            },

            onFinish: () => {
                closingPoll.value = false;
            },
        },
    );
};

/*
|--------------------------------------------------------------------------
| Estado
|--------------------------------------------------------------------------
*/

const statusLabel = (
    status: Poll['status']
): string => {
    if (status === 'draft') {
        return 'Borrador';
    }

    if (status === 'open') {
        return 'Abierta';
    }

    return 'Cerrada';
};

/*
|--------------------------------------------------------------------------
| Tipo
|--------------------------------------------------------------------------
*/

const modeLabel = (
    mode: string
): string => {
    if (mode === 'competitive') {
        return 'Competitivo';
    }

    if (mode === 'cooperative') {
        return 'Cooperativo';
    }

    if (
        mode ===
        'semi_cooperative'
    ) {
        return 'Semicooperativo';
    }

    return mode;
};
</script>

<template>
    <Head :title="poll.name" />

    <AppLayout>
        <div
            class="mx-auto w-full max-w-6xl px-4 py-6 sm:px-6"
        >
            <!-- VOLVER -->

            <Link
                href="/polls"
                class="text-sm font-semibold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
            >
                ← Volver a votaciones
            </Link>

            <!-- ================================================== -->
            <!-- CABECERA                                           -->
            <!-- ================================================== -->

            <div
                class="mt-5 flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between"
            >
                <div>
                    <p
                        class="font-semibold text-[#5200FF]"
                    >
                        VOTACIÓN
                    </p>

                    <h1
                        class="mt-1 text-3xl font-bold text-zinc-900 dark:text-zinc-100"
                    >
                        {{ poll.name }}
                    </h1>

                    <div
                        class="mt-3 flex flex-wrap gap-3"
                    >
                        <!-- ESTADO -->

                        <span
                            class="rounded-full px-3 py-1 text-sm font-semibold"
                            :class="{
                                'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300':
                                    poll.status === 'draft',

                                'bg-[#CCFF00] text-black':
                                    poll.status === 'open',

                                'bg-purple-100 text-purple-700 dark:bg-purple-950 dark:text-purple-300':
                                    poll.status === 'closed',
                            }"
                        >
                            {{
                                statusLabel(
                                    poll.status
                                )
                            }}
                        </span>

                        <span
                            class="rounded-full bg-[#CCFF00] px-3 py-1 text-sm font-semibold text-black"
                        >
                            <UsersRound class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-black" :stroke-width="2.2" role="img" aria-label="Jugadores" />
                            {{
                                poll.participants
                                    .length
                            }}
                            jugadores
                        </span>

                        <span
                            class="rounded-full bg-zinc-100 px-3 py-1 text-sm font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                        >
                            <PlayingCardsFanIcon class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-violet-600 dark:text-violet-300" aria-hidden="true" />
                            {{
                                poll.candidates.length
                            }}
                            juegos
                        </span>
                    </div>

                    <div
                        class="mt-4 flex flex-wrap items-center gap-2"
                    >
                        <span
                            class="text-sm font-semibold text-zinc-500 dark:text-zinc-400"
                        >
                            <LibraryBig class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-violet-600 dark:text-violet-300" :stroke-width="2" aria-hidden="true" /> Colecciones:
                        </span>

                        <template
                            v-if="poll.collections.length"
                        >
                            <span
                                v-for="collection in poll.collections"
                                :key="collection.id"
                                class="rounded-full bg-violet-100 px-3 py-1 text-sm font-semibold text-[#5200FF] dark:bg-violet-950/40 dark:text-violet-300"
                            >
                                {{ collection.name }}
                            </span>
                        </template>

                        <span
                            v-else
                            class="text-sm text-zinc-400"
                        >
                            Sin colección registrada
                        </span>
                    </div>

                    <p
                        v-if="
                            poll.status === 'open'
                        "
                        class="mt-4 text-sm font-semibold text-zinc-600 dark:text-zinc-300"
                    >

                        {{
                            poll.voted_participants_count
                        }}
                        /
                        {{
                            poll.participants_count
                        }}
                        participantes han votado
                    </p>
                </div>

                <!-- ================================================== -->
                <!-- ACCIONES PRINCIPALES                               -->
                <!-- ================================================== -->

                <div
                    class="w-full sm:max-w-sm"
                >
                    <!-- BORRADOR -->

                    <div
                        v-if="poll.status === 'draft'"
                        class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-950"
                    >
                        <p
                            class="text-xs font-black uppercase tracking-wide text-zinc-400"
                        >
                            Siguiente paso
                        </p>

                        <h2
                            class="mt-1 text-lg font-black text-zinc-900 dark:text-zinc-100"
                        >
                            Abrir la votación
                        </h2>

                        <p
                            class="mt-2 text-sm leading-6 text-zinc-500 dark:text-zinc-400"
                        >
                            Cuando la abras, tus amigos
                            podrán acceder al enlace y
                            votar.
                        </p>

                        <button
                            type="button"
                            :disabled="!canOpenPoll"
                            class="mt-5 w-full rounded-xl bg-[#5200FF] px-5 py-3 font-black text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40"
                            @click="openPoll"
                        >
                            Abrir votación
                        </button>

                        <p
                            v-if="!canOpenPoll"
                            class="mt-3 text-sm font-semibold text-orange-600 dark:text-orange-300"
                        >
                            Necesitas al menos
                            2 jugadores y 1 juego.
                        </p>
                    </div>

                    <!-- ABIERTA -->

                    <div
                        v-if="poll.status === 'open'"
                        class="rounded-2xl border-2 border-[#CCFF00] bg-white p-5 dark:bg-zinc-950"
                    >
                        <div
                            class="flex items-center justify-between gap-3"
                        >
                            <div>


                                <h2
                                    class="mt-1 text-lg font-black text-zinc-900 dark:text-zinc-100"
                                >
                                    Votos recibidos
                                </h2>
                            </div>

                            <p
                                class="text-2xl font-black text-[#5200FF] dark:text-[#CCFF00]"
                            >
                                {{ votePercentage }}%
                            </p>
                        </div>

                        <!-- PROGRESO -->

                        <div
                            class="mt-4 h-3 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800"
                        >
                            <div
                                class="h-full rounded-full bg-[#CCFF00] transition-all"
                                :style="{
                                    width: `${votePercentage}%`,
                                }"
                            />
                        </div>

                        <p
                            class="mt-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300"
                        >
                            {{
                                poll.voted_participants_count
                            }}
                            de
                            {{
                                poll.participants_count
                            }}
                            han votado
                        </p>

                        <!-- ENLACES INDIVIDUALES -->

                        <div
                            class="mt-5 rounded-2xl border border-zinc-200 p-4 dark:border-zinc-800"
                        >
                            <p class="font-bold">
                                Enlaces individuales para votar
                            </p>

                            <p class="mt-1 text-sm text-zinc-500">
                                Envía a cada jugador únicamente su enlace.
                            </p>

                            <div class="mt-4 space-y-3">
                                <div
                                    v-for="participant in poll.participants"
                                    :key="participant.id"
                                    class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-900"
                                >
                                    <p class="font-semibold">
                                        <span :class="['inline-flex items-center gap-1.5', playerNameColor(participant.display_name)]"><MeepleIcon class="h-3.5 w-3.5 shrink-0" />{{ participant.display_name }}</span>
                                    </p>

                                    <a
                                        v-if="participant.vote_token"
                                        :href="`/vote/${poll.token}/${participant.vote_token}`"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="mt-1 block break-all text-sm font-semibold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
                                    >
                                        /vote/{{ poll.token }}/{{ participant.vote_token }} <ChainIcon class="ml-1 inline-block h-4 w-4 shrink-0 align-[-0.15em]" aria-hidden="true" />
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- CERRAR -->

                        <button
                            type="button"
                            class="mt-4 w-full rounded-xl border border-red-200 px-5 py-3 font-bold text-red-600 transition hover:bg-red-50 dark:border-red-900 dark:hover:bg-red-950/30"
                            @click="closePoll"
                        >
                            Cerrar votación
                        </button>
                    </div>

                    <!-- CERRADA -->

                    <div
                        v-if="poll.status === 'closed'"
                        class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-950"
                    >
                        <p
                            class="text-xs font-black uppercase tracking-wide text-[#5200FF]"
                        >
                            VOTACIÓN FINALIZADA
                        </p>

                        <h2
                            class="mt-1 text-lg font-black text-zinc-900 dark:text-zinc-100"
                        >
                            Resultados disponibles
                        </h2>

                        <p
                            class="mt-2 text-sm text-zinc-500 dark:text-zinc-400"
                        >
                            Puedes consultar los resultados más abajo
                            en esta página. Cada participante también
                            puede verlos desde su enlace individual.
                        </p>
                    </div>
                </div>
            </div>

            <div
                v-if="pollError"
                class="mt-5 rounded-2xl border border-red-200 bg-red-50 p-4 text-red-700 dark:border-red-900 dark:bg-red-950/20 dark:text-red-300"
            >
                <p class="font-bold">
                    No se ha podido realizar la acción
                </p>

                <p class="mt-1 text-sm">
                    {{ pollError }}
                </p>
            </div>

            <!-- ================================================== -->
            <!-- PARTICIPANTES                                      -->
            <!-- ================================================== -->

            <section class="mt-10">
                <h2
                    class="text-xl font-bold text-zinc-900 dark:text-zinc-100"
                >
                    Jugadores
                </h2>

                <div
                    v-if="
                        poll.participants.length
                    "
                    class="mt-4 flex flex-wrap gap-2"
                >
                    <span
                        v-for="participant in poll.participants"
                        :key="participant.id"
                        class="rounded-xl border px-4 py-2 font-medium"
                        :class="
                            participant.has_voted
                                ? 'border-green-300 bg-green-50 text-green-800 dark:border-green-900 dark:bg-green-950/30 dark:text-green-300'
                                : poll.status === 'open'
                                  ? 'border-orange-200 bg-orange-50 text-orange-700 dark:border-orange-900 dark:bg-orange-950/20 dark:text-orange-300'
                                  : 'border-zinc-200 text-zinc-600 dark:border-zinc-800 dark:text-zinc-300'
                        "
                    >
                        {{
                            participant.display_name
                        }}

                        <span
                            v-if="
                                participant.has_voted
                            "
                            class="ml-2 font-bold"
                        >
                            ✓ Votado
                        </span>

                        <span
                            v-else-if="poll.status === 'open'"
                            class="ml-2 text-xs font-semibold"
                        >
                            pendiente
                        </span>
                    </span>
                </div>
            </section>

            <!-- ================================================== -->
            <!-- CANDIDATOS                                         -->
            <!-- ================================================== -->

            <section class="mt-10">
                <h2
                    class="text-xl font-bold text-zinc-900 dark:text-zinc-100"
                >
                    Juegos candidatos
                </h2>

                <div
                    v-if="
                        poll.candidates.length
                    "
                    class="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <article
                        v-for="candidate in poll.candidates"
                        :key="candidate.id"
                        class="group overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm transition-all duration-200 motion-safe:hover:-translate-y-0.5 hover:border-violet-400 hover:shadow-lg hover:shadow-violet-500/10 focus-within:ring-2 focus-within:ring-violet-500/30 dark:border-zinc-800 dark:bg-zinc-950 dark:hover:border-violet-500"
                    >
                        <!-- PORTADA -->

                        <div
                            v-if="
                                candidate.game
                                    .image_url &&
                                !failedGameImageIds.includes(candidate.game.id)
                            "
                            class="flex aspect-[4/3] items-center justify-center bg-zinc-100 p-3 dark:bg-zinc-900"
                        >
                            <img
                                :src="
                                    candidate.game
                                        .image_url
                                "
                                :alt="
                                    candidate.game
                                        .name
                                "
                                class="h-full w-full object-contain transition-transform duration-300 motion-safe:group-hover:scale-[1.03]"
                                loading="lazy"
                                @error="markGameImageFailed(candidate.game.id)"
                            />
                        </div>

                        <div
                            v-else
                            class="flex aspect-[4/3] flex-col items-center justify-center gap-2 bg-zinc-100 text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400"
                        >
                            <span class="text-3xl" aria-hidden="true"><PlayingCardsFanIcon class="mr-1 inline-block size-8 shrink-0 align-[-0.14em] text-violet-600 dark:text-violet-300" aria-hidden="true" /></span>
                            <span class="text-sm font-medium">Sin portada</span>
                        </div>

                        <div class="p-5">
                            <h3 class="text-xl font-bold">
                                <Link
                                    :href="`/games/${candidate.game.id}`"
                                    class="rounded text-zinc-900 transition-colors hover:text-[#5200FF] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF] dark:text-zinc-100 dark:hover:text-violet-300"
                                >
                                    {{ candidate.game.name }}
                                </Link>
                            </h3>

                            <!-- IDEALES -->

                            <div
                                class="mt-3 flex flex-wrap gap-2"
                            >
                                <span
                                    v-for="number in candidate.game.ideal_player_counts"
                                    :key="number"
                                    class="rounded-full bg-[#CCFF00] px-3 py-1 text-xs font-bold text-black"
                                >
                                    Ideal a
                                    {{ number }}
                                </span>
                            </div>

                            <!-- DATOS -->

                            <div
                                class="mt-4 space-y-2 text-sm text-zinc-500 dark:text-zinc-400"
                            >
                                <p>
                                    <UsersRound class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-[#6D28D9] dark:text-[#C4B5FD]" :stroke-width="2.2" role="img" aria-label="Jugadores" />
                                    {{
                                        candidate.game
                                            .min_players
                                    }}–{{
                                        candidate.game
                                            .max_players
                                    }}
                                    jugadores
                                </p>

                                <p
                                    v-if="
                                        candidate.game
                                            .min_playtime !==
                                        null
                                    "
                                >
                                    <HourglassCog class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-[#1D4ED8] dark:text-[#CCFF00]" :stroke-width="2.2" role="img" aria-label="Duración" />
                                    {{
                                        candidate.game
                                            .min_playtime
                                    }}

                                    <template
                                        v-if="
                                            candidate.game
                                                .max_playtime !==
                                                null &&
                                            candidate.game
                                                .max_playtime !==
                                                candidate.game
                                                    .min_playtime
                                        "
                                    >
                                        –{{
                                            candidate
                                                .game
                                                .max_playtime
                                        }}
                                    </template>

                                    min
                                </p>

                                <p
                                    v-if="
                                        candidate.game
                                            .complexity !==
                                        null
                                    "
                                >
                                    <BrainCog class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-[#BE185D] dark:text-[#F9A8D4]" :stroke-width="2.2" role="img" aria-label="Complejidad" />
                                    {{
                                        candidate.game
                                            .complexity
                                    }}
                                    / 5
                                </p>

                                <p>
                                    <Swords v-if="candidate.game.game_mode === 'competitive'" class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-orange-500 dark:text-orange-300" :stroke-width="2.2" aria-hidden="true" />
                                    {{
                                        modeLabel(
                                            candidate
                                                .game
                                                .game_mode
                                        )
                                    }}
                                </p>
                            </div>

                            <!-- ================================== -->
                            <!-- EXPANSIÓN PREVISTA                 -->
                            <!-- ================================== -->

                            <div
                                v-if="
                                    candidate.expansion
                                "
                                class="mt-5 rounded-xl border border-violet-200 bg-violet-50 p-4 dark:border-violet-900 dark:bg-violet-950/30"
                            >
                                <p
                                    class="text-sm font-bold text-[#5200FF] dark:text-violet-300"
                                >
                                    <BuildingComplexPlus class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-[#6D28D9] dark:text-[#CCFF00]" :stroke-width="2" aria-hidden="true" /> Expansión prevista
                                </p>

                                <p
                                    class="mt-1 font-semibold text-zinc-800 dark:text-zinc-100"
                                >
                                    {{
                                        candidate
                                            .expansion
                                            .name
                                    }}
                                </p>
                            </div>

                            <!-- NOTA -->

                            <div
                                v-if="
                                    candidate.note
                                "
                                class="mt-4 rounded-xl bg-zinc-100 p-4 text-sm dark:bg-zinc-900"
                            >
                                <p class="flex items-center gap-2 font-bold">
                                    <NotebookPen class="size-5 shrink-0 text-[#CCFF00]" aria-hidden="true" />
                                    <span>Para esta partida</span>
                                </p>

                                <p
                                    class="mt-1 text-zinc-600 dark:text-zinc-300"
                                >
                                    {{
                                        candidate.note
                                    }}
                                </p>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <!-- ================================================== -->
            <!-- RESULTADOS                                         -->
            <!-- ================================================== -->

            <section
                v-if="
                    poll.status === 'closed'
                "
                class="mt-12"
            >
                <div class="mb-6">
                    <p
                        class="font-semibold text-[#5200FF]"
                    >
                        RESULTADOS
                    </p>

                    <h2
                        class="mt-1 text-2xl font-bold text-zinc-900 dark:text-zinc-100"
                    >
                        Clasificación final
                    </h2>

                    <p
                        class="mt-2 text-sm text-zinc-500 dark:text-zinc-400"
                    >
                        Primera opción: 2 puntos.
                        Segunda opción: 1 punto.
                        En caso de empate se
                        priorizan las primeras
                        opciones.
                    </p>
                </div>

                <div
                    v-if="results.length && poll.status === 'closed'"
                    class="mt-5 mb-5"
                >
                    <div
                        v-if="poll.has_clear_winner"
                        class="rounded-2xl bg-[#CCFF00] p-5 text-black"
                    >
                        <p class="text-xs font-black uppercase">
                            Ganador
                        </p>

                        <p class="mt-1 text-xl font-black">
                            🏆
                            {{ results[0].game.name }}
                        </p>
                    </div>

                    <div
                        v-else
                        class="rounded-2xl bg-orange-100 p-5 text-orange-900 dark:bg-orange-950/30 dark:text-orange-200"
                    >
                        <p class="font-black">
                            🤝 Empate en primera posición
                        </p>

                        <p class="mt-1 text-sm">
                            Hay varios juegos con los
                            mismos puntos y el mismo número
                            de primeras opciones.
                        </p>
                    </div>
                </div>

                <div
                    v-if="results.length"
                    class="space-y-4"
                >
                    <article
                        v-for="result in results"
                        :key="
                            result.candidate_id
                        "
                        class="group flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm transition-all duration-200 motion-safe:hover:-translate-y-0.5 hover:border-violet-400 hover:shadow-lg hover:shadow-violet-500/10 focus-within:ring-2 focus-within:ring-violet-500/30 sm:flex-row sm:items-center dark:border-zinc-800 dark:bg-zinc-950 dark:hover:border-violet-500"
                    >
                        <!-- POSICIÓN -->

                        <div
                            class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full text-xl font-black"
                            :class="{
                                'bg-[#CCFF00] text-black':
                                    result.is_winner,

                                'bg-orange-100 text-orange-800':
                                    result.is_top_tie,

                                'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200':
                                    !result.is_winner &&
                                    !result.is_top_tie,
                            }"
                        >
                            <span v-if="result.is_winner">
                                🥇
                            </span>

                            <span v-else-if="result.is_top_tie">
                                🤝
                            </span>

                            <span v-else>
                                {{ result.rank }}
                            </span>
                        </div>

                        <!-- PORTADA -->

                        <div
                            class="h-24 w-24 shrink-0 overflow-hidden rounded-xl bg-zinc-100 dark:bg-zinc-900"
                        >
                            <img
                                v-if="
                                    result.game.image_url &&
                                    !failedGameImageIds.includes(result.game.id)
                                "
                                :src="
                                    result.game.image_url
                                "
                                :alt="
                                    result.game.name
                                "
                                class="h-full w-full object-contain p-1 transition-transform duration-300 motion-safe:group-hover:scale-[1.04]"
                                loading="lazy"
                                @error="markGameImageFailed(result.game.id)"
                            />

                            <div
                                v-else
                                class="flex h-full flex-col items-center justify-center gap-1 text-xs text-zinc-500 dark:text-zinc-400"
                            >
                                <span aria-hidden="true"><PlayingCardsFanIcon class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-violet-600 dark:text-violet-300" aria-hidden="true" /></span>
                                <span>Sin portada</span>
                            </div>
                        </div>

                        <!-- INFO -->

                        <div class="flex-1">
                            <h3 class="text-lg font-bold">
                                <Link
                                    :href="`/games/${result.game.id}`"
                                    class="rounded text-zinc-900 transition-colors hover:text-[#5200FF] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF] dark:text-zinc-100 dark:hover:text-violet-300"
                                >
                                    {{ result.game.name }}
                                </Link>
                            </h3>

                            <p
                                v-if="
                                    resultExpansion(result.candidate_id)
                                "
                                class="mt-1 text-sm font-semibold text-[#5200FF] dark:text-violet-300"
                            >
                                <BuildingComplexPlus class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-[#6D28D9] dark:text-[#CCFF00]" :stroke-width="2" aria-hidden="true" />
                                {{
                                    resultExpansion(result.candidate_id)?.name
                                }}
                            </p>

                            <p
                                class="mt-2 text-sm text-zinc-500 dark:text-zinc-400"
                            >
                                🥇
                                {{
                                    result.first_votes
                                }}
                                primeras
                                ·
                                🥈
                                {{
                                    result.second_votes
                                }}
                                segundas
                            </p>
                        </div>

                        <!-- PUNTOS -->

                        <div
                            class="sm:text-right"
                        >
                            <p
                                class="text-3xl font-black text-[#5200FF] dark:text-[#CCFF00]"
                            >
                                {{
                                    result.points
                                }}
                            </p>

                            <p
                                class="text-xs font-semibold text-zinc-500"
                            >
                                puntos
                            </p>
                        </div>
                    </article>
                </div>

                <!-- PARTIDAS REALMENTE JUGADAS -->

                <div
                    v-if="poll.plays.length"
                    class="mt-6 rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <p class="text-sm font-bold uppercase tracking-wide text-[#5200FF]">
                        <PlayingCardsFanIcon class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-violet-600 dark:text-violet-300" aria-hidden="true" />
                        {{
                            poll.plays.length === 1
                                ? 'Partida registrada'
                                : 'Partidas registradas'
                        }}
                    </p>

                    <div class="mt-4 space-y-3">
                        <div
                            v-for="play in poll.plays"
                            :key="play.id"
                            class="rounded-xl bg-zinc-50 p-4 dark:bg-zinc-900"
                        >
                            <Link
                                :href="`/games/${play.game.id}`"
                                class="font-bold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
                            >
                                {{ play.game.name }} →
                            </Link>

                            <p
                                v-if="play.expansion"
                                class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"
                            >
                                <BuildingComplexPlus class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-[#6D28D9] dark:text-[#CCFF00]" :stroke-width="2" aria-hidden="true" /> {{ play.expansion.name }}
                            </p>

                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                <CalendarHeart class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-lime-700 dark:text-[#CCFF00]" :stroke-width="2.2" aria-hidden="true" /> {{ play.played_at }}
                            </p>

                            <p
                                v-if="play.players.length"
                                class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"
                            >
                                <UsersRound class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-[#6D28D9] dark:text-[#C4B5FD]" :stroke-width="2.2" role="img" aria-label="Jugadores" />
                                {{
                                    play.players
                                        .map(player => player.name)
                                        .join(', ')
                                }}
                            </p>

                            <Link
                                :href="`/plays/${play.id}/edit`"
                                class="mt-3 inline-block text-sm font-semibold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
                            >
                                <span class="inline-flex items-center justify-center gap-1.5"><PencilSparklesIcon class="h-4 w-4 shrink-0 text-violet-500 dark:text-[#CCFF00]" />Editar registro</span>
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- TODAVÍA NO REGISTRADA -->

                <div
                    v-else
                    class="mt-8"
                >
                    <Link
                        :href="`/polls/${poll.id}/register-play`"
                        class="inline-flex rounded-xl bg-[#5200FF] px-6 py-3 font-bold text-white"
                    >
                        <PlayingCardsFanIcon class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-violet-600 dark:text-violet-300" aria-hidden="true" /> Registrar partida realmente jugada
                    </Link>
                </div>
            </section>
            <ScrollToTopProgress />
        </div>
        <Dialog v-model:open="closePollDialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <div
                        class="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-purple-100 text-2xl dark:bg-purple-950"
                        aria-hidden="true"
                    >
                        🗳️
                    </div>

                    <DialogTitle class="text-xl font-black">
                        ¿Cerrar la votación?
                    </DialogTitle>

                    <DialogDescription class="pt-2 text-sm leading-6">
                        Confirma que quieres finalizar la votación
                        y mostrar sus resultados.
                    </DialogDescription>
                </DialogHeader>

                <!-- TODAVÍA QUEDAN PERSONAS POR VOTAR -->
                <div
                    v-if="pendingParticipants.length > 0"
                    class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
                >
                    <p class="font-bold">
                        ⚠️ Hay votos pendientes
                    </p>

                    <p class="mt-2">
                        Todavía
                        {{
                            pendingParticipants.length === 1
                                ? 'falta 1 persona'
                                : `faltan ${pendingParticipants.length} personas`
                        }}
                        por votar.
                    </p>

                    <p class="mt-2">
                        Si cierras ahora la votación, esas personas
                        ya no podrán participar.
                    </p>
                </div>

                <!-- TODOS HAN VOTADO -->
                <div
                    v-else
                    class="rounded-xl border border-purple-200 bg-purple-50 p-4 text-sm leading-6 text-purple-900 dark:border-purple-900 dark:bg-purple-950/40 dark:text-purple-200"
                >
                    <p class="font-bold">
                        ✓ ¡Todos han votado!
                    </p>

                    <p class="mt-2">
                        Puedes cerrar la votación para conocer
                        los resultados.
                    </p>
                </div>

                <DialogFooter class="mt-3 gap-2 sm:gap-3">
                    <button
                        type="button"
                        :disabled="closingPoll"
                        class="rounded-xl border border-zinc-300 px-5 py-3 font-semibold transition hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800 disabled:opacity-50"
                        @click="closePollDialogOpen = false"
                    >
                        Cancelar
                    </button>

                    <button
                        type="button"
                        :disabled="closingPoll"
                        class="rounded-xl bg-[#5200FF] px-5 py-3 font-bold text-white transition hover:bg-[#4000cc] disabled:cursor-not-allowed disabled:opacity-50"
                        @click="confirmClosePoll"
                    >
                        {{ closingPoll ? 'Cerrando...' : 'Cerrar votación' }}
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
