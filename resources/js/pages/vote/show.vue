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

const brokenCoverIds = ref<number[]>([]);
const markCoverAsBroken = (candidateId: number) => {
    if (!brokenCoverIds.value.includes(candidateId)) {
        brokenCoverIds.value.push(candidateId);
    }
};

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
            class="bg-[#5200FF] px-4 py-6 text-white sm:px-5 sm:py-8"
        >
            <div
                class="mx-auto max-w-5xl"
            >
                <p
                    class="text-sm font-bold uppercase tracking-wider text-[#CCFF00]"
                >
                    ¿A qué jugamos?
                </p>

                <h1
                    class="mt-2 break-words text-2xl font-black sm:text-3xl"
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
            class="mx-auto max-w-5xl px-4 py-6 sm:py-8"
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
                v-if="poll.status === 'closed'"
                class="mx-auto max-w-3xl"
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
                        class="flex min-w-0 items-center gap-3 rounded-2xl border bg-white p-3 shadow-sm sm:gap-4 sm:p-5"
                    >
                        <!-- POSICIÓN -->

                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-lg font-black sm:h-14 sm:w-14 sm:text-xl"
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
                            class="h-16 w-12 shrink-0 rounded-lg bg-zinc-100 object-contain sm:h-20 sm:w-16"
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
                                class="text-2xl font-black text-[#5200FF] sm:text-3xl"
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
                class="rounded-3xl border border-green-200 bg-white p-5 text-center shadow-sm sm:p-6"
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
                    class="mb-4 flex items-center justify-between rounded-xl bg-white px-4 py-3 shadow-sm"
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

                <div class="grid items-start gap-4 lg:grid-cols-2">
                    <article
                        v-for="candidate in poll.candidates"
                        :key="candidate.id"
                        class="min-w-0 overflow-hidden rounded-2xl border-2 bg-white shadow-sm transition-colors"
                        :class="{
                            'border-[#7aa600] ring-2 ring-[#CCFF00]/70':
                                form.first_candidate_id === candidate.id,
                            'border-[#FF6B00] ring-2 ring-[#FF6B00]/30':
                                form.second_candidate_id === candidate.id,
                            'border-zinc-200':
                                form.first_candidate_id !== candidate.id &&
                                form.second_candidate_id !== candidate.id,
                        }"
                    >
                        <!-- Una portada pequeña y completa, también en móvil. -->
                        <div class="flex min-w-0 gap-3 p-3 sm:gap-4 sm:p-4">
                            <div class="flex h-32 w-24 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-zinc-100 p-2 sm:h-40 sm:w-28">
                                <img
                                    v-if="candidate.game.image_url && !brokenCoverIds.includes(candidate.id)"
                                    :src="candidate.game.image_url"
                                    :alt="`Portada de ${candidate.game.name}`"
                                    loading="lazy"
                                    class="h-full w-full object-contain"
                                    @error="markCoverAsBroken(candidate.id)"
                                />
                                <span v-else class="text-center text-xs text-zinc-500">🎲 Sin portada</span>
                            </div>

                            <div class="min-w-0 flex-1">
                                <div
                                    v-if="form.first_candidate_id === candidate.id || form.second_candidate_id === candidate.id"
                                    class="mb-2"
                                >
                                    <span
                                        v-if="form.first_candidate_id === candidate.id"
                                        class="inline-block rounded-full bg-[#CCFF00] px-2.5 py-1 text-xs font-black text-black"
                                    >
                                        🥇 Primera opción
                                    </span>
                                    <span
                                        v-else
                                        class="inline-block rounded-full bg-orange-100 px-2.5 py-1 text-xs font-black text-orange-900"
                                    >
                                        🥈 Segunda opción
                                    </span>
                                </div>

                                <h2 class="break-words text-lg font-black leading-snug sm:text-xl">
                                    {{ candidate.game.name }}
                                </h2>

                                <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-zinc-600 sm:text-sm">
                                    <span>👥 {{ candidate.game.min_players }}–{{ candidate.game.max_players }}</span>
                                    <span v-if="candidate.game.min_playtime">
                                        ⏱ {{ candidate.game.min_playtime }}<template v-if="candidate.game.max_playtime && candidate.game.max_playtime !== candidate.game.min_playtime">–{{ candidate.game.max_playtime }}</template> min
                                    </span>
                                    <span v-if="candidate.game.complexity !== null">🧠 {{ candidate.game.complexity }}/5</span>
                                    <span v-if="candidate.game.game_mode">🎲 {{ modeLabel(candidate.game.game_mode) }}</span>
                                </div>

                                <div
                                    v-if="candidate.game.ideal_player_counts.length"
                                    class="mt-2 flex flex-wrap gap-1.5"
                                >
                                    <span
                                        v-for="number in candidate.game.ideal_player_counts"
                                        :key="number"
                                        class="rounded-full bg-lime-100 px-2 py-1 text-xs font-semibold text-lime-900"
                                    >
                                        Ideal a {{ number }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- La expansión prevista es información, no un candidato independiente. -->
                        <p
                            v-if="candidate.expansion"
                            class="mx-3 mb-3 rounded-lg bg-violet-50 px-3 py-2 text-sm text-violet-900 sm:mx-4"
                        >
                            🧩 Con expansión: <strong>{{ candidate.expansion.name }}</strong>
                        </p>

                        <p
                            v-if="candidate.note"
                            class="mx-3 mb-3 break-words rounded-lg bg-zinc-100 px-3 py-2 text-sm text-zinc-700 sm:mx-4"
                        >
                            ℹ️ {{ candidate.note }}
                        </p>

                        <!-- Los jugadores del grupo son visibles sin desplegar nada. -->
                        <div class="border-t border-zinc-100 px-3 py-3 sm:px-4">
                            <p class="text-xs font-bold uppercase tracking-wide text-zinc-500">
                                👥 Jugadores de hoy ({{ candidate.history.participants.length }})
                            </p>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                <span
                                    v-for="participant in candidate.history.participants"
                                    :key="participant.participant_id"
                                    class="max-w-full break-words rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-semibold text-zinc-700"
                                >
                                    {{ participant.display_name }}<template v-if="participant.participant_id === currentParticipantId"> (tú)</template>
                                </span>
                            </div>
                        </div>

                        <!-- Los botones quedan antes de las secciones secundarias. -->
                        <div
                            class="grid gap-2 px-3 pb-3 sm:px-4"
                            :class="needsSecondChoice ? 'grid-cols-2' : 'grid-cols-1'"
                        >
                            <button
                                type="button"
                                :aria-pressed="form.first_candidate_id === candidate.id"
                                class="min-h-12 rounded-xl border-2 px-2 py-3 text-sm font-bold transition-colors sm:text-base"
                                :class="form.first_candidate_id === candidate.id
                                    ? 'border-[#9bcb00] bg-[#CCFF00] text-black'
                                    : 'border-zinc-200 bg-white text-zinc-900 hover:border-[#9bcb00] hover:bg-lime-50'"
                                @click="selectFirst(candidate.id)"
                            >
                                {{ needsSecondChoice ? '🥇 1.ª opción' : '✓ Mi voto' }}
                            </button>
                            <button
                                v-if="needsSecondChoice"
                                type="button"
                                :aria-pressed="form.second_candidate_id === candidate.id"
                                class="min-h-12 rounded-xl border-2 px-2 py-3 text-sm font-bold transition-colors sm:text-base"
                                :class="form.second_candidate_id === candidate.id
                                    ? 'border-[#FF6B00] bg-orange-100 text-orange-950'
                                    : 'border-zinc-200 bg-white text-zinc-900 hover:border-orange-400 hover:bg-orange-50'"
                                @click="selectSecond(candidate.id)"
                            >
                                🥈 2.ª opción
                            </button>
                        </div>

                        <!-- La descripción, el historial y los enlaces siguen disponibles. -->
                        <details class="group border-t border-zinc-200 bg-zinc-50/70">
                            <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 text-sm font-semibold text-[#5200FF] [&::-webkit-details-marker]:hidden">
                                Ver descripción e historial
                                <span aria-hidden="true" class="ml-2 transition-transform group-open:rotate-180">⌄</span>
                            </summary>
                            <div class="space-y-3 border-t border-zinc-200 px-4 py-4 text-sm">
                                <p v-if="candidate.game.short_description" class="whitespace-pre-line leading-relaxed text-zinc-700">
                                    {{ candidate.game.short_description }}
                                </p>
                                <p v-else class="text-zinc-500">Este juego no tiene descripción breve.</p>

                                <div class="rounded-xl bg-white p-3">
                                    <p class="font-bold">📚 Historial registrado</p>
                                    <p class="mt-1 text-zinc-600">
                                        {{ candidate.history.total_plays }}
                                        {{ candidate.history.total_plays === 1 ? 'partida registrada' : 'partidas registradas' }}
                                        <template v-if="candidate.history.last_played_at">
                                            · Última: {{ formatDate(candidate.history.last_played_at) }}
                                        </template>
                                    </p>
                                    <div class="mt-3 space-y-1.5 border-t border-zinc-100 pt-3">
                                        <div
                                            v-for="participant in candidate.history.participants"
                                            :key="participant.participant_id"
                                            class="flex items-start justify-between gap-2"
                                        >
                                            <span class="min-w-0 break-words text-zinc-700">{{ participant.display_name }}</span>
                                            <span class="shrink-0 text-zinc-500">
                                                {{ !participant.tracked ? 'Sin historial' : participant.play_count === 0 ? 'Nunca' : `${participant.play_count} ${participant.play_count === 1 ? 'partida' : 'partidas'}` }}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="candidate.game.bgg_url || candidate.game.youtube_url" class="flex flex-wrap gap-4">
                                    <a
                                        v-if="candidate.game.bgg_url"
                                        :href="candidate.game.bgg_url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="font-bold text-[#5200FF] underline-offset-2 hover:underline"
                                    >BGG ↗</a>
                                    <a
                                        v-if="candidate.game.youtube_url"
                                        :href="candidate.game.youtube_url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="font-bold text-[#5200FF] underline-offset-2 hover:underline"
                                    >▶ Tutorial</a>
                                </div>
                            </div>
                        </details>
                    </article>
                </div>

                <!-- CONFIRMAR -->

                <div class="sticky bottom-0 z-20 -mx-4 mt-5 border-t border-zinc-200 bg-zinc-50/95 px-4 py-3 shadow-[0_-5px_25px_rgba(0,0,0,0.06)] backdrop-blur">
                    <div class="mb-2 grid min-w-0 gap-2 text-xs sm:text-sm" :class="needsSecondChoice ? 'grid-cols-2' : 'grid-cols-1'">
                        <p class="min-w-0 truncate rounded-lg bg-lime-100 px-3 py-2" :title="selectedFirstGame?.name ?? ''">
                            🥇 {{ selectedFirstGame?.name ?? 'Elige primera opción' }}
                        </p>
                        <p
                            v-if="needsSecondChoice"
                            class="min-w-0 truncate rounded-lg bg-orange-100 px-3 py-2"
                            :title="selectedSecondGame?.name ?? ''"
                        >
                            🥈 {{ selectedSecondGame?.name ?? 'Elige segunda opción' }}
                        </p>
                    </div>

                    <p v-if="form.hasErrors" class="mb-2 text-sm font-semibold text-red-700">
                        Revisa los datos antes de confirmar tu voto.
                    </p>

                    <button
                        type="button"
                        :disabled="!canSubmit || form.processing"
                        class="w-full rounded-xl bg-[#5200FF] px-4 py-3 text-base font-black text-white transition hover:bg-[#4100cc] disabled:cursor-not-allowed disabled:opacity-40 sm:py-3.5"
                        @click="submit"
                    >
                        {{ form.processing ? 'Guardando...' : needsSecondChoice ? 'Confirmar mis votos' : 'Confirmar mi voto' }}
                    </button>
                </div>
            </template>
        </div>
    </main>
</template>
