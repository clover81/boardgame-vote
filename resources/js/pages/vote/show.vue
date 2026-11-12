<script setup lang="ts">
import { playerMeepleColor, playerNameColor } from '@/lib/player-colors';
import MeepleIcon from '@/components/icons/MeepleIcon.vue';
import YoutubeLogoIcon from '@/components/icons/YoutubeLogoIcon.vue';
import { BuildingComplexPlus, LibraryBig, Link as ChainIcon, UsersRound, HourglassCog, Swords, BrainCog, NotebookPen } from '@lucide/vue';

import PlayingCardsFanIcon from '@/components/icons/PlayingCardsFanIcon.vue';

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
import ScrollToTopProgress from '@/components/ScrollToTopProgress.vue';

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
    color: string | null;
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
    single_choice: boolean;
    required_vote_count: number;

    participants: Participant[];
    candidates: Candidate[];
}

const props = defineProps<{
    poll: Poll;
    results: Result[];
    currentParticipantId: number;
    voteToken: string;
}>();

// La votación pública usa siempre fondo claro, aunque la aplicación esté en modo oscuro.
// Evitamos que las variantes dark: conviertan los nombres de colores en tonos pálidos.
const publicPlayerNameColor = (name: string, savedColor?: string | null): string =>
    playerNameColor(name, savedColor)
        .split(/\s+/)
        .filter((className) => !className.startsWith('dark:'))
        .join(' ');

const colorForParticipant = (id: number): string | null =>
    props.poll.participants.find((participant) => participant.id === id)?.color ?? null;

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

