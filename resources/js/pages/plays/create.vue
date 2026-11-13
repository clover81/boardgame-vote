<script setup lang="ts">
import { today } from '@/lib/local-date';
import MeepleIcon from '@/components/icons/MeepleIcon.vue';
import { playerNameColor } from '@/lib/player-colors';
import { BuildingComplexPlus } from '@lucide/vue';
import PlayingCardsFanIcon from '@/components/icons/PlayingCardsFanIcon.vue';
import {
    computed,
    ref,
    watch,
} from 'vue';

import {
    Head,
    Link,
    useForm,
    usePage,
} from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import InputError from '@/components/InputError.vue';
import ScrollToTopProgress from '@/components/ScrollToTopProgress.vue';

const page = usePage();
const pollReturnQuery = computed(() => {
    const back = new URLSearchParams(page.url.split('?')[1]).get('back');

    return back?.startsWith('/polls?') ? `?back=${encodeURIComponent(back)}` : '';
});

interface Player {
    id: number;
    name: string;
}

interface Expansion {
    id: number;
    name: string;
}

interface Game {
    id: number;
    name: string;
    image_url: string | null;

    /*
     * Expansión indicada al crear
     * la votación.
     */
    planned_expansion_id:
        number | null;

    expansions: Expansion[];
}

interface Poll {
    id: number;
    name: string;
    has_clear_winner: boolean;
    has_recorded_votes: boolean;
}

interface Candidate {
    id: number;
    game: {
        id: number;
        name: string;
    };
    planned_expansion_id: number | null;
}

interface Defaults {
    game_ids: number[];
    expansion_ids: Record<number, number | null>;
    player_ids: number[];
    played_at: string;
}

const props = defineProps<{
    poll: Poll;
    candidates: Candidate[];
    players: Player[];
    games: Game[];
    defaults: Defaults;
    initial_game_id?: number | null;
    initial_expansion_id?: number | null;
    initial_played_at?: string | null;
    initial_player_ids?: number[] | null;
}>();

/*
|--------------------------------------------------------------------------
| Formulario
|--------------------------------------------------------------------------
*/

const requestedInitialGameId = props.initial_game_id !== undefined
    ? props.initial_game_id
    : (props.defaults.game_ids[0] ?? null);

const initialGameId = props.games.some((game) => game.id === requestedInitialGameId)
    ? requestedInitialGameId
    : null;

const initialExpansionId = props.initial_expansion_id !== undefined
    ? props.initial_expansion_id
    : requestedInitialGameId !== null
      ? (props.defaults.expansion_ids[requestedInitialGameId] ?? null)
      : null;

const form = useForm({
    poll_id: props.poll.id,

    game_ids: initialGameId !== null ? [initialGameId] : [] as number[],

    expansion_ids: {} as Record<number, number | null>,

    played_at: props.initial_played_at ?? today(),

    player_ids:
        [...(props.initial_player_ids ?? props.defaults.player_ids)],

    notes: '',
});

const toggleGame = (gameId: number) => {
    if (form.game_ids.includes(gameId)) {
        form.game_ids = form.game_ids.filter(
            (id) => id !== gameId
        );
    } else {
        form.game_ids = [
            ...form.game_ids,
            gameId,
        ];
    }
};

const isGameSelected = (gameId: number) => {
    return form.game_ids.includes(gameId);
};

// Las portadas vienen de la lista de juegos, no de los candidatos.
const gameById = computed(() =>
    new Map(props.games.map((game) => [game.id, game]))
);

const brokenCoverIds = ref<number[]>([]);

const coverFor = (gameId: number): string | null =>
    gameById.value.get(gameId)?.image_url ?? null;

const markCoverAsBroken = (gameId: number) => {
    if (!brokenCoverIds.value.includes(gameId)) {
        brokenCoverIds.value.push(gameId);
    }
};

const candidateGameIds = computed(() =>
    props.candidates.map((candidate) => candidate.game.id)
);

const otherGames = computed(() =>
    props.games.filter((game) => !candidateGameIds.value.includes(game.id))
);

const selectedOtherGameId = computed({
    get: () => form.game_ids.find((gameId) =>
        otherGames.value.some((game) => game.id === gameId)
    ) ?? null,
    set: (gameId: number | null) => {
        const selectedCandidates = form.game_ids.filter((id) =>
            candidateGameIds.value.includes(id)
        );

        form.game_ids = gameId === null
            ? selectedCandidates
            : [...selectedCandidates, gameId];
    },
});

