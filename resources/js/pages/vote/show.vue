<script setup lang="ts">
import {
    computed,
    ref,
    watch,
} from 'vue';

import {
    Head,
    useForm,
} from '@inertiajs/vue3';

import AlertError from '@/components/AlertError.vue';

/*
|--------------------------------------------------------------------------
| Tipos
|--------------------------------------------------------------------------
*/

interface Vote {
    candidate_id: number;
    position: number;
}

interface Participant {
    id: number;
    display_name: string;
    votes: Vote[];
}

interface Expansion {
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

    bgg_url: string | null;
    youtube_url: string | null;

    ideal_player_counts: number[];
}

interface CandidateHistoryParticipant {
    participant_id: number;
    display_name: string;

    play_count: number;

    tracked: boolean;
}

interface CandidateHistory {
    total_plays: number;

    last_played_at:
        string | null;

    participants:
        CandidateHistoryParticipant[];
}

interface Candidate {
    id: number;
    note: string | null;

    expansion:
        Expansion | null;

    game: Game;

    history: CandidateHistory;
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

interface Poll {
    id: number;
    name: string;
    token: string;

    status:
        | 'open'
        | 'closed';

    has_clear_winner: boolean;

    participants: Participant[];
    candidates: Candidate[];
}

const props = defineProps<{
    poll: Poll;
    results: Result[];
    currentParticipantId: number;
    voteToken: string;
}>();

const resultExpansion = (candidateId: number): Expansion | null => {
    return props.poll.candidates.find(
        (candidate) => candidate.id === candidateId
    )?.expansion ?? null;
};

/*
|--------------------------------------------------------------------------
| Participante
|--------------------------------------------------------------------------
*/

const selectedParticipantId =
    ref<number>(props.currentParticipantId);

const selectedParticipant =
    computed(() =>
        props.poll.participants.find(
            (participant) =>
                participant.id ===
                selectedParticipantId.value
        )
    );

const voteSaved = ref(false);

const singleCandidate = computed(
    () =>
        props.poll.candidates.length === 1
);

const selectedFirstGame = computed(() => {
    if (!form.first_candidate_id) {
        return null;
    }

    return props.poll.candidates.find(
        (candidate) => candidate.id === form.first_candidate_id
    )?.game ?? null;
});

const selectedSecondGame = computed(() => {
    if (!form.second_candidate_id) {
        return null;
    }

    return props.poll.candidates.find(
        (candidate) => candidate.id === form.second_candidate_id
    )?.game ?? null;
});

const hasPreviousVote = computed(() => {
    return (selectedParticipant.value?.votes.length ?? 0) >= requiredVoteCount.value;
});

/*
|--------------------------------------------------------------------------
| Formulario
|--------------------------------------------------------------------------
*/

const form = useForm({
    participant_id:
        null as number | null,

    first_candidate_id:
        null as number | null,

    second_candidate_id:
        null as number | null,
});

const needsSecondChoice = computed(() => {
    return props.poll.candidates.length >= 2;
});

const canSubmit = computed(() => {
    if (
        form.participant_id === null ||
        form.first_candidate_id === null
    ) {
        return false;
    }

    if (!needsSecondChoice.value) {
        return true;
    }

    return (
        form.second_candidate_id !== null &&
        form.second_candidate_id !==
            form.first_candidate_id
    );
});

const requiredVoteCount = computed(
    () => needsSecondChoice.value ? 2 : 1
);

/*
|--------------------------------------------------------------------------
| Recuperar voto anterior
|--------------------------------------------------------------------------
*/

watch(
    selectedParticipantId,
    () => {
        voteSaved.value = false;

        form.participant_id =
            selectedParticipantId.value;

        form.first_candidate_id =
            null;

        form.second_candidate_id =
            null;

        const participant =
            selectedParticipant.value;

        if (!participant) {
            return;
        }

        for (
            const vote of participant.votes
        ) {
            if (
                vote.position === 1
            ) {
                form.first_candidate_id =
                    vote.candidate_id;
            }

            if (
                vote.position === 2
            ) {
                form.second_candidate_id =
                    vote.candidate_id;
            }
        }

        /*
         * Si solo existe un candidato,
         * lo seleccionamos directamente.
         */
        if (
            !form.first_candidate_id &&
            props.poll.candidates.length === 1
        ) {
            form.first_candidate_id =
                props.poll.candidates[0].id;
        }
    },
    { immediate: true }
);

/*
|--------------------------------------------------------------------------
| Primera opción
|--------------------------------------------------------------------------
*/

const selectFirst = (
    candidateId: number
) => {
    if (
        form.second_candidate_id ===
        candidateId
    ) {
        form.second_candidate_id =
            null;
    }

    form.first_candidate_id =
        candidateId;
};

/*
|--------------------------------------------------------------------------
| Segunda opción
|--------------------------------------------------------------------------
*/

const selectSecond = (
    candidateId: number
) => {
    if (
        form.first_candidate_id ===
        candidateId
    ) {
        form.first_candidate_id =
            null;
    }

    form.second_candidate_id =
        candidateId;
};

/*
|--------------------------------------------------------------------------
| Enviar
|--------------------------------------------------------------------------
*/

const submit = () => {
    if (!canSubmit.value) {
        return;
    }

    form.post(
        `/vote/${props.poll.token}/${props.voteToken}`,
        {
            preserveScroll: true,

            onSuccess: () => {
                voteSaved.value = true;

                window.scrollTo({
                    top: 0,
                    behavior: 'smooth',
                });
            },
        }
    );
};

/*
|--------------------------------------------------------------------------
| Tipo
|--------------------------------------------------------------------------
*/

const modeLabel = (
    mode: string
): string => {
    if (
        mode === 'competitive'
    ) {
        return 'Competitivo';
    }

    if (
        mode === 'cooperative'
    ) {
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

/*
|--------------------------------------------------------------------------
| Fecha
|--------------------------------------------------------------------------
*/

const formatDate = (
    date: string | null
): string => {
    if (!date) {
        return '';
    }

    return new Intl.DateTimeFormat(
        'es-ES',
        {
            day: 'numeric',
            month: 'long',
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
    <Head :title="poll.name" />

    <main
        class="min-h-screen bg-zinc-50 text-zinc-900"
    >
        <!-- ===================================================== -->
        <!-- CABECERA                                              -->
        <!-- ===================================================== -->

        <header
            class="bg-[#5200FF] px-5 py-8 text-white"
        >
            <div
                class="mx-auto max-w-3xl"
            >
                <p
                    class="text-sm font-bold uppercase tracking-wider text-[#CCFF00]"
                >
                    ¿A qué jugamos?
                </p>

                <h1
                    class="mt-2 text-3xl font-black"
                >
                    {{ poll.name }}
                </h1>

                <p
                    class="mt-2 text-violet-100"
                >
                    <template v-if="poll.status === 'open'">
                        {{
                            needsSecondChoice
                                ? 'Elige tu primera y segunda opción.'
                                : 'Confirma tu voto para este juego.'
                        }}
                    </template>

                    <template v-else>
                        La votación ha terminado.
                    </template>
                </p>
            </div>
        </header>

        <div
            class="mx-auto max-w-3xl px-4 py-8"
        >
            <AlertError
                v-if="Object.keys(form.errors).length"
                class="mb-6"
                title="No hemos podido guardar tu voto"
                :errors="Object.values(form.errors)"
            />

            <!-- ================================================= -->
            <!-- RESULTADOS                                        -->
            <!-- ================================================= -->

            <section
                v-if="
                    poll.status ===
                    'closed'
                "
            >
                <p
                    class="font-bold uppercase tracking-wide text-[#5200FF]"
                >
                    RESULTADOS
                </p>

                <h2
                    class="mt-1 text-3xl font-black"
                >
                    Resultado de la votación
                </h2>

                <p
                    class="mt-2 text-zinc-500"
                >
                    {{
                        needsSecondChoice
                            ? 'Primera opción: 2 puntos · Segunda opción: 1 punto'
                            : 'Cada voto vale 2 puntos'
                    }}
                </p>

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
                    v-if="
                        results.length
                    "
                    class="mt-7 space-y-4"
                >
                    <article
                        v-for="result in results"
                        :key="
                            result.candidate_id
                        "
                        class="flex items-center gap-4 rounded-2xl border bg-white p-5 shadow-sm"
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

                        <img
                            v-if="
                                result.game.image_url
                            "
                            :src="
                                result.game.image_url
                            "
                            :alt="
                                result.game.name
                            "
                            class="h-20 w-16 rounded-lg object-cover"
                        />

                        <!-- INFO -->

                        <div
                            class="min-w-0 flex-1"
                        >
                            <h3
                                class="text-lg font-bold"
                            >
                                {{
                                    result.game.name
                                }}
                            </h3>

                            <p
                                v-if="
                                    resultExpansion(result.candidate_id)
                                "
                                class="mt-1 text-sm font-bold text-[#5200FF]"
                            >
                                🧩
                                {{
                                    resultExpansion(result.candidate_id)?.name
                                }}
                            </p>

                            <p
                                class="mt-1 text-sm text-zinc-500"
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
                            class="text-right"
                        >
                            <p
                                class="text-3xl font-black text-[#5200FF]"
                            >
                                {{
                                    result.points
                                }}
                            </p>

                            <p
                                class="text-xs text-zinc-500"
                            >
                                puntos
                            </p>
                        </div>
                    </article>
                </div>

                <div
                    v-else
                    class="mt-7 rounded-2xl border border-dashed p-6 text-center text-zinc-500"
                >
                    No hay votos registrados.
                </div>
            </section>

            <section
                v-if="poll.status === 'open' && voteSaved && selectedParticipant"
                class="rounded-3xl border border-green-200 bg-white p-6 text-center shadow-sm"
            >
                <div
                    class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-[#CCFF00] text-3xl"
                >
                    ✓
                </div>

                <h2
                    class="mt-5 text-2xl font-black"
                >
                    ¡Voto guardado!
                </h2>

                <p class="mt-2 text-zinc-500">
                    Gracias,
                    <strong>
                        {{ selectedParticipant.display_name }}
                    </strong>.
                </p>

                <div
                    class="mt-6 grid gap-3 text-left"
                    :class="{ 'sm:grid-cols-2': !singleCandidate }"
                >
                    <div
                        class="rounded-2xl bg-lime-50 p-4"
                    >
                        <p
                            class="text-xs font-black uppercase text-zinc-500"
                        >
                            🥇 Primera opción
                        </p>

                        <p class="mt-1 font-black">
                            {{ selectedFirstGame?.name }}
                        </p>
                    </div>

                    <div
                        v-if="!singleCandidate"
                        class="rounded-2xl bg-orange-50 p-4"
                    >
                        <p
                            class="text-xs font-black uppercase text-zinc-500"
                        >
                            🥈 Segunda opción
                        </p>

                        <p class="mt-1 font-black">
                            {{ selectedSecondGame?.name }}
                        </p>
                    </div>
                </div>

                <div
                    class="mt-6 grid gap-3"
                >
                    <button
                        type="button"
                        class="rounded-xl border border-zinc-300 px-4 py-3 font-bold"
                        @click="voteSaved = false"
                    >
                        Modificar mis votos
                    </button>

                </div>
            </section>

            <!-- ================================================= -->
            <!-- VOTACIÓN                                          -->
            <!-- ================================================= -->

            <template
                v-if="
                    poll.status ===
                        'open' &&
                    !voteSaved
                "
            >
                <!-- PARTICIPANTE -->

                <div
                    class="mb-6 flex items-center justify-between"
                >
                    <div>
                        <p
                            class="text-sm text-zinc-500"
                        >
                            Estás votando como
                        </p>

                        <p class="font-bold">
                            {{
                                selectedParticipant
                                    ?.display_name
                            }}
                        </p>

                        <p
                            v-if="hasPreviousVote"
                            class="mt-1 text-xs font-semibold text-orange-600"
                        >
                            Estás modificando tus votos anteriores.
                        </p>
                    </div>

                </div>

                <!-- JUEGOS -->

                <div class="space-y-6">
                    <article
                        v-for="candidate in poll.candidates"
                        :key="candidate.id"
                        class="overflow-hidden rounded-3xl border bg-white shadow-sm"
                        :class="{
                            'border-[#CCFF00] ring-4 ring-[#CCFF00]':
                                form.first_candidate_id ===
                                candidate.id,

                            'border-[#FF6B00] ring-4 ring-[#FF6B00]':
                                form.second_candidate_id ===
                                candidate.id,
                        }"
                    >
                        <!-- PORTADA -->

                        <img
                            v-if="
                                candidate.game
                                    .image_url
                            "
                            :src="
                                candidate.game
                                    .image_url
                            "
                            :alt="
                                candidate.game
                                    .name
                            "
                            class="aspect-[16/10] w-full object-cover sm:aspect-[16/9]"
                        />

                        <div class="p-5">
                            <div
                                v-if="
                                    form.first_candidate_id === candidate.id ||
                                    form.second_candidate_id === candidate.id
                                "
                                class="mb-3"
                            >
                                <span
                                    v-if="form.first_candidate_id === candidate.id"
                                    class="rounded-full bg-[#CCFF00] px-3 py-1 text-xs font-black text-black"
                                >
                                    🥇 TU PRIMERA OPCIÓN
                                </span>

                                <span
                                    v-if="form.second_candidate_id === candidate.id"
                                    class="rounded-full bg-[#FF6B00] px-3 py-1 text-xs font-black text-black"
                                >
                                    🥈 TU SEGUNDA OPCIÓN
                                </span>
                            </div>

                            <!-- NOMBRE -->

                            <h2
                                class="text-2xl font-black"
                            >
                                {{
                                    candidate.game
                                        .name
                                }}
                            </h2>

                            <!-- IDEALES -->

                            <div
                                class="mt-3 flex flex-wrap gap-2"
                            >
                                <span
                                    v-for="number in candidate.game.ideal_player_counts"
                                    :key="number"
                                    class="rounded-full bg-[#CCFF00] px-3 py-1 text-sm font-bold text-black"
                                >
                                    Ideal a
                                    {{ number }}
                                </span>
                            </div>

                            <!-- DATOS -->

                            <div
                                class="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-sm text-zinc-600"
                            >
                                <span>
                                    👥
                                    {{
                                        candidate
                                            .game
                                            .min_players
                                    }}–{{
                                        candidate
                                            .game
                                            .max_players
                                    }}
                                </span>

                                <span
                                    v-if="
                                        candidate
                                            .game
                                            .min_playtime
                                    "
                                >
                                    ⏱
                                    {{
                                        candidate
                                            .game
                                            .min_playtime
                                    }}

                                    <template
                                        v-if="
                                            candidate
                                                .game
                                                .max_playtime &&
                                            candidate
                                                .game
                                                .max_playtime !==
                                                candidate
                                                    .game
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
                                </span>

                                <span
                                    v-if="
                                        candidate
                                            .game
                                            .complexity !==
                                        null
                                    "
                                >
                                    🧠
                                    {{
                                        candidate
                                            .game
                                            .complexity
                                    }}/5
                                </span>

                                <span>
                                    🎲
                                    {{
                                        modeLabel(
                                            candidate
                                                .game
                                                .game_mode
                                        )
                                    }}
                                </span>
                            </div>

                            <!-- DESCRIPCIÓN -->

                            <p
                                v-if="
                                    candidate.game
                                        .short_description
                                "
                                class="mt-5 leading-7 text-zinc-600"
                            >
                                {{
                                    candidate.game
                                        .short_description
                                }}
                            </p>

                            <!-- ============================== -->
                            <!-- EXPANSIÓN PREVISTA             -->
                            <!-- ============================== -->

                            <div
                                v-if="
                                    candidate.expansion
                                "
                                class="mt-5 rounded-2xl border border-violet-200 bg-violet-50 p-4"
                            >
                                <p
                                    class="font-bold text-[#5200FF]"
                                >
                                    🧩 Para esta partida
                                </p>

                                <p
                                    class="mt-1 font-semibold"
                                >
                                    Se propone jugar con
                                    {{
                                        candidate
                                            .expansion
                                            .name
                                    }}
                                </p>

                                <p
                                    class="mt-1 text-xs text-zinc-500"
                                >
                                    La expansión es
                                    información de la
                                    partida, no una opción
                                    de voto independiente.
                                </p>
                            </div>

                            <!-- ============================== -->
                            <!-- HISTORIAL                      -->
                            <!-- ============================== -->

                            <div
                                class="mt-5 rounded-2xl border bg-zinc-50 p-4"
                            >
                                <p
                                    class="font-bold"
                                >
                                    📚 Historial contigo
                                </p>

                                <!-- SIN PARTIDAS -->

                                <p
                                    v-if="
                                        candidate.history
                                            .total_plays ===
                                        0
                                    "
                                    class="mt-2 text-sm text-zinc-500"
                                >
                                    Todavía no has
                                    registrado ninguna
                                    partida de este juego.
                                </p>

                                <!-- CON PARTIDAS -->

                                <div
                                    v-else
                                    class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm"
                                >
                                    <span
                                        class="font-semibold text-[#5200FF]"
                                    >
                                        🎲
                                        {{
                                            candidate
                                                .history
                                                .total_plays
                                        }}
                                        {{
                                            candidate
                                                .history
                                                .total_plays ===
                                            1
                                                ? 'partida registrada'
                                                : 'partidas registradas'
                                        }}
                                    </span>

                                    <span
                                        v-if="
                                            candidate
                                                .history
                                                .last_played_at
                                        "
                                        class="text-zinc-500"
                                    >
                                        📅 Última:
                                        {{
                                            formatDate(
                                                candidate
                                                    .history
                                                    .last_played_at
                                            )
                                        }}
                                    </span>
                                </div>

                                <!-- JUGADORES DE HOY -->

                                <div
                                    class="mt-4 border-t pt-3"
                                >
                                    <p
                                        class="text-xs font-bold uppercase tracking-wide text-zinc-500"
                                    >
                                        Jugadores de hoy
                                    </p>

                                    <div
                                        class="mt-3 space-y-2"
                                    >
                                        <div
                                            v-for="participant in candidate.history.participants"
                                            :key="
                                                participant.participant_id
                                            "
                                            class="flex items-center justify-between gap-3 text-sm"
                                        >
                                            <span>
                                                👤
                                                {{
                                                    participant
                                                        .display_name
                                                }}
                                            </span>

                                            <span
                                                v-if="
                                                    !participant
                                                        .tracked
                                                "
                                                class="text-zinc-400"
                                            >
                                                Sin historial
                                            </span>

                                            <span
                                                v-else-if="
                                                    participant
                                                        .play_count ===
                                                    0
                                                "
                                                class="text-zinc-400"
                                            >
                                                Nunca
                                            </span>

                                            <span
                                                v-else
                                                class="font-semibold"
                                            >
                                                {{
                                                    participant
                                                        .play_count
                                                }}
                                                {{
                                                    participant
                                                        .play_count ===
                                                    1
                                                        ? 'partida'
                                                        : 'partidas'
                                                }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- NOTA -->

                            <div
                                v-if="
                                    candidate.note
                                "
                                class="mt-5 rounded-2xl bg-zinc-100 p-4"
                            >
                                <p class="font-bold">
                                    ℹ️ Información adicional
                                </p>

                                <p
                                    class="mt-1 text-sm text-zinc-600"
                                >
                                    {{
                                        candidate.note
                                    }}
                                </p>
                            </div>

                            <!-- ENLACES -->

                            <div
                                class="mt-5 flex flex-wrap gap-3"
                            >
                                <a
                                    v-if="
                                        candidate.game
                                            .bgg_url
                                    "
                                    :href="
                                        candidate.game
                                            .bgg_url
                                    "
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-sm font-semibold text-[#5200FF]"
                                >
                                    BGG ↗
                                </a>

                                <a
                                    v-if="
                                        candidate.game
                                            .youtube_url
                                    "
                                    :href="
                                        candidate.game
                                            .youtube_url
                                    "
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-sm font-semibold text-[#FF6B00]"
                                >
                                    ▶ Tutorial
                                </a>
                            </div>

                            <!-- VOTO -->

                            <div
                                class="mt-6 grid gap-3"
                                :class="
                                    needsSecondChoice
                                        ? 'grid-cols-2'
                                        : 'grid-cols-1'
                                "
                            >
                                <button
                                    type="button"
                                    class="rounded-xl border-2 px-3 py-3 font-bold"
                                    :class="
                                        form.first_candidate_id ===
                                        candidate.id
                                            ? 'border-[#CCFF00] bg-[#CCFF00] text-black'
                                            : 'border-zinc-200'
                                    "
                                    @click="
                                        selectFirst(
                                            candidate.id
                                        )
                                    "
                                >
                                    {{ needsSecondChoice ? '🥇 1ª opción' : '✓ Mi voto' }}
                                </button>

                                <button
                                    v-if="needsSecondChoice"
                                    type="button"
                                    class="rounded-xl border-2 px-3 py-3 font-bold"
                                    :class="
                                        form.second_candidate_id ===
                                        candidate.id
                                            ? 'border-[#FF6B00] bg-[#FF6B00] text-black'
                                            : 'border-zinc-200'
                                    "
                                    @click="
                                        selectSecond(
                                            candidate.id
                                        )
                                    "
                                >
                                    🥈 2ª opción
                                </button>
                            </div>
                        </div>
                    </article>
                </div>

                <!-- CONFIRMAR -->

                <div
                    class="sticky bottom-0 mt-8 border-t bg-zinc-50/95 py-4 backdrop-blur"
                >
                    <div
                        v-if="singleCandidate"
                        class="mb-3 rounded-xl bg-lime-100 px-3 py-2 text-sm font-bold"
                    >
                        🎲
                        {{ selectedFirstGame?.name }}
                    </div>

                    <div
                        v-else
                        class="mb-3 grid gap-2 text-sm sm:grid-cols-2"
                    >
                        <div
                            class="rounded-xl bg-lime-100 px-3 py-2"
                        >
                            <span class="font-black">
                                🥇
                            </span>

                            {{ selectedFirstGame?.name ?? 'Elige primera opción' }}
                        </div>

                        <div
                            class="rounded-xl bg-orange-100 px-3 py-2"
                        >
                            <span class="font-black">
                                🥈
                            </span>

                            {{ selectedSecondGame?.name ?? 'Elige segunda opción' }}
                        </div>
                    </div>

                    <div
                        v-if="
                            form.hasErrors
                        "
                        class="mb-3 rounded-xl bg-red-50 p-3 text-sm font-semibold text-red-700"
                    >
                        Revisa los datos antes de
                        confirmar tu voto.
                    </div>

                    <div
                        v-if="canSubmit"
                        class="mb-3 text-sm"
                    >
                        <template v-if="needsSecondChoice">
                            🥇 Primera opción seleccionada
                            ·
                            🥈 Segunda opción seleccionada
                        </template>

                        <template v-else>
                            ✓ Juego seleccionado
                        </template>
                    </div>

                    <button
                        type="button"
                        :disabled="
                            !canSubmit ||
                            form.processing
                        "
                        class="w-full rounded-2xl bg-[#5200FF] px-6 py-4 text-lg font-black text-white disabled:cursor-not-allowed disabled:opacity-40"
                        @click="submit"
                    >
                        {{
                            form.processing
                                ? 'Guardando...'
                                : needsSecondChoice
                                  ? 'Confirmar mis votos'
                                  : 'Confirmar mi voto'
                        }}
                    </button>
                </div>
            </template>
        </div>
    </main>
</template>