const needsSecondChoice = computed(() => props.poll.required_vote_count === 2);

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

        // Al recargar, la confirmación depende de los votos guardados,
        // no de un estado temporal del navegador.
        voteSaved.value = participant.votes.some((vote) => vote.position === 1)
            && (!needsSecondChoice.value || participant.votes.some((vote) => vote.position === 2));

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
    // Segundo clic: quitar la primera opción y reiniciar la segunda.
    if (form.first_candidate_id === candidateId) {
        form.first_candidate_id = null;
        form.second_candidate_id = null;
        return;
    }

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
    // Segundo clic: quitar únicamente la segunda opción.
    if (form.second_candidate_id === candidateId) {
        form.second_candidate_id = null;
        return;
    }

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
const hasRecordedVotes = computed(() => props.results.some(result => result.points > 0));
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
                <h1 class="break-words text-2xl font-bold sm:text-3xl">
                    {{ poll.name }}
                </h1>

                <p
                    class="mt-2 text-violet-100"
                >
                    <template v-if="poll.status === 'open'">
                        {{
                            needsSecondChoice
                                ? 'Elige tu primera y segunda opción.'
                                : poll.candidates.length > 1 ? 'Elige un juego y confirma tu voto.' : 'Confirma tu voto para este juego.'
                        }}
                    </template>

                    <template v-else>
                        La votación ha terminado.
                    </template>
                </p>
            </div>
        </header>

        <div
            class="mx-auto max-w-5xl px-4 pt-6 pb-24 sm:pt-8"
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
                <h2 class="text-2xl font-bold sm:text-3xl">
                    Resultado de la votación
                </h2>

                <p
                    v-if="hasRecordedVotes"
                    class="mt-2 text-zinc-500"
                >
                    {{
                        poll.single_choice
                            ? 'Un voto por persona.'
                            : needsSecondChoice
                                ? 'Primera opción: 2 puntos · Segunda opción: 1 punto'
                                : 'Cada voto vale 2 puntos'
                    }}
                </p>

                <div
                    v-if="hasRecordedVotes"
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
                            {{ poll.single_choice
                                ? 'Hay varios juegos con el mismo número de votos.'
                                : 'Hay varios juegos con los mismos puntos y el mismo número de primeras opciones.' }}
                        </p>
                    </div>
                </div>

                <div
                    v-if="hasRecordedVotes"
                    class="mt-7 space-y-4"
                >
                    <article
                        v-for="result in results"
                        :key="
                            result.candidate_id
                        "
                        class="group flex min-w-0 items-center gap-3 rounded-2xl border border-zinc-200 bg-white p-3 shadow-sm transition-[border-color,box-shadow,transform] duration-200 motion-safe:hover:-translate-y-0.5 hover:border-violet-400 hover:shadow-[0_12px_30px_rgba(82,0,255,0.10)] sm:gap-4 sm:p-5"
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
                            v-if="result.game.image_url && !brokenCoverIds.includes(result.candidate_id)"
                            :src="result.game.image_url"
                            :alt="`Portada de ${result.game.name}`"
                            loading="lazy"
                            class="h-16 w-12 shrink-0 rounded-lg bg-zinc-100 object-contain transition-transform duration-300 motion-safe:group-hover:scale-[1.04] sm:h-20 sm:w-16"
                            @error="markCoverAsBroken(result.candidate_id)"
                        />
                        <div
                            v-else
                            class="flex h-16 w-12 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-lg sm:h-20 sm:w-16"
                            role="img"
                            :aria-label="`Sin portada de ${result.game.name}`"
                        >
                            <PlayingCardsFanIcon class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-violet-700" aria-hidden="true" />
                        </div>

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
                                <BuildingComplexPlus class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-[#6D28D9] dark:text-[#CCFF00]" :stroke-width="2" aria-hidden="true" />
                                {{
                                    resultExpansion(result.candidate_id)?.name
                                }}
                            </p>

                            <p
                                v-if="!poll.single_choice"
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
                                {{ poll.single_choice ? (result.points === 1 ? 'voto' : 'votos') : 'puntos' }}
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
                        class="rounded-xl border border-zinc-300 px-4 py-3 font-bold transition-[border-color,color,box-shadow] duration-200 hover:border-violet-500 hover:text-[#5200FF] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#5200FF] focus-visible:ring-offset-2"
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

                        <p :class="['mt-1 inline-flex items-center gap-2 font-bold', publicPlayerNameColor(selectedParticipant?.display_name ?? '', selectedParticipant?.color)]">
                            <MeepleIcon :class="['size-4 shrink-0', playerMeepleColor(selectedParticipant?.display_name, selectedParticipant?.color)]" aria-hidden="true" />
                            {{ selectedParticipant?.display_name }}
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

                <div class="grid items-stretch gap-4 lg:grid-cols-2">
                    <article
                        v-for="candidate in poll.candidates"
                        :key="candidate.id"
                        class="group flex min-w-0 flex-col overflow-hidden rounded-2xl border-2 bg-white shadow-sm transition-[border-color,box-shadow,transform] duration-200 motion-safe:hover:-translate-y-0.5 hover:shadow-[0_12px_30px_rgba(82,0,255,0.12)]"
                        :class="{
                            'border-[#7aa600] ring-2 ring-[#CCFF00]/70':
                                form.first_candidate_id === candidate.id,
                            'border-[#FF6B00] ring-2 ring-[#FF6B00]/30':
                                form.second_candidate_id === candidate.id,
                            'border-zinc-200 hover:border-violet-400 hover:ring-2 hover:ring-violet-500/15':
                                form.first_candidate_id !== candidate.id &&
                                form.second_candidate_id !== candidate.id,
                        }"
                    >
                        <!-- Una portada pequeña y completa, también en móvil. -->
                        <div class="flex min-w-0 gap-3 p-3 sm:gap-4 sm:p-4 lg:min-h-[14.25rem]">
                            <div class="flex h-32 w-24 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-zinc-100 p-1.5 shadow-inner sm:h-44 sm:w-32">
                                <img
                                    v-if="candidate.game.image_url && !brokenCoverIds.includes(candidate.id)"
                                    :src="candidate.game.image_url"
                                    :alt="`Portada de ${candidate.game.name}`"
                                    loading="lazy"
                                    class="h-full w-full object-contain transition-transform duration-300 motion-safe:group-hover:scale-[1.04]"
                                    @error="markCoverAsBroken(candidate.id)"
                                />
                                <span v-else class="text-center text-xs text-zinc-500"><PlayingCardsFanIcon class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-violet-700" aria-hidden="true" /> Sin portada</span>
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="mb-2 min-h-7">
                                    <span
                                        v-if="form.first_candidate_id === candidate.id"
                                        class="inline-block rounded-full bg-[#CCFF00] px-2.5 py-1 text-xs font-black text-black"
                                    >
                                        🥇 Primera opción
                                    </span>
                                    <span
                                        v-else-if="form.second_candidate_id === candidate.id"
                                        class="inline-block rounded-full bg-orange-100 px-2.5 py-1 text-xs font-black text-orange-900"
                                    >
                                        🥈 Segunda opción
                                    </span>
                                </div>

                                <h2 class="break-words text-lg font-black leading-snug sm:text-xl lg:min-h-[3.5rem]">
                                    {{ candidate.game.name }}
                                </h2>

                                <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-zinc-600 sm:text-sm">
                                    <span><UsersRound class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-[#6D28D9]" :stroke-width="2.2" role="img" aria-label="Jugadores" /> {{ candidate.game.min_players }}–{{ candidate.game.max_players }}</span>
                                    <span v-if="candidate.game.min_playtime">
                                        <HourglassCog class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-[#1D4ED8]" :stroke-width="2.2" role="img" aria-label="Duración" /> {{ candidate.game.min_playtime }}<template v-if="candidate.game.max_playtime && candidate.game.max_playtime !== candidate.game.min_playtime">–{{ candidate.game.max_playtime }}</template> min
                                    </span>
                                    <span v-if="candidate.game.complexity !== null"><BrainCog class="inline h-3.5 w-3.5 shrink-0 text-pink-400" /> {{ candidate.game.complexity }}/5</span>
                                    <span v-if="candidate.game.game_mode"><Swords v-if="candidate.game.game_mode === 'competitive'" class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-orange-700" :stroke-width="2.2" aria-hidden="true" /> {{ modeLabel(candidate.game.game_mode) }}</span>
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
                            <BuildingComplexPlus class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-[#6D28D9] dark:text-[#CCFF00]" :stroke-width="2" aria-hidden="true" /> Con expansión: <strong>{{ candidate.expansion.name }}</strong>
                        </p>

                        <div
                            v-if="candidate.note"
                            class="mx-3 mb-3 rounded-xl border border-violet-100 bg-violet-50/60 p-3 text-sm sm:mx-4"
                        >
                            <p class="mb-1 flex items-center gap-1.5 font-bold text-zinc-900">
                                <NotebookPen class="size-4 shrink-0 text-[#5200FF]" aria-hidden="true" />
                                Para esta partida
                            </p>
                            <p class="whitespace-pre-line break-words text-zinc-700">{{ candidate.note }}</p>
                        </div>

                        <!-- Los jugadores del grupo son visibles sin desplegar nada. -->
                        <div class="border-t border-zinc-100 px-3 py-3 sm:px-4 lg:mt-auto lg:min-h-[5.75rem]">
                            <p class="text-xs font-bold uppercase tracking-wide text-zinc-500">
                                <UsersRound class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-[#6D28D9]" :stroke-width="2.2" role="img" aria-label="Jugadores" /> Jugadores de hoy ({{ candidate.history.participants.length }})
                            </p>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                <span
                                    v-for="participant in candidate.history.participants"
                                    :key="participant.participant_id"
                                    class="max-w-full break-words rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-semibold text-zinc-700"
                                >
                                    <span :class="['inline-flex items-center gap-1.5', publicPlayerNameColor(participant.display_name, colorForParticipant(participant.participant_id))]"><MeepleIcon :class="['h-3.5 w-3.5 shrink-0', playerMeepleColor(participant.display_name, colorForParticipant(participant.participant_id))]" />{{ participant.display_name }}</span><template v-if="participant.participant_id === currentParticipantId"> (tú)</template>
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
                                class="min-h-12 rounded-xl border-2 px-2 py-3 text-sm font-bold transition-[background-color,border-color,box-shadow,transform] duration-200 motion-safe:hover:-translate-y-0.5 hover:shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#5200FF] focus-visible:ring-offset-2 motion-safe:active:scale-[0.98] sm:text-base"
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
                                class="min-h-12 rounded-xl border-2 px-2 py-3 text-sm font-bold transition-[background-color,border-color,box-shadow,transform] duration-200 motion-safe:hover:-translate-y-0.5 hover:shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#5200FF] focus-visible:ring-offset-2 motion-safe:active:scale-[0.98] sm:text-base"
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
                                    <p class="font-bold"><LibraryBig class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-violet-600 dark:text-violet-300" :stroke-width="2" aria-hidden="true" /> Historial registrado</p>
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
                                            <span class="min-w-0 break-words text-zinc-700"><span :class="['inline-flex items-center gap-1.5', publicPlayerNameColor(participant.display_name, colorForParticipant(participant.participant_id))]"><MeepleIcon :class="['h-3.5 w-3.5 shrink-0', playerMeepleColor(participant.display_name, colorForParticipant(participant.participant_id))]" />{{ participant.display_name }}</span></span>
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
                                    >BGG <ChainIcon class="ml-1 inline-block h-4 w-4 shrink-0 align-[-0.15em]" aria-hidden="true" /></a>
                                    <a
                                        v-if="candidate.game.youtube_url"
                                        :href="candidate.game.youtube_url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="font-bold text-[#5200FF] underline-offset-2 hover:underline inline-flex items-center justify-center gap-1.5"
                                    ><YoutubeLogoIcon class="size-4 shrink-0" aria-hidden="true" /> Tutorial</a>
                                </div>
                            </div>
                        </details>
                    </article>
                </div>

                <!-- CONFIRMAR -->

                <div class="mx-auto mt-6 max-w-3xl rounded-2xl border border-zinc-200 bg-white p-3 shadow-sm sm:p-4">
                    <div class="mb-3 hidden min-w-0 gap-2 text-xs sm:grid sm:text-sm" :class="needsSecondChoice ? 'grid-cols-2' : 'grid-cols-1'">
                        <p class="min-w-0 truncate rounded-xl bg-lime-100 px-3 py-2.5 font-medium" :title="selectedFirstGame?.name ?? ''">
                            🥇 {{ selectedFirstGame?.name ?? (needsSecondChoice ? 'Elige primera opción' : 'Elige un juego') }}
                        </p>
                        <p
                            v-if="needsSecondChoice"
                            class="min-w-0 truncate rounded-xl bg-orange-100 px-3 py-2.5 font-medium"
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
                        class="w-full rounded-xl bg-[#5200FF] px-4 py-3 text-base font-black text-white transition-[background-color,box-shadow,transform] duration-200 hover:bg-[#4100cc] hover:shadow-lg hover:shadow-violet-500/20 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[#5200FF]/40 focus-visible:ring-offset-2 motion-safe:active:scale-[0.99] disabled:cursor-not-allowed disabled:bg-zinc-100 disabled:text-zinc-700 disabled:shadow-none disabled:hover:bg-zinc-100 sm:py-3.5"
                        @click="submit"
                    >
                        <span class="sm:hidden">
                            {{ form.processing ? 'Guardando...' : canSubmit ? (needsSecondChoice ? 'Confirmar votos' : 'Confirmar voto') : form.first_candidate_id === null ? (needsSecondChoice ? 'Elige tu 1.ª opción' : 'Elige un juego') : 'Elige tu 2.ª opción' }}
                            <span v-if="!form.processing" class="ml-1 opacity-75">
                                ({{ Number(form.first_candidate_id !== null) + Number(needsSecondChoice && form.second_candidate_id !== null) }}/{{ requiredVoteCount }})
                            </span>
                        </span>
                        <span class="hidden sm:inline">
                            {{ form.processing ? 'Guardando...' : needsSecondChoice ? 'Confirmar mis votos' : 'Confirmar mi voto' }}
                        </span>
                    </button>
                </div>
            </template>
        </div>
        <ScrollToTopProgress :remember-position="false" compact-on-mobile />
    </main>
</template>