/*
|--------------------------------------------------------------------------
| Juegos seleccionados
|--------------------------------------------------------------------------
*/

const selectedGames = computed(() =>
    props.games.filter((game) => form.game_ids.includes(game.id))
);

/*
|--------------------------------------------------------------------------
| Expansión inicial de cada juego
|--------------------------------------------------------------------------
*/

watch(
    selectedGames,
    (games) => {
        for (const game of games) {
            if (game.id in form.expansion_ids) {
                continue;
            }

            const expansionId = game.id === initialGameId
                ? initialExpansionId
                : game.planned_expansion_id;

            form.expansion_ids[game.id] = game.expansions.some(
                (expansion) => expansion.id === expansionId
            ) ? expansionId : null;
        }
    },
    { immediate: true }
);

/*
|--------------------------------------------------------------------------
| Guardar
|--------------------------------------------------------------------------
*/

const submit = () => {
    form.transform((data) => ({
        ...data,
        expansion_ids: Object.fromEntries(
            data.game_ids.map((gameId) => [gameId, data.expansion_ids[gameId] ?? null])
        ),
    })).post(`/plays${pollReturnQuery.value}`);
};
</script>

<template>
    <Head title="Registrar partida" />

    <AppLayout>
        <div
            class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6"
        >
            <!-- VOLVER -->

            <Link
                :href="`/polls/${poll.id}${pollReturnQuery}`"
                class="text-sm font-semibold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
            >
                ← Volver a la votación
            </Link>

            <!-- CABECERA -->

            <div class="mt-4">
                <h1
                    class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 sm:text-3xl"
                >
                    Registrar partida
                </h1>

                <p
                    class="mt-2 text-zinc-500 dark:text-zinc-400"
                >
                    {{ poll.name }}
                </p>
            </div>

            <form
                class="mt-8 space-y-8"
                @submit.prevent="submit"
            >
                <div
                    class="rounded-2xl border border-violet-200 bg-violet-50 p-5 dark:border-violet-900 dark:bg-violet-950/20"
                >
                    <p
                        v-if="!poll.has_clear_winner"
                        class="mb-2 font-black text-orange-700 dark:text-orange-300"
                    >
                        {{ poll.has_recorded_votes ? 'Resultado: empate' : 'No hay votos registrados' }}
                    </p>

                    <p
                        class="font-black text-[#5200FF] dark:text-violet-300"
                    >
                        <PlayingCardsFanIcon class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-violet-600 dark:text-violet-300" aria-hidden="true" /> ¿Qué acabasteis jugando?
                    </p>

                    <p
                        v-if="initialGameId !== null"
                        class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-300"
                    >
                        El ganador está seleccionado. Desmárcalo si no lo jugasteis.
                    </p>
                </div>

                <!-- ================================================== -->
                <!-- JUEGO                                              -->
                <!-- ================================================== -->

                <section
                    class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <h2
                        class="text-xl font-bold"
                    >
                        Juego jugado
                    </h2>

                    <div v-if="candidates.length" class="mt-5">
                        <h3 class="text-sm font-black text-zinc-500 dark:text-zinc-400">
                            JUEGOS DE LA VOTACIÓN
                        </h3>

                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <button
                                v-for="candidate in candidates"
                                :key="candidate.id"
                                type="button"
                                class="group flex min-w-0 items-center gap-3 rounded-2xl border-2 p-3 text-left shadow-sm transition-[transform,border-color,box-shadow,background-color] duration-200 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-violet-400/40 motion-reduce:transform-none motion-reduce:transition-none enabled:hover:-translate-y-0.5 enabled:hover:border-violet-500 enabled:hover:shadow-[0_12px_26px_rgba(82,0,255,0.14)] disabled:cursor-not-allowed disabled:opacity-50 sm:gap-4 sm:p-4"
                                :class="isGameSelected(candidate.game.id)
                                    ? 'border-[#5200FF] bg-violet-50 ring-2 ring-[#5200FF]/30 dark:bg-violet-950/30'
                                    : 'border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900'"
                                :aria-pressed="isGameSelected(candidate.game.id)"
                                :disabled="!games.some((game) => game.id === candidate.game.id)"
                                @click="toggleGame(candidate.game.id)"
                            >
                                <span
                                    class="flex h-24 w-[4.75rem] shrink-0 items-center justify-center overflow-hidden rounded-xl bg-zinc-100 p-1.5 dark:bg-zinc-800 sm:h-28 sm:w-20"
                                >
                                    <img
                                        v-if="coverFor(candidate.game.id) && !brokenCoverIds.includes(candidate.game.id)"
                                        :src="coverFor(candidate.game.id) ?? undefined"
                                        :alt="`Portada de ${candidate.game.name}`"
                                        loading="lazy"
                                        class="h-full w-full object-contain transition-transform duration-200 group-hover:scale-105 motion-reduce:transition-none"
                                        @error="markCoverAsBroken(candidate.game.id)"
                                    />
                                    <span v-else class="text-center text-xs text-zinc-500 dark:text-zinc-400">
                                        <PlayingCardsFanIcon class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-violet-600 dark:text-violet-300" aria-hidden="true" /> Sin portada
                                    </span>
                                </span>

                                <span class="min-w-0 flex-1">
                                    <span class="block break-words font-black leading-snug text-zinc-900 dark:text-zinc-100">
                                        {{ candidate.game.name }}
                                    </span>

                                    <span
                                        v-if="isGameSelected(candidate.game.id)"
                                        class="mt-2 inline-flex rounded-full bg-[#5200FF] px-2.5 py-1 text-xs font-bold text-white"
                                    >
                                        ✓ Seleccionado
                                    </span>

                                    <span
                                        v-if="!games.some((game) => game.id === candidate.game.id)"
                                        class="mt-2 block text-sm text-zinc-500 dark:text-zinc-400"
                                    >
                                        Este juego está inactivo o ya no pertenece a tus colecciones.
                                    </span>

                                    <span
                                        v-if="candidate.planned_expansion_id"
                                        class="mt-2 block text-sm font-semibold text-[#5200FF] dark:text-violet-300"
                                    >
                                        <BuildingComplexPlus class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-[#6D28D9] dark:text-[#CCFF00]" :stroke-width="2" aria-hidden="true" /> Expansión prevista
                                    </span>
                                </span>
                            </button>
                        </div>

                    </div>

                    <div class="mt-6">
                        <label
                            for="other-game"
                            class="mb-2 block text-sm font-bold text-zinc-700 dark:text-zinc-300"
                        >
                            Añadir un juego fuera de la votación
                        </label>

                        <select
                            id="other-game"
                            v-model="selectedOtherGameId"
                            class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                        >
                            <option :value="null">Otro juego...</option>
                            <option v-for="game in otherGames" :key="game.id" :value="game.id">
                                {{ game.name }}
                            </option>
                        </select>

                        <p v-if="!otherGames.length" class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">
                            No hay otros juegos activos en tus colecciones.
                        </p>
                    </div>

                    <InputError :message="form.errors.game_ids" />

                </section>

                <!-- ================================================== -->
                <!-- EXPANSIÓN                                          -->
                <!-- ================================================== -->

                <section
                    class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <h2 class="text-xl font-bold">
                        Expansiones jugadas
                    </h2>

                    <p v-if="!selectedGames.length" class="mt-3 text-sm text-zinc-500">
                        Selecciona un juego para elegir su expansión.
                    </p>

                    <div
                        v-for="game in selectedGames"
                        :key="game.id"
                        class="mt-5"
                    >
                        <label
                            :for="`played-expansion-${game.id}`"
                            class="block text-sm font-bold"
                        >
                            {{ game.name }}

                            <span class="font-normal text-zinc-500">
                                (opcional)
                            </span>
                        </label>

                        <select
                            :id="`played-expansion-${game.id}`"
                            v-model="form.expansion_ids[game.id]"
                            :disabled="!game.expansions.length"
                            class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100"
                        >
                            <option :value="null">
                                Sin expansión
                            </option>

                            <option
                                v-for="expansion in game.expansions"
                                :key="expansion.id"
                                :value="expansion.id"
                            >
                                {{ expansion.name }}
                            </option>
                        </select>

                        <p
                            v-if="!game.expansions.length"
                            class="mt-2 text-xs text-zinc-500"
                        >
                            Este juego no tiene
                            expansiones activas.
                        </p>

                    </div>

                    <InputError :message="form.errors.expansion_ids" />
                </section>

                <!-- ================================================== -->
                <!-- FECHA                                              -->
                <!-- ================================================== -->

                <section
                    class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <label
                        class="font-bold"
                    >
                        Fecha de la partida
                    </label>

                    <input
                        v-model="
                            form.played_at
                        "
                        type="date"
                        class="mt-3 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                    />

                    <InputError :message="form.errors.played_at" />
                </section>

                <!-- ================================================== -->
                <!-- JUGADORES                                          -->
                <!-- ================================================== -->

                <section
                    class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <h2
                        class="text-xl font-bold"
                    >
                        Participantes
                    </h2>

                    <p
                        class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"
                    >
                        Los participantes de la votación vienen marcados.
                        Puedes añadir otros jugadores habituales o desmarcar
                        a quienes no jugaron.
                    </p>

                    <div
                        class="mt-5 grid gap-3 sm:grid-cols-2"
                    >
                        <label
                            v-for="participant in players"
                            :key="
                                participant.id
                            "
                            class="cursor-pointer"
                        >
                            <input
                                v-model="
                                    form.player_ids
                                "
                                type="checkbox"
                                :value="
                                    participant.id
                                "
                                class="peer sr-only"
                            />

                            <div class="flex min-h-14 items-center justify-between gap-3 rounded-xl border-2 border-zinc-200 p-4 font-semibold transition-[transform,border-color,background-color,box-shadow] duration-200 hover:-translate-y-0.5 hover:border-violet-400 hover:shadow-[0_8px_20px_rgba(82,0,255,0.10)] peer-focus-visible:ring-4 peer-focus-visible:ring-violet-400/40 peer-checked:border-[#5200FF] peer-checked:bg-violet-50 peer-checked:ring-2 peer-checked:ring-[#5200FF]/20 dark:peer-checked:bg-violet-950/30 motion-reduce:transform-none motion-reduce:transition-none dark:border-zinc-800">
                                <span :class="playerNameColor(participant.name)" class="inline-flex min-w-0 items-center gap-2">
                                    <MeepleIcon class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    <span class="min-w-0 break-words">{{ participant.name }}</span>
                                </span>
                                <span v-if="form.player_ids.includes(participant.id)" aria-hidden="true" class="shrink-0 rounded-full bg-[#5200FF] px-2 py-0.5 text-xs font-bold text-white">✓</span>
                            </div>
                        </label>
                    </div>

                    <InputError :message="form.errors.player_ids" />
                </section>

                <!-- ================================================== -->
                <!-- NOTAS                                              -->
                <!-- ================================================== -->

                <section
                    class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <label
                        class="font-bold"
                    >
                        Notas
                    </label>

                    <textarea
                        v-model="form.notes"
                        rows="4"
                        class="mt-3 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 placeholder:text-zinc-400 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                        placeholder="Ej: Partida muy ajustada..."
                    />

                    <InputError :message="form.errors.notes" />
                </section>

                <!-- ================================================== -->
                <!-- GUARDAR                                            -->
                <!-- ================================================== -->

                <ul
                    v-if="selectedGames.length"
                    aria-label="Juegos que se registrarán"
                    class="flex flex-wrap gap-2"
                >
                    <li
                        v-for="game in selectedGames"
                        :key="game.id"
                        class="min-w-0 max-w-full break-words rounded-xl bg-zinc-100 px-3 py-2 text-sm font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                    >
                        {{ game.name }}
                    </li>
                </ul>

                <button
                    type="submit"
                    :disabled="
                        form.processing ||
                        form.game_ids.length === 0 ||
                        form.player_ids.length === 0
                    "
                    class="w-full rounded-2xl bg-[#5200FF] px-6 py-4 text-lg font-black text-white shadow-[0_12px_28px_rgba(82,0,255,0.18)] transition-[background-color,transform,box-shadow] duration-200 hover:-translate-y-0.5 hover:bg-[#4100cc] hover:shadow-[0_14px_32px_rgba(82,0,255,0.28)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-violet-400/40 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:translate-y-0 motion-reduce:transform-none motion-reduce:transition-none"
                >
                    {{
                        form.processing
                            ? 'Guardando...'
                            : form.game_ids.length > 1
                                ? `Registrar ${form.game_ids.length} partidas`
                                : 'Registrar partida'
                    }}
                </button>
            </form>
        </div>
        <ScrollToTopProgress
            :remember-position="false"
            compact-on-mobile
            hide-near-bottom
            :hide-near-bottom-distance="96"
        />
    </AppLayout>
</template>
