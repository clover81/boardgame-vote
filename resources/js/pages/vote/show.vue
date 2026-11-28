<script setup lang="ts">
import { playerMeepleColor, playerNameColor } from '@/lib/player-colors';
import { getSecondPreferenceTieBreak, pendingTieBreakMessage } from '@/lib/poll-tiebreak';
import MeepleIcon from '@/components/icons/MeepleIcon.vue';
import YoutubeLogoIcon from '@/components/icons/YoutubeLogoIcon.vue';
import { BuildingComplexPlus, LibraryBig, Link as ChainIcon, UsersRound, HourglassCog, Swords, BrainCog, NotebookPen , Trophy, Medal, CircleStar, SquareStar} from '@lucide/vue';

import PlayingCardsFanIcon from '@/components/icons/PlayingCardsFanIcon.vue';

import {
    computed,
    nextTick,
    ref,
    watch,
} from 'vue';

import {
    Head,
    useForm,
} from '@inertiajs/vue3';

import AlertError from '@/components/AlertError.vue';
import ScrollToTopProgress from '@/components/ScrollToTopProgress.vue';
import { normalizeSearchText } from '@/lib/search-text';

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

    game_mode: string | null;
    game_modes: string[];

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
    third_votes: number;

    points: number;

    rank: number;

    is_winner: boolean;
    is_top_tie: boolean;
}

interface Poll {
    id: number;
    name: string;
    token: string;
    session_position: 1 | 2 | null;

    status:
        | 'open'
        | 'closed';

    has_clear_winner: boolean;
    tie_break_outcome: {
        poll_id: number;
        status: 'draft' | 'open' | 'closed';
        has_counted_votes: boolean;
        winner: {
            game_id: number;
            game_name: string;
            votes: number;
            method: 'votes' | 'draw';
        } | null;
    } | null;
    single_choice: boolean;
    ranking_slots: number | null;
    required_vote_count: number;

    participants: Participant[];
    candidates: Candidate[];
}

interface LinkedSession {
    name: string;
    status: 'open' | 'closed';
    session_position: 1 | 2;
    candidates: Array<{
        id: number;
        is_winner: boolean;
        note: string | null;
        expansion_name: string | null;
        game: Pick<Game, 'name' | 'image_url' | 'min_players' | 'max_players' | 'min_playtime' | 'max_playtime' | 'short_description' | 'youtube_url' | 'bgg_url'>;
    }>;
}

const props = defineProps<{
    poll: Poll;
    results: Result[];
    linkedSession: LinkedSession | null;
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
const voteConnectionError = ref('');
const voteError = ref<HTMLElement | null>(null);
const pageVoteError = ref<HTMLElement | null>(null);
const voteConfirmation = ref<HTMLElement | null>(null);
const savedVotePanel = ref<HTMLElement | null>(null);
const voteEditor = ref<HTMLElement | null>(null);
const confirmationPanel = ref<HTMLElement | null>(null);

const candidateSearch = ref('');
const candidateSort = ref<'name' | 'name_desc'>('name');
const lastSelectedCandidateId = ref<number | null>(null);

const goToConfirmation = () => {
    const panel = confirmationPanel.value;
    if (!panel) return;

    panel.focus({ preventScroll: true });
    panel.scrollIntoView({
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches
            ? 'auto'
            : 'smooth',
        block: 'center',
    });
};

const focusVoteRegion = async (
    focusTarget: () => HTMLElement | null,
    scrollTarget: () => HTMLElement | null
) => {
    await nextTick();

    focusTarget()?.focus({ preventScroll: true });

    scrollTarget()?.scrollIntoView({
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches
            ? 'auto'
            : 'smooth',
        block: 'start',
    });
};

const editVote = async () => {
    candidateSearch.value = '';
    lastSelectedCandidateId.value = null;
    voteSaved.value = false;
    await focusVoteRegion(
        () => voteEditor.value,
        () => voteEditor.value
    );
};

const candidateSearchInput = ref<HTMLInputElement | null>(null);

const clearCandidateSearch = (event: MouseEvent) => {
    candidateSearch.value = '';

    if (event.detail === 0) candidateSearchInput.value?.focus();
};

const visibleCandidates = computed(() => {
    const terms = normalizeSearchText(candidateSearch.value).split(' ').filter(Boolean);

    return props.poll.candidates
        .filter(({ game }) => {
            const name = normalizeSearchText(game.name);
            return terms.every(term => name.includes(term));
        })
        .sort((a, b) => {
            const comparison = a.game.name.localeCompare(
                b.game.name,
                'es',
                { sensitivity: 'base' }
            );

            return (
                candidateSort.value === 'name_desc'
                    ? -comparison
                    : comparison
            ) || a.id - b.id;
        });
});

const brokenCoverIds = ref<number[]>([]);
const markCoverAsBroken = (candidateId: number) => {
    if (!brokenCoverIds.value.includes(candidateId)) {
        brokenCoverIds.value.push(candidateId);
    }
};

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

const selectedThirdGame = computed(() =>
    props.poll.candidates.find(
        candidate => candidate.id === form.third_candidate_id
    )?.game ?? null
);

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

    third_candidate_id:
        null as number | null,
});

const requiredVoteCount = computed(
    () => props.poll.required_vote_count
);
const needsSecondChoice = computed(() => requiredVoteCount.value >= 2);
const needsThirdChoice = computed(() => requiredVoteCount.value === 3);

const preferencePointsLabel = (position: 1 | 2 | 3): string => {
    const points = props.poll.ranking_slots !== null
        ? Math.max(0, props.poll.ranking_slots - position + 1)
        : props.poll.single_choice
            ? 1
            : position === 1 ? 2 : position === 2 ? 1 : 0;

    return `${points} ${points === 1 ? 'punto' : 'puntos'}`;
};

watch(requiredVoteCount, count => {
    if (count < 2) form.second_candidate_id = null;
    if (count < 3) form.third_candidate_id = null;
});

const selectedIds = computed(() => [
    form.first_candidate_id,
    ...(needsSecondChoice.value ? [form.second_candidate_id] : []),
    ...(needsThirdChoice.value ? [form.third_candidate_id] : []),
]);

const selectedVoteCount = computed(
    () => selectedIds.value.filter(id => id !== null).length
);

const canSubmit = computed(() =>
    form.participant_id !== null &&
    selectedIds.value.length === requiredVoteCount.value &&
    selectedIds.value.every(id => id !== null) &&
    new Set(selectedIds.value).size === selectedIds.value.length
);

const showSearchConfirmation = computed(() =>
    canSubmit.value &&
    candidateSearch.value.trim() !== '' &&
    !visibleCandidates.value.some(candidate => candidate.id === lastSelectedCandidateId.value)
);

const voteButtonLabel = computed(() => {
    if (form.processing) return 'Guardando...';
    if (canSubmit.value) {
        return needsSecondChoice.value ? 'Confirmar votos' : 'Confirmar voto';
    }
    if (form.first_candidate_id === null) {
        return needsSecondChoice.value ? 'Elige tu 1.ª opción' : 'Elige un juego';
    }
    if (needsSecondChoice.value && form.second_candidate_id === null) {
        return 'Elige tu 2.ª opción';
    }
    if (needsThirdChoice.value && form.third_candidate_id === null) {
        return 'Elige tu 3.ª opción';
    }
    return 'Revisa tus elecciones';
});

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

        form.third_candidate_id =
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

            if (vote.position === 3) {
                form.third_candidate_id = vote.candidate_id;
            }
        }

        // Al recargar, la confirmación depende de los votos guardados,
        // no de un estado temporal del navegador.
        voteSaved.value = Array.from(
            { length: requiredVoteCount.value },
            (_, index) => index + 1
        ).every(position =>
            participant.votes.some(vote => vote.position === position)
        );

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

const preferenceFields = [
    'first_candidate_id',
    'second_candidate_id',
    'third_candidate_id',
] as const;

const selectPreference = (position: 1 | 2 | 3, candidateId: number) => {
    if (form.processing) return;

    const field = preferenceFields[position - 1];

    if (form[field] === candidateId) {
        // Desmarcar una preferencia no borra las demás.
        form[field] = null;
    } else {
        const previousField = preferenceFields.find(otherField => form[otherField] === candidateId);
        if (previousField !== undefined) {
            form[previousField] = form[field];
        }

        form[field] = candidateId;
    }

    lastSelectedCandidateId.value = candidateId;
};

const selectFirst = (id: number) => selectPreference(1, id);
const selectSecond = (id: number) => selectPreference(2, id);
const selectThird = (id: number) => selectPreference(3, id);

/*
|--------------------------------------------------------------------------
| Enviar
|--------------------------------------------------------------------------
*/

const cancelVoteEdit = async () => {
    const savedVotes = selectedParticipant.value?.votes ?? [];

    form.first_candidate_id = savedVotes.find((vote) => vote.position === 1)?.candidate_id ?? null;
    form.second_candidate_id = savedVotes.find((vote) => vote.position === 2)?.candidate_id ?? null;
    form.third_candidate_id = savedVotes.find((vote) => vote.position === 3)?.candidate_id ?? null;
    form.clearErrors();
    voteConnectionError.value = '';
    voteSaved.value = true;

    await focusVoteRegion(
        () => voteConfirmation.value,
        () => savedVotePanel.value
    );
};

const submit = () => {
    if (form.processing || !canSubmit.value) {
        return;
    }

    voteConnectionError.value = '';

    form.transform((data) => ({
        ...data,
        single_choice: props.poll.single_choice,
        ranking_slots: requiredVoteCount.value,
    })).post(
        `/vote/${props.poll.token}/${props.voteToken}`,
        {
            preserveScroll: true,
            onError: async () => {
                await nextTick();
                const error = voteError.value ?? pageVoteError.value;
                error?.focus();
            },

            onNetworkError: () => {
                voteConnectionError.value = 'No hemos podido confirmar el guardado. Comprueba tu conexión y vuelve a intentarlo.';
                void nextTick(() => voteError.value?.focus());
                return false;
            },

            onSuccess: async () => {
                voteSaved.value = true;
                await focusVoteRegion(
                    () => voteConfirmation.value,
                    () => savedVotePanel.value
                );
            },
        }
    );
};

/*
|--------------------------------------------------------------------------
| Tipo
|--------------------------------------------------------------------------
*/

const gameModesFor = (game: Game): string[] =>
    game.game_modes.length
        ? game.game_modes
        : game.game_mode
            ? [game.game_mode]
            : [game.max_players === 1 ? 'solo' : 'unclassified'];

const gameModeLabel = (game: Game): string => {
    const labels: Record<string, string> = {
        competitive: 'Competitivo',
        cooperative: 'Cooperativo',
        semi_cooperative: 'Semicooperativo',
        solo: 'Solitario',
        unclassified: 'Sin clasificar',
    };

    return gameModesFor(game)
        .map(mode => labels[mode] ?? mode)
        .join(' · ');
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
const formatPlayerRange = ({ min_players: min, max_players: max }: Pick<Game, 'min_players' | 'max_players'>): string =>
    min === max ? String(min) : `${min}–${max}`;

const formatPlaytime = ({ min_playtime: min, max_playtime: max }: Pick<Game, 'min_playtime' | 'max_playtime'>): string => {
    if (min && max && min !== max) return `${min}–${max} min`;
    return `${min || max} min`;
};

// Admitir únicamente enlaces web externos válidos.
const externalUrl = (url: string | null): string | undefined => {
    if (!url) return undefined;

    try {
        const parsed = new URL(url);

        return ['http:', 'https:'].includes(parsed.protocol)
            ? parsed.href
            : undefined;
    } catch {
        return undefined;
    }
};

const hasRecordedVotes = computed(() => props.results.some(result => result.points > 0));

const finalTieBreakWinner = computed(
    () => props.poll.tie_break_outcome?.winner ?? null
);

const sessionWinnerCandidateId = computed(() => {
    if (props.poll.status !== 'closed') return null;

    const winningGameId = finalTieBreakWinner.value?.game_id;
    const tieBreakCandidate = props.poll.candidates.find(candidate => candidate.game.id === winningGameId);

    return tieBreakCandidate?.id ?? props.results.find(result => result.is_winner)?.candidate_id ?? null;
});

// Los juegos siguen accesibles después de votar y al cerrar la sesión.
const sessionCandidates = computed(() => {
    const ranks = new Map(
        props.results.map(result => [result.candidate_id, result.rank])
    );

    return [...props.poll.candidates].sort((a, b) => {
        if (props.poll.status === 'closed') {
            const winner = sessionWinnerCandidateId.value;
            const winnerOrder = Number(b.id === winner) - Number(a.id === winner);
            if (winnerOrder !== 0) return winnerOrder;

            const aRank = ranks.get(a.id) ?? Number.MAX_SAFE_INTEGER;
            const bRank = ranks.get(b.id) ?? Number.MAX_SAFE_INTEGER;
            if (aRank !== bRank) return aRank - bRank;
        }

        return a.game.name.localeCompare(b.game.name, 'es', {
            sensitivity: 'base',
        });
    });
});

const sessionResult = (candidateId: number): Result | undefined =>
    props.results.find(result => result.candidate_id === candidateId);

const secondPreferenceTieBreak = computed(() =>
    getSecondPreferenceTieBreak(props.results)
);

const winningGame = computed(() => {
    const winner = props.results.find(result => result.is_winner);
    return winner
        ? props.poll.candidates.find(candidate => candidate.id === winner.candidate_id)?.game
        : undefined;
});

// Si no hay tutorial guardado, enlazamos a una búsqueda de YouTube,
// identificada claramente como búsqueda, nunca como vídeo verificado.
const tutorialUrl = (game: Pick<Game, 'name' | 'youtube_url'>): string =>
    externalUrl(game.youtube_url) ??
    `https://www.youtube.com/results?search_query=${encodeURIComponent(
        `tutorial juego de mesa ${game.name} español`
    )}`;

const myChoiceLabel = (candidateId: number): string | null => {
    const position = selectedParticipant.value?.votes.find(
        vote => vote.candidate_id === candidateId
    )?.position;

    if (position === 1) {
        return needsSecondChoice.value ? 'Tu primera opción' : '✓ Tu voto';
    }
    if (position === 2) return 'Tu segunda opción';
    if (position === 3) return 'Tu tercera opción';

    return null;
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

        <!-- CABECERA FOTOGRÁFICA CLOVER -->
        <div class="mx-auto max-w-5xl px-4 pt-4 sm:pt-6">
            <header class="relative isolate overflow-hidden rounded-3xl bg-[#190c30] text-white shadow-lg shadow-[#181424]/15 ring-1 ring-violet-950/10 sm:rounded-[28px]">

                <!-- Fotografía de la estantería de votaciones. -->
                <img
                    src="/images/vote-shelf-comic.webp"
                    alt=""
                    aria-hidden="true"
                    width="2000"
                    height="924"
                    loading="eager"
                    decoding="async"
                    class="pointer-events-none absolute inset-0 h-full w-full object-cover object-[center_40%] brightness-[1.12] saturate-[1.34] sm:object-[center_41%]"
                />

                <div
                    aria-hidden="true"
                    class="pointer-events-none absolute inset-0 bg-gradient-to-t from-[#170927]/95 via-[#210d3b]/65 to-[#170927]/10 sm:from-[#24083e]/75 sm:via-[#210d3b]/10 sm:to-transparent"
                ></div>
                <div
                    aria-hidden="true"
                    class="pointer-events-none absolute inset-0 hidden bg-gradient-to-r from-[#170927]/90 via-[#250940]/35 to-[#19072b]/5 sm:block"
                ></div>

                <div class="relative z-10 px-4 pt-4 pb-5 sm:flex sm:min-h-[230px] sm:flex-col sm:justify-between sm:gap-5 sm:px-7 sm:py-6 lg:min-h-[250px] lg:px-9 lg:py-7">

                    <!-- Identidad Clover -->
                    <div class="inline-flex w-fit max-w-full items-center gap-2.5 rounded-xl bg-[#190c30]/85 pr-3 sm:bg-transparent sm:pr-0">
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-[#CCFF00] text-[#5200FF] sm:size-11"
                            aria-hidden="true"
                        >
                            <Trophy class="size-5 sm:size-6" :stroke-width="2.2" />
                        </span>

                        <div class="min-w-0 drop-shadow-sm">
                            <p class="text-sm font-black leading-tight tracking-tight sm:text-base">
                                Juegos de mesa
                            </p>
                            <p class="text-xs font-medium text-zinc-200">
                                Clover
                            </p>
                        </div>
                    </div>

                    <!-- Información de la votación -->
                    <div class="mt-9 min-w-0 sm:mt-0 sm:max-w-[66%]">

                        <div
                            v-if="poll.session_position !== null"
                            class="mb-2.5 flex flex-wrap items-center gap-x-2.5 gap-y-1.5"
                        >
                            <span class="inline-flex rounded-full border border-white/40 bg-[#321b50]/80 px-3 py-1 text-xs font-bold text-white">
                                Juego {{ poll.session_position }} de 2
                            </span>

                            <span class="text-xs font-medium text-zinc-100 sm:text-sm">
                                Cada juego se vota por separado
                            </span>
                        </div>

                        <h1 class="border-l-[3px] border-[#CCFF00] pl-3 wrap-anywhere text-[clamp(1.65rem,4.8vw,2.75rem)] font-black leading-[1.06] tracking-tight text-white drop-shadow-[0_2px_10px_rgba(0,0,0,0.7)] sm:pl-4">
                            {{ poll.name }}
                        </h1>

                        <p class="mt-2 pl-[15px] text-sm/5 font-medium text-zinc-100 sm:pl-[19px] sm:text-base/6">
                            <template v-if="poll.status === 'open' && voteSaved">
                                {{
                                    needsSecondChoice
                                        ? 'Tus preferencias ya están guardadas.'
                                        : 'Tu voto ya está guardado.'
                                }}
                            </template>

                            <template v-else-if="poll.status === 'open'">
                                {{
                                    needsThirdChoice
                                        ? 'Elige tres juegos distintos: 3, 2 y 1 puntos.'
                                        : needsSecondChoice
                                            ? 'Elige tu primera y segunda opción.'
                                            : poll.candidates.length > 1
                                                ? 'Elige un juego y confirma tu voto.'
                                                : 'Confirma tu voto para este juego.'
                                }}
                            </template>

                            <template v-else>
                                La votación ha terminado.
                            </template>
                        </p>
                    </div>

                </div>
            </header>
        </div>

        <div
            class="mx-auto max-w-5xl px-4 pt-6 pb-24 sm:pt-8"
        >
            <div
                v-if="form.hasErrors && (poll.status !== 'open' || voteSaved)"
                ref="pageVoteError"
                tabindex="-1"
                class="mb-6"
            >
                <AlertError
                    title="No hemos podido guardar tu voto"
                    :errors="Object.values(form.errors)"
                />
            </div>

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

                <a
                    v-if="!poll.has_clear_winner"
                    href="#session-games"
                    class="mt-4 inline-flex min-h-11 items-center gap-2 rounded-xl border border-violet-200 bg-white px-4 py-2.5 text-sm font-bold text-[#5200FF] shadow-sm hover:bg-violet-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF]"
                >
                    <YoutubeLogoIcon class="size-5 shrink-0" aria-hidden="true" />
                    Ver juegos y tutoriales
                </a>

                <div v-if="hasRecordedVotes" class="mt-2 text-sm text-zinc-600">
                    <ul
                        v-if="needsSecondChoice"
                        aria-label="Puntuación por preferencia"
                        class="flex flex-wrap gap-2"
                    >
                        <li class="rounded-lg bg-zinc-100 px-2.5 py-1">
                            1.ª opción · <span class="whitespace-nowrap font-semibold">{{ preferencePointsLabel(1) }}</span>
                        </li>
                        <li class="rounded-lg bg-zinc-100 px-2.5 py-1">
                            2.ª opción · <span class="whitespace-nowrap font-semibold">{{ preferencePointsLabel(2) }}</span>
                        </li>
                        <li v-if="needsThirdChoice" class="rounded-lg bg-zinc-100 px-2.5 py-1">
                            3.ª opción · <span class="whitespace-nowrap font-semibold">{{ preferencePointsLabel(3) }}</span>
                        </li>
                    </ul>
                    <p v-else>
                        <template v-if="poll.single_choice || poll.ranking_slots === 1">Un voto por persona.</template>
                        <template v-else>
                            Cada voto vale <span class="whitespace-nowrap font-semibold">{{ preferencePointsLabel(1) }}</span>.
                        </template>
                    </p>
                </div>

                <div
                    v-if="hasRecordedVotes"
                    class="mt-5 mb-5"
                >
                    <div
                        v-if="poll.has_clear_winner"
                        class="overflow-hidden rounded-2xl border border-lime-200 bg-[#F7FAED] p-4 text-zinc-900 shadow-sm sm:p-5"
                    >
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full bg-[#CCFF00] px-3 py-1.5 text-xs font-black text-zinc-950"
                        >
                            <Trophy class="size-4 shrink-0 text-[#5200FF]" :stroke-width="2.2" aria-hidden="true" /> Juego ganador
                        </span>

                        <div class="mt-4 flex min-w-0 items-start gap-4 sm:gap-5">
                            <img
                                v-if="winningGame?.image_url && !brokenCoverIds.includes(results[0].candidate_id)"
                                :src="winningGame.image_url"
                                :alt="`Portada de ${winningGame.name}`"
                                loading="lazy"
                                class="h-28 w-20 shrink-0 rounded-lg border border-zinc-200/70 object-contain shadow-sm sm:h-36 sm:w-28"
                                @error="markCoverAsBroken(results[0].candidate_id)"
                            />

                            <div class="min-w-0 flex-1">
                                <h3 class="break-words text-xl font-black leading-snug sm:text-2xl">
                                    {{ results[0].game.name }}
                                </h3>
                                <p
                                    v-if="secondPreferenceTieBreak"
                                    class="mt-2 text-sm font-bold text-lime-900"
                                >
                                    Desempate resuelto por más segundas opciones
                                    ({{ secondPreferenceTieBreak.winnerSecondVotes }}
                                    frente a {{ secondPreferenceTieBreak.nextBestSecondVotes }}).
                                </p>

                                <div
                                    v-if="winningGame"
                                    class="mt-3 flex flex-wrap gap-x-4 gap-y-2 text-xs font-medium text-zinc-600 sm:text-sm"
                                >
                                    <span class="inline-flex items-center gap-1.5">
                                        <UsersRound class="size-4 text-[#5200FF]" aria-hidden="true" />
                                        {{ formatPlayerRange(winningGame) }} {{ winningGame.max_players === 1 ? 'jugador' : 'jugadores' }}
                                    </span>
                                    <span
                                        v-if="winningGame.min_playtime || winningGame.max_playtime"
                                        class="inline-flex items-center gap-1.5"
                                    >
                                        <HourglassCog class="size-4 text-blue-700" aria-hidden="true" />
                                        {{ formatPlaytime(winningGame) }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <p
                            v-if="winningGame?.short_description"
                            class="mt-4 line-clamp-3 text-sm leading-relaxed text-zinc-700 sm:text-base"
                        >
                            {{ winningGame.short_description }}
                        </p>

                        <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center">
                            <a
                                v-if="winningGame"
                                :href="tutorialUrl(winningGame)"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex min-h-12 items-center justify-center gap-2 rounded-xl bg-[#5200FF] px-4 py-3 text-center text-sm font-bold text-white shadow-sm transition-colors hover:bg-[#4100CC] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF]"
                            >
                                <YoutubeLogoIcon class="size-5 shrink-0" aria-hidden="true" />
                                {{ externalUrl(winningGame.youtube_url) ? 'Ver tutorial del ganador' : 'Buscar tutorial del ganador' }}
                            </a>

                            <a
                                href="#session-games"
                                class="inline-flex min-h-11 items-center justify-center rounded-xl px-3 py-2 text-center text-sm font-bold text-[#5200FF] underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF]"
                            >
                                Ver todos los juegos
                                <span class="ml-2" aria-hidden="true">↓</span>
                            </a>
                        </div>
                    </div>

                    <div
                        v-else-if="finalTieBreakWinner"
                        class="rounded-2xl border border-lime-200 bg-[#F7FAED] p-5 text-zinc-900"
                    >
                        <p class="font-black text-lime-900">
                            🏆 Ganador definitivo tras desempate
                        </p>
                        <p class="mt-3 break-words text-xl font-black">
                            {{ finalTieBreakWinner.game_name }}
                        </p>
                        <p class="mt-2 text-sm">
                            <template v-if="finalTieBreakWinner.method === 'draw'">
                                Ganador elegido por sorteo después de un nuevo empate
                                ({{ finalTieBreakWinner.votes }} votos).
                            </template>
                            <template v-else>
                                Ganó la ronda de desempate con
                                {{ finalTieBreakWinner.votes }}
                                {{ finalTieBreakWinner.votes === 1 ? 'voto' : 'votos' }}.
                            </template>
                        </p>
                        <p class="mt-2 text-sm text-zinc-600">
                            La clasificación de la votación original
                            se conserva sin cambios.
                        </p>
                    </div>

                    <div
                        v-else
                        class="rounded-2xl bg-orange-100 p-5 text-orange-900"
                    >
                        <p class="font-black">
                            <CircleStar class="mr-1 inline-block size-4 shrink-0 align-[-0.15em]" :stroke-width="2.2" aria-hidden="true" /> Empate en primera posición
                        </p>

                        <p class="mt-2 break-words text-sm font-bold">
                            {{
                                results
                                    .filter(result => result.is_top_tie)
                                    .map(result => result.game.name)
                                    .join(' · ')
                            }}
                        </p>

                        <p class="mt-1 text-sm">
                            {{ poll.single_choice || poll.ranking_slots === 1
                                ? 'Hay varios juegos con el mismo número de votos.'
                                : 'Hay varios juegos con los mismos puntos y el mismo número de primeras y segundas opciones.' }}
                        </p>

                        <p
                            v-if="poll.tie_break_outcome"
                            class="mt-3 text-sm font-semibold"
                        >
                            {{ pendingTieBreakMessage(poll.tie_break_outcome) }}
                        </p>
                    </div>
                </div>

                <details
                    v-if="hasRecordedVotes"
                    :open="results.length <= 4"
                    class="group mt-7"
                >
                    <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3 rounded-xl border border-zinc-200 bg-white px-4 py-3 font-bold text-[#5200FF] shadow-sm hover:bg-violet-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF] [&::-webkit-details-marker]:hidden">
                        <span class="inline-flex min-w-0 items-center gap-2">
                            <Medal class="size-5 shrink-0" :stroke-width="2" aria-hidden="true" />
                            <span>Clasificación<span class="hidden sm:inline"> completa</span></span>
                            <span class="shrink-0 rounded-md bg-violet-50 px-1.5 text-xs">
                                {{ results.length }}<span class="sr-only"> {{ results.length === 1 ? 'juego' : 'juegos' }}</span>
                            </span>
                        </span>
                        <span
                            aria-hidden="true"
                            class="shrink-0 text-xl transition-transform group-open:rotate-180"
                        >⌄</span>
                    </summary>
                    <div class="mt-4 space-y-4">
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

                                'bg-zinc-100 text-zinc-700':
                                    !result.is_winner &&
                                    !result.is_top_tie,
                            }"
                        >
                            <span v-if="result.is_winner">
                                <Medal class="inline-block size-4 text-[#5200FF]" :stroke-width="2" aria-hidden="true" />
                            </span>

                            <span v-else-if="result.is_top_tie">
                                <CircleStar class="inline-block size-4 text-[#5200FF]" :stroke-width="2" aria-hidden="true" />
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
                                class="wrap-anywhere text-lg font-bold leading-snug"
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
                                <BuildingComplexPlus class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-[#6D28D9]" :stroke-width="2" aria-hidden="true" />
                                {{
                                    resultExpansion(result.candidate_id)?.name
                                }}
                            </p>

                            <p
                                v-if="needsSecondChoice"
                                class="mt-1 flex flex-wrap gap-x-2 gap-y-1 text-xs text-zinc-600 sm:text-sm"
                            >
                                <span class="whitespace-nowrap">{{ result.first_votes }} {{ result.first_votes === 1 ? 'primera' : 'primeras' }}</span>
                                <span class="whitespace-nowrap">{{ result.second_votes }} {{ result.second_votes === 1 ? 'segunda' : 'segundas' }}</span>
                                <span v-if="needsThirdChoice" class="whitespace-nowrap">{{ result.third_votes }} {{ result.third_votes === 1 ? 'tercera' : 'terceras' }}</span>
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
                                {{ poll.single_choice || poll.ranking_slots === 1 ? (result.points === 1 ? 'voto' : 'votos') : (result.points === 1 ? 'punto' : 'puntos') }}
                            </p>
                        </div>
                    </article>
                    </div>
                </details>

                <div
                    v-else
                    class="mt-7 rounded-2xl border border-dashed p-6 text-center text-zinc-500"
                >
                    No hay votos registrados.
                </div>
            </section>

            <section
                v-if="poll.status === 'open' && voteSaved && selectedParticipant"
                ref="savedVotePanel"
                class="mx-auto max-w-3xl scroll-mt-6 rounded-3xl border border-lime-200 bg-white p-5 text-center shadow-sm sm:p-6"
            >
                <div
                    class="mx-auto flex h-14 w-14 items-center justify-center rounded-full border border-lime-200 bg-lime-100"
                >
                    <span class="text-3xl font-bold text-[#5200FF]" aria-hidden="true">
                        ✓
                    </span>
                </div>

                <h2
                    ref="voteConfirmation"
                    tabindex="-1"
                    class="mt-5 text-2xl font-black"
                >
                    ¡Voto guardado!
                </h2>

                <p class="mt-2 wrap-anywhere text-zinc-500">
                    Gracias,
                    <strong>
                        {{ selectedParticipant.display_name }}
                    </strong>.
                </p>

                <p class="mt-3 text-sm text-zinc-600">
                    {{
                        needsSecondChoice
                            ? 'Tus preferencias están guardadas. Puedes modificarlas mientras la votación siga abierta.'
                            : 'Tu voto está guardado. Puedes modificarlo mientras la votación siga abierta.'
                    }}
                </p>

                <p class="mt-4 rounded-xl bg-violet-50 px-3 py-2.5 text-sm text-violet-900">
                    Guarda esta página en favoritos para consultar los juegos
                    y sus tutoriales cuando se cierre la votación.
                    Este enlace es personal: no lo compartas.
                </p>

                <div
                    class="mt-6 grid gap-3 text-left"
                    :class="needsThirdChoice ? 'sm:grid-cols-3' : needsSecondChoice ? 'sm:grid-cols-2' : ''"
                >
                    <div
                        class="rounded-2xl bg-lime-50 p-4"
                    >
                        <p
                            class="text-xs font-black uppercase text-zinc-500"
                        >
                            {{ needsSecondChoice ? 'Primera opción' : 'Mi voto' }}
                            · {{ preferencePointsLabel(1) }}
                        </p>

                        <p class="mt-1 min-w-0 wrap-anywhere font-black">
                            {{ selectedFirstGame?.name }}
                        </p>
                    </div>

                    <div
                        v-if="needsSecondChoice"
                        class="rounded-2xl bg-orange-50 p-4"
                    >
                        <p
                            class="text-xs font-black uppercase text-zinc-500"
                        >
                            Segunda opción · {{ preferencePointsLabel(2) }}
                        </p>

                        <p class="mt-1 min-w-0 wrap-anywhere font-black">
                            {{ selectedSecondGame?.name }}
                        </p>
                    </div>

                    <div v-if="needsThirdChoice" class="rounded-2xl bg-sky-50 p-4">
                        <p class="text-xs font-black uppercase text-zinc-500">
                            Tercera opción · {{ preferencePointsLabel(3) }}
                        </p>
                        <p class="mt-1 min-w-0 wrap-anywhere font-black">
                            {{ selectedThirdGame?.name }}
                        </p>
                    </div>
                </div>

                <div
                    class="mt-6 grid gap-3"
                >
                    <a
                        href="#session-games"
                        class="flex min-h-12 items-center justify-center gap-2 rounded-xl bg-[#5200FF] px-4 py-3 text-center font-bold text-white hover:bg-[#4100cc] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF]"
                    >
                        <YoutubeLogoIcon class="size-5 shrink-0" aria-hidden="true" />
                        Ver juegos y tutoriales
                    </a>

                    <button
                        type="button"
                        class="rounded-xl border border-zinc-300 px-4 py-3 font-bold transition-[border-color,color,box-shadow] duration-200 hover:border-violet-500 hover:text-[#5200FF] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#5200FF] focus-visible:ring-offset-2"
                        @click="editVote"
                    >
                        {{ needsSecondChoice ? 'Modificar mis votos' : 'Modificar mi voto' }}
                    </button>

                </div>
            </section>

            <!-- ================================================= -->
            <!-- CONSULTAR JUEGOS DESPUÉS DE VOTAR O CERRAR        -->
            <!-- ================================================= -->

            <section
                v-if="poll.status === 'closed' || voteSaved"
                id="session-games"
                tabindex="-1"
                aria-labelledby="session-games-title"
                class="mt-8 scroll-mt-6"
            >
                <div class="mb-5">
                    <h2
                        id="session-games-title"
                        class="text-xl font-black sm:text-2xl"
                    >
                        Juegos y tutoriales de esta votación
                    </h2>

                    <p class="mt-2 text-sm text-zinc-600">
                        {{
                            poll.status === 'closed'
                                ? sessionWinnerCandidateId !== null
                                    ? 'Consulta el juego ganador y todos los propuestos. Puedes volver a este enlace cuando quieras.'
                                    : 'Consulta todos los juegos propuestos y sus tutoriales. Puedes volver a este enlace cuando quieras.'
                                : 'Tu voto está guardado. Puedes consultar los juegos y aprender sus reglas sin cambiar tu elección.'
                        }}
                    </p>
                    <a
                        v-if="linkedSession?.candidates.length"
                        href="#linked-session-games"
                        class="mt-3 inline-flex min-h-11 max-w-full items-center gap-2 rounded-xl border border-violet-200 bg-white px-3 py-2 text-sm font-bold text-[#5200FF] hover:bg-violet-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF]"
                    >
                        Ver juegos y tutoriales del juego {{ linkedSession.session_position }}
                        <span aria-hidden="true">↓</span>
                    </a>
                </div>

                <div class="grid items-stretch gap-4 sm:grid-cols-2">
                    <article
                        v-for="candidate in sessionCandidates"
                        :key="candidate.id"
                        class="flex min-w-0 flex-col rounded-2xl border-2 bg-white p-4 shadow-sm"
                        :class="
                            candidate.id === sessionWinnerCandidateId
                                ? 'border-[#9BCB00] ring-1 ring-[#CCFF00]'
                                : 'border-zinc-200'
                        "
                    >
                        <div class="flex min-w-0 items-start gap-3">
                            <div class="flex h-28 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-zinc-100 p-1">
                                <img
                                    v-if="candidate.game.image_url && !brokenCoverIds.includes(candidate.id)"
                                    :src="candidate.game.image_url"
                                    :alt="`Portada de ${candidate.game.name}`"
                                    loading="lazy"
                                    class="h-full w-full object-contain"
                                    @error="markCoverAsBroken(candidate.id)"
                                />
                                <PlayingCardsFanIcon
                                    v-else
                                    class="size-7 text-violet-700"
                                    aria-label="Juego sin portada"
                                />
                            </div>

                            <div class="min-w-0 flex-1">
                                <p
                                    v-if="candidate.id === sessionWinnerCandidateId"
                                    class="mb-2 inline-flex max-w-full items-center gap-1.5 rounded-full bg-[#CCFF00] px-2.5 py-1 text-xs font-black text-zinc-950"
                                >
                                    <Trophy class="size-4 shrink-0 text-[#5200FF]" :stroke-width="2.2" aria-hidden="true" /> Juego ganador
                                </p>
                                <p
                                    v-else-if="sessionResult(candidate.id)?.is_top_tie"
                                    class="mb-1 text-xs font-bold text-orange-800"
                                >
                                    <CircleStar class="mr-1 inline-block size-4 shrink-0 align-[-0.15em]" :stroke-width="2.2" aria-hidden="true" /> Empate en primera posición
                                </p>

                                <p
                                    v-if="myChoiceLabel(candidate.id)"
                                    class="mb-1 text-xs font-bold text-[#5200FF]"
                                >
                                    <SquareStar class="mr-1 inline-block size-4 align-[-0.15em]" :stroke-width="2" aria-hidden="true" /> {{ myChoiceLabel(candidate.id) }}
                                </p>

                                <h3 class="break-words font-black leading-snug text-zinc-900">
                                    {{ candidate.game.name }}
                                </h3>

                                <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-zinc-600">
                                    <span class="inline-flex items-center gap-1">
                                        <UsersRound class="size-3.5 text-[#5200FF]" aria-hidden="true" />
                                        {{ formatPlayerRange(candidate.game) }}
                                    </span>
                                    <span
                                        v-if="candidate.game.min_playtime || candidate.game.max_playtime"
                                        class="inline-flex items-center gap-1"
                                    >
                                        <HourglassCog class="size-3.5 text-blue-700" aria-hidden="true" />
                                        {{ formatPlaytime(candidate.game) }}
                                    </span>
                                </div>

                                <p
                                    v-if="candidate.expansion"
                                    class="mt-1 text-xs font-semibold text-[#5200FF]"
                                >
                                    Con expansión: {{ candidate.expansion.name }}
                                </p>

                                <p
                                    v-if="candidate.note"
                                    class="mt-2 whitespace-pre-line break-words text-xs text-zinc-600"
                                >
                                    {{ candidate.note }}
                                </p>
                            </div>
                        </div>

                        <p
                            v-if="candidate.game.short_description"
                            class="mt-3 line-clamp-3 text-sm leading-relaxed text-zinc-600"
                        >
                            {{ candidate.game.short_description }}
                        </p>

                        <div class="mt-auto flex flex-wrap items-stretch gap-2 pt-4">
                            <a
                                :href="tutorialUrl(candidate.game)"
                                :aria-label="`${externalUrl(candidate.game.youtube_url) ? 'Ver tutorial' : 'Buscar tutorial'} de ${candidate.game.name}`"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-[#5200FF] px-3 py-2.5 text-center text-sm font-bold text-white hover:bg-[#4100cc] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF]"
                            >
                                <YoutubeLogoIcon class="size-5 shrink-0" aria-hidden="true" />
                                {{ externalUrl(candidate.game.youtube_url) ? 'Ver tutorial' : 'Buscar tutorial' }}
                            </a>

                            <a
                                v-if="externalUrl(candidate.game.bgg_url)"
                                :href="externalUrl(candidate.game.bgg_url)"
                                :aria-label="`Ver BGG de ${candidate.game.name}`"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex min-h-11 items-center justify-center gap-1 rounded-xl border border-zinc-300 px-3 text-sm font-bold text-[#5200FF] hover:bg-violet-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF]"
                            >
                                BGG
                                <ChainIcon class="size-4 shrink-0" aria-hidden="true" />
                            </a>
                        </div>
                    </article>
                </div>
                <div
                    v-if="linkedSession && linkedSession.candidates.length"
                    class="mt-9 border-t border-zinc-200 pt-7"
                >
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <p class="text-xs font-bold uppercase tracking-wide text-[#5200FF]">
                            Juego {{ linkedSession.session_position }} de 2
                        </p>
                        <span class="text-xs font-semibold text-zinc-600">
                            {{ linkedSession.status === 'open' ? 'Votación abierta' : 'Votación cerrada' }}
                        </span>
                    </div>
                    <h3 id="linked-session-games" tabindex="-1" class="mt-1 scroll-mt-6 wrap-anywhere text-xl font-black">
                        {{ linkedSession.name }}
                    </h3>
                    <p class="mt-2 mb-4 text-sm text-zinc-600">
                        Juegos y tutoriales de la otra votación de esta sesión.
                        Puedes consultarlos sin modificar ningún voto.
                    </p>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <article
                            v-for="candidate in linkedSession.candidates"
                            :key="candidate.id"
                            class="flex min-w-0 flex-col rounded-2xl border bg-white p-3 shadow-sm"
                            :class="candidate.is_winner
                                ? 'border-[#9BCB00] ring-1 ring-[#CCFF00]'
                                : 'border-zinc-200'"
                        >
                            <div class="flex min-w-0 items-start gap-3">
                                <div class="flex h-24 w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-zinc-100">
                                    <img
                                        v-if="candidate.game.image_url && !brokenCoverIds.includes(candidate.id)"
                                        :src="candidate.game.image_url"
                                        :alt="`Portada de ${candidate.game.name}`"
                                        loading="lazy"
                                        class="h-full w-full object-contain"
                                        @error="markCoverAsBroken(candidate.id)"
                                    />
                                    <PlayingCardsFanIcon
                                        v-else
                                        class="size-6 text-violet-700"
                                        aria-label="Juego sin portada"
                                    />
                                </div>

                                <div class="min-w-0 flex-1">
                                    <p
                                        v-if="candidate.is_winner"
                                        class="mb-2 inline-flex max-w-full items-center gap-1.5 rounded-full bg-[#CCFF00] px-2.5 py-1 text-xs font-black text-zinc-950"
                                    >
                                        <Trophy class="size-4 shrink-0 text-[#5200FF]" :stroke-width="2.2" aria-hidden="true" /> Ganador
                                    </p>
                                    <h4 class="break-words font-black leading-snug">
                                        {{ candidate.game.name }}
                                    </h4>
                                    <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-zinc-600">
                                        <span class="inline-flex items-center gap-1">
                                            <UsersRound class="size-3.5 text-[#5200FF]" aria-hidden="true" />
                                            {{ formatPlayerRange(candidate.game) }}
                                        </span>
                                        <span
                                            v-if="candidate.game.min_playtime || candidate.game.max_playtime"
                                            class="inline-flex items-center gap-1"
                                        >
                                            <HourglassCog class="size-3.5 text-blue-700" aria-hidden="true" />
                                            {{ formatPlaytime(candidate.game) }}
                                        </span>
                                    </div>
                                    <p
                                        v-if="candidate.expansion_name"
                                        class="mt-1 text-xs font-semibold text-[#5200FF]"
                                    >
                                        Con expansión: {{ candidate.expansion_name }}
                                    </p>
                                    <p
                                        v-if="candidate.note"
                                        class="mt-2 whitespace-pre-line break-words text-xs text-zinc-600"
                                    >
                                        {{ candidate.note }}
                                    </p>
                                </div>
                            </div>

                            <p
                                v-if="candidate.game.short_description"
                                class="mt-3 line-clamp-3 text-sm text-zinc-600"
                            >
                                {{ candidate.game.short_description }}
                            </p>

                            <div class="mt-auto flex flex-wrap items-stretch gap-2 pt-4">
                                <a
                                    :href="tutorialUrl(candidate.game)"
                                    :aria-label="`${externalUrl(candidate.game.youtube_url) ? 'Ver tutorial' : 'Buscar tutorial'} de ${candidate.game.name}`"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-[#5200FF] px-3 py-2.5 text-center text-sm font-bold text-white hover:bg-[#4100cc] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF]"
                                >
                                    <YoutubeLogoIcon class="size-5 shrink-0" aria-hidden="true" />
                                    {{ externalUrl(candidate.game.youtube_url) ? 'Ver tutorial' : 'Buscar tutorial' }}
                                </a>
                                <a
                                    v-if="externalUrl(candidate.game.bgg_url)"
                                    :href="externalUrl(candidate.game.bgg_url)"
                                    :aria-label="`Ver BGG de ${candidate.game.name}`"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex min-h-11 items-center justify-center gap-1 rounded-xl border border-zinc-300 px-3 text-sm font-bold text-[#5200FF] hover:bg-violet-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF]"
                                >
                                    BGG
                                    <ChainIcon class="size-4 shrink-0" aria-hidden="true" />
                                </a>
                            </div>
                        </article>
                    </div>
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
                    ref="voteEditor"
                    tabindex="-1"
                    class="mb-4 flex items-center justify-between rounded-xl bg-white px-4 py-3 shadow-sm"
                >
                    <div class="min-w-0">
                        <p
                            class="text-sm text-zinc-500"
                        >
                            Estás votando como
                        </p>

                        <p :class="['mt-1 flex min-w-0 items-center gap-2 font-bold', publicPlayerNameColor(selectedParticipant?.display_name ?? '', selectedParticipant?.color)]">
                            <MeepleIcon :class="['size-4 shrink-0', playerMeepleColor(selectedParticipant?.display_name, selectedParticipant?.color)]" aria-hidden="true" />
                            <span class="min-w-0 wrap-anywhere">{{ selectedParticipant?.display_name }}</span>
                        </p>

                        <p
                            v-if="hasPreviousVote"
                            class="mt-1 text-xs font-semibold text-orange-600"
                        >
                            {{
                                needsSecondChoice
                                    ? 'Tus votos anteriores siguen guardados hasta que confirmes los cambios.'
                                    : 'Tu voto anterior sigue guardado hasta que confirmes los cambios.'
                            }}
                        </p>
                    </div>

                </div>

                <!-- Los participantes son comunes a todos los candidatos.
                     Su historial individual sigue en el desplegable de cada juego. -->
                <section
                    v-if="poll.participants.length"
                    aria-label="Jugadores de esta votación"
                    class="mb-4 rounded-xl border border-zinc-200 bg-white p-3 shadow-sm sm:p-4"
                >
                    <p class="text-xs font-bold uppercase tracking-wide text-zinc-600">
                        <UsersRound
                            class="mr-1 inline-block size-4 align-[-0.15em] text-[#5200FF]"
                            :stroke-width="2.2"
                            aria-hidden="true"
                        />
                        Jugadores de hoy ({{ poll.participants.length }})
                    </p>

                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <span
                            v-for="participant in poll.participants"
                            :key="participant.id"
                            class="max-w-full break-words rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-semibold text-zinc-700"
                        >
                            <span
                                :class="[
                                    'inline-flex max-w-full min-w-0 items-center gap-1.5',
                                    publicPlayerNameColor(
                                        participant.display_name,
                                        participant.color
                                    )
                                ]"
                            >
                                <MeepleIcon
                                    :class="[
                                        'size-3.5 shrink-0',
                                        playerMeepleColor(
                                            participant.display_name,
                                            participant.color
                                        )
                                    ]"
                                    aria-hidden="true"
                                />
                                <span class="min-w-0 wrap-anywhere">{{ participant.display_name }}</span>
                            </span>
                            <span v-if="participant.id === currentParticipantId">
                                (tú)
                            </span>
                        </span>
                    </div>
                </section>

                <!-- JUEGOS -->

                <div v-if="poll.candidates.length >= 8" class="mb-4 rounded-xl bg-white p-3 sm:p-4">
                    <label for="vote-candidate-search" class="text-sm font-bold">Buscar juego</label>
                    <input
                        id="vote-candidate-search"
                        ref="candidateSearchInput"
                        v-model="candidateSearch"
                        type="search"
                        class="mt-2 w-full rounded-xl border border-zinc-300 px-3 py-2.5 focus-visible:outline-2 focus-visible:outline-[#5200FF]"
                        placeholder="Nombre del juego…"
                    />
                    <p role="status" class="mt-2 text-sm text-zinc-600">
                        {{ !visibleCandidates.length
                            ? 'No hay juegos que coincidan con la búsqueda'
                            : visibleCandidates.length === 1 ? '1 juego encontrado' : `${visibleCandidates.length} juegos encontrados` }}
                    </p>
                    <button
                        v-if="!visibleCandidates.length"
                        type="button"
                        class="mt-2 min-h-11 rounded-lg px-2 text-sm font-bold text-[#5200FF] hover:underline focus-visible:outline-2 focus-visible:outline-[#5200FF]"
                        @click="clearCandidateSearch"
                    >
                        Mostrar todos los juegos
                    </button>
                    <button
                        v-if="showSearchConfirmation"
                        type="button"
                        aria-controls="vote-confirmation"
                        class="mt-2 inline-flex min-h-11 items-center gap-2 rounded-xl border border-violet-200 bg-violet-50 px-3 py-2 text-sm font-bold text-[#5200FF] hover:bg-violet-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF]"
                        @click="goToConfirmation"
                    >
                        Ver resumen y confirmar
                        <span aria-hidden="true">↓</span>
                    </button>
                </div>

                <div
                    v-if="poll.candidates.length > 1"
                    class="mb-4 flex items-center justify-end gap-2"
                >
                    <label
                        for="vote-candidate-sort"
                        class="text-sm font-semibold text-zinc-700"
                    >
                        Ordenar:
                    </label>
                    <select
                        id="vote-candidate-sort"
                        v-model="candidateSort"
                        class="min-h-11 rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm font-semibold text-zinc-900 focus-visible:outline-2 focus-visible:outline-[#5200FF]"
                    >
                        <option value="name">Nombre (A–Z)</option>
                        <option value="name_desc">Nombre (Z–A)</option>
                    </select>
                </div>

                <div class="grid items-stretch gap-4 lg:grid-cols-2">
                    <article
                        v-for="candidate in visibleCandidates"
                        :key="candidate.id"
                        class="group flex min-w-0 flex-col overflow-hidden rounded-2xl border-2 bg-white shadow-sm transition-[border-color,box-shadow,transform] duration-200 motion-safe:hover:-translate-y-0.5 hover:shadow-[0_12px_30px_rgba(82,0,255,0.12)]"
                        :class="{
                            'border-[#7aa600] ring-2 ring-[#CCFF00]/70':
                                form.first_candidate_id === candidate.id,
                            'border-[#FF6B00] ring-2 ring-[#FF6B00]/30':
                                form.second_candidate_id === candidate.id,
                            'border-sky-500 ring-2 ring-sky-200':
                                form.third_candidate_id === candidate.id,
                            'border-zinc-200 hover:border-violet-400 hover:ring-2 hover:ring-violet-500/15':
                                form.first_candidate_id !== candidate.id &&
                                form.second_candidate_id !== candidate.id &&
                                form.third_candidate_id !== candidate.id,
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
                                        {{ needsSecondChoice ? 'Primera opción' : 'Mi voto' }}
                                    </span>
                                    <span
                                        v-else-if="form.second_candidate_id === candidate.id"
                                        class="inline-block rounded-full bg-orange-100 px-2.5 py-1 text-xs font-black text-orange-900"
                                    >
                                        Segunda opción
                                    </span>
                                    <span
                                        v-else-if="form.third_candidate_id === candidate.id"
                                        class="inline-block rounded-full bg-sky-100 px-2.5 py-1 text-xs font-black text-sky-900"
                                    >
                                        Tercera opción
                                    </span>
                                </div>

                                <h2 class="break-words text-lg font-black leading-snug sm:text-xl lg:min-h-[3.5rem]">
                                    {{ candidate.game.name }}
                                </h2>

                                <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-zinc-600 sm:text-sm">
                                    <span><UsersRound class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-[#6D28D9]" :stroke-width="2.2" role="img" aria-label="Jugadores" /> {{ formatPlayerRange(candidate.game) }}</span>
                                    <span v-if="candidate.game.min_playtime || candidate.game.max_playtime">
                                        <HourglassCog class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-[#1D4ED8]" :stroke-width="2.2" role="img" aria-label="Duración" /> {{ formatPlaytime(candidate.game) }}
                                    </span>
                                    <span
                                        v-if="candidate.game.complexity !== null"
                                        class="inline-flex items-center gap-1 whitespace-nowrap"
                                        title="Complejidad sobre 5"
                                    >
                                        <BrainCog
                                            class="size-4 shrink-0 text-pink-600"
                                            :stroke-width="2"
                                            aria-hidden="true"
                                        />
                                        <span class="sr-only">Complejidad: </span>
                                        <span>{{ candidate.game.complexity }}/5</span>
                                    </span>
                                    <span><Swords v-if="gameModesFor(candidate.game).includes('competitive')" class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-orange-700" :stroke-width="2.2" aria-hidden="true" /> {{ gameModeLabel(candidate.game) }}</span>
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
                            <BuildingComplexPlus class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-[#6D28D9]" :stroke-width="2" aria-hidden="true" /> Con expansión: <strong>{{ candidate.expansion.name }}</strong>
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

                        <!-- Los botones quedan antes de las secciones secundarias. -->
                        <div
                            class="grid gap-2 px-3 pb-3 sm:px-4"
                            :class="needsThirdChoice ? 'grid-cols-3' : needsSecondChoice ? 'grid-cols-2' : 'grid-cols-1'"
                        >
                            <button
                                type="button"
                                :disabled="form.processing"
                                :aria-pressed="form.first_candidate_id === candidate.id"
                                class="min-h-14 rounded-xl border-2 text-center px-2 py-3 text-sm font-bold transition-[background-color,border-color,box-shadow,transform] duration-200 motion-safe:enabled:hover:-translate-y-0.5 enabled:hover:shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#5200FF] focus-visible:ring-offset-2 motion-safe:enabled:active:scale-[0.98] disabled:opacity-75 disabled:cursor-wait sm:text-base"
                                :class="form.first_candidate_id === candidate.id
                                    ? 'border-[#9bcb00] bg-[#CCFF00] text-black'
                                    : 'border-zinc-200 bg-white text-zinc-900 enabled:hover:border-[#9bcb00] enabled:hover:bg-lime-50'"
                                @click="selectFirst(candidate.id)"
                            >
                                <span class="block text-xs font-bold leading-tight sm:text-sm">
                                    {{ needsSecondChoice ? '1.ª opción' : 'Mi voto' }}
                                    <span class="sr-only">para {{ candidate.game.name }}</span>
                                </span>
                                <span class="mt-1 block text-[11px] font-semibold leading-tight opacity-80 sm:text-xs">
                                    {{ preferencePointsLabel(1) }}
                                </span>
                            </button>
                            <button
                                v-if="needsSecondChoice"
                                type="button"
                                :disabled="form.processing"
                                :aria-pressed="form.second_candidate_id === candidate.id"
                                class="min-h-14 rounded-xl border-2 text-center px-2 py-3 text-sm font-bold transition-[background-color,border-color,box-shadow,transform] duration-200 motion-safe:enabled:hover:-translate-y-0.5 enabled:hover:shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#5200FF] focus-visible:ring-offset-2 motion-safe:enabled:active:scale-[0.98] disabled:opacity-75 disabled:cursor-wait sm:text-base"
                                :class="form.second_candidate_id === candidate.id
                                    ? 'border-[#FF6B00] bg-orange-100 text-orange-950'
                                    : 'border-zinc-200 bg-white text-zinc-900 enabled:hover:border-orange-400 enabled:hover:bg-orange-50'"
                                @click="selectSecond(candidate.id)"
                            >
                                <span class="block text-xs font-bold leading-tight sm:text-sm">
                                    2.ª opción
                                    <span class="sr-only">para {{ candidate.game.name }}</span>
                                </span>
                                <span class="mt-1 block text-[11px] font-semibold leading-tight opacity-80 sm:text-xs">
                                    {{ preferencePointsLabel(2) }}
                                </span>
                            </button>
                            <button
                                v-if="needsThirdChoice"
                                type="button"
                                :disabled="form.processing"
                                :aria-pressed="form.third_candidate_id === candidate.id"
                                class="min-h-14 rounded-xl border-2 text-center px-1 py-3 text-xs font-bold transition hover:shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:ring-offset-2 disabled:opacity-75 sm:text-sm"
                                :class="form.third_candidate_id === candidate.id
                                    ? 'border-sky-500 bg-sky-100 text-sky-950'
                                    : 'border-zinc-200 bg-white text-zinc-900 hover:border-sky-400 hover:bg-sky-50'"
                                @click="selectThird(candidate.id)"
                            >
                                <span class="block text-xs font-bold leading-tight sm:text-sm">
                                    3.ª opción
                                    <span class="sr-only">para {{ candidate.game.name }}</span>
                                </span>
                                <span class="mt-1 block text-[11px] font-semibold leading-tight opacity-80 sm:text-xs">
                                    {{ preferencePointsLabel(3) }}
                                </span>
                            </button>
                        </div>

                        <div
                            v-if="poll.candidates.length >= 4 && canSubmit && lastSelectedCandidateId === candidate.id"
                            class="px-3 pb-3 sm:px-4"
                        >
                            <button
                                type="button"
                                aria-controls="vote-confirmation"
                                class="flex min-h-11 w-full items-center justify-center gap-2 rounded-xl border border-violet-200 bg-violet-50 px-3 py-2.5 text-sm font-bold text-[#5200FF] transition hover:bg-violet-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF]"
                                @click="goToConfirmation"
                            >
                                Ver resumen y confirmar
                                <span aria-hidden="true">↓</span>
                            </button>
                        </div>

                        <!-- La descripción, el historial y los enlaces siguen disponibles. -->
                        <details class="group border-t border-zinc-200 bg-zinc-50/70">
                            <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 text-sm font-semibold text-[#5200FF] [&::-webkit-details-marker]:hidden">
                                Ver descripción e historial
                                <span class="sr-only">de {{ candidate.game.name }}</span>
                                <span aria-hidden="true" class="ml-2 transition-transform group-open:rotate-180">⌄</span>
                            </summary>
                            <div class="space-y-3 border-t border-zinc-200 px-4 py-4 text-sm">
                                <p v-if="candidate.game.short_description" class="whitespace-pre-line leading-relaxed text-zinc-700">
                                    {{ candidate.game.short_description }}
                                </p>
                                <p v-else class="text-zinc-500">Este juego no tiene descripción breve.</p>

                                <div class="rounded-xl bg-white p-3">
                                    <p class="font-bold"><LibraryBig class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-violet-600" :stroke-width="2" aria-hidden="true" /> Historial registrado</p>
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
                                            <span :class="['flex min-w-0 items-center gap-1.5', publicPlayerNameColor(participant.display_name, colorForParticipant(participant.participant_id))]">
                                                <MeepleIcon
                                                    :class="['h-3.5 w-3.5 shrink-0', playerMeepleColor(participant.display_name, colorForParticipant(participant.participant_id))]"
                                                    aria-hidden="true"
                                                />
                                                <span class="min-w-0 wrap-anywhere">{{ participant.display_name }}</span>
                                            </span>
                                            <span class="shrink-0 text-zinc-500">
                                                {{ !participant.tracked ? 'Sin historial' : participant.play_count === 0 ? 'Nunca' : `${participant.play_count} ${participant.play_count === 1 ? 'partida' : 'partidas'}` }}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="externalUrl(candidate.game.bgg_url) || externalUrl(candidate.game.youtube_url)" class="flex flex-wrap gap-4">
                                    <a
                                        v-if="externalUrl(candidate.game.bgg_url)"
                                        :href="externalUrl(candidate.game.bgg_url)"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="font-bold text-[#5200FF] underline-offset-2 hover:underline"
                                    >BGG <ChainIcon class="ml-1 inline-block h-4 w-4 shrink-0 align-[-0.15em]" aria-hidden="true" /></a>
                                    <a
                                        v-if="externalUrl(candidate.game.youtube_url)"
                                        :href="externalUrl(candidate.game.youtube_url)"
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

                <div
                    id="vote-confirmation"
                    ref="confirmationPanel"
                    tabindex="-1"
                    role="region"
                    aria-label="Resumen y confirmación del voto"
                    class="mx-auto mt-6 max-w-3xl rounded-2xl border border-zinc-200 bg-white p-3 shadow-sm sm:p-4"
                >
                    <div
                        class="mb-3 grid min-w-0 gap-2 text-xs sm:text-sm"
                        :class="needsThirdChoice ? 'sm:grid-cols-3' : needsSecondChoice ? 'sm:grid-cols-2' : 'grid-cols-1'"
                    >
                        <p class="min-w-0 wrap-anywhere rounded-xl bg-lime-100 px-3 py-2.5 font-medium" :title="selectedFirstGame?.name ?? ''">
                            <template v-if="selectedFirstGame">
                                {{ needsSecondChoice ? 'Primera opción' : 'Mi voto' }}
                                ({{ preferencePointsLabel(1) }}):
                                {{ selectedFirstGame.name }}
                            </template>
                            <template v-else>
                                {{ needsSecondChoice ? 'Elige primera opción' : 'Elige un juego' }}
                            </template>
                        </p>
                        <p
                            v-if="needsSecondChoice"
                            class="min-w-0 wrap-anywhere rounded-xl bg-orange-100 px-3 py-2.5 font-medium"
                            :title="selectedSecondGame?.name ?? ''"
                        >
                            <template v-if="selectedSecondGame">
                                Segunda opción ({{ preferencePointsLabel(2) }}):
                                {{ selectedSecondGame.name }}
                            </template>
                            <template v-else>
                                Elige segunda opción
                            </template>
                        </p>
                        <p
                            v-if="needsThirdChoice"
                            class="min-w-0 wrap-anywhere rounded-xl bg-sky-100 px-3 py-2.5 font-medium"
                            :title="selectedThirdGame?.name ?? ''"
                        >
                            <template v-if="selectedThirdGame">
                                Tercera opción ({{ preferencePointsLabel(3) }}):
                                {{ selectedThirdGame.name }}
                            </template>
                            <template v-else>
                                Elige tercera opción
                            </template>
                        </p>
                    </div>

                    <div v-if="form.hasErrors || voteConnectionError" ref="voteError" tabindex="-1" class="mb-3">
                        <AlertError
                            title="No hemos podido guardar tu voto"
                            :errors="voteConnectionError ? [voteConnectionError] : Object.values(form.errors)"
                        />
                    </div>

                    <p role="status" class="sr-only">
                        {{ form.processing ? 'Guardando el voto…' : '' }}
                    </p>

                    <button
                        type="button"
                        :disabled="!canSubmit || form.processing"
                        class="w-full rounded-xl bg-[#5200FF] px-4 py-3 text-base font-black text-white transition-[background-color,box-shadow,transform] duration-200 hover:bg-[#4100cc] hover:shadow-lg hover:shadow-violet-500/20 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[#5200FF]/40 focus-visible:ring-offset-2 motion-safe:active:scale-[0.99] disabled:cursor-not-allowed disabled:bg-zinc-100 disabled:text-zinc-700 disabled:shadow-none disabled:hover:bg-zinc-100 sm:py-3.5"
                        @click="submit"
                    >
                        <span class="sm:hidden">
                            {{ voteButtonLabel }}
                            <span v-if="!form.processing" class="ml-1 opacity-75">
                                ({{ selectedVoteCount }}/{{ requiredVoteCount }})
                            </span>
                        </span>
                        <span class="hidden sm:inline">
                            {{ form.processing ? 'Guardando...' : voteButtonLabel === 'Confirmar votos' ? 'Confirmar mis votos' : voteButtonLabel === 'Confirmar voto' ? 'Confirmar mi voto' : voteButtonLabel }}
                        </span>
                    </button>
                    <button
                        v-if="hasPreviousVote"
                        type="button"
                        :disabled="form.processing"
                        class="mt-2 w-full rounded-xl px-4 py-2 text-sm font-semibold text-[#5200FF] hover:underline disabled:opacity-50"
                        @click="cancelVoteEdit"
                    >
                        Cancelar cambios
                    </button>
                </div>
            </template>
        </div>
        <ScrollToTopProgress
            :remember-position="false"
            :hide-near-bottom="poll.status === 'open' && !voteSaved"
            compact-on-mobile
        />
    </main>
</template>
