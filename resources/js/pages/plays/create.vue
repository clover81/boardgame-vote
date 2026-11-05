<script setup lang="ts">
import {
    computed,
    watch,
} from 'vue';

import {
    Head,
    Link,
    useForm,
} from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import InputError from '@/components/InputError.vue';

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

    played_at:
        props.initial_played_at ?? props.defaults.played_at,

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
    form.post('/plays');
};
</script>

<template>
    <Head title="Registrar partida" />

    <AppLayout>
        <div
            class="mx-auto w-full max-w-5xl px-4 py-6 sm:px-6"
        >
            <!-- VOLVER -->

            <Link
                :href="`/polls/${poll.id}`"
                class="text-sm font-semibold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
            >
                ← Volver a la votación
            </Link>

            <!-- CABECERA -->

            <div class="mt-4">
                <p
                    class="font-semibold text-[#5200FF]"
                >
                    REGISTRAR PARTIDA
                </p>

                <h1
                    class="mt-1 text-3xl font-bold text-zinc-900 dark:text-zinc-100"
                >
                    ¿Qué jugasteis finalmente?
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
                        v-if="!poll.has_clear_winner && candidates.length"
                        class="mb-2 font-black text-orange-700 dark:text-orange-300"
                    >
                        Resultado: empate
                    </p>

                    <p
                        class="font-black text-[#5200FF] dark:text-violet-300"
                    >
                        🎲 ¿Qué acabasteis jugando?
                    </p>

                    <p
                        v-if="initialGameId !== null"
                        class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-300"
                    >
                        Hemos preseleccionado el ganador
                        de la votación cuando existe uno
                        claro. Cámbialo si finalmente
                        jugasteis otro juego.
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
                                class="rounded-2xl border p-4 text-left transition disabled:cursor-not-allowed disabled:opacity-50"
                                :class="isGameSelected(candidate.game.id)
                                    ? 'border-[#5200FF] bg-violet-50 ring-2 ring-[#5200FF] dark:bg-violet-950/30'
                                    : 'border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900'"
                                :aria-pressed="isGameSelected(candidate.game.id)"
                                :disabled="!games.some((game) => game.id === candidate.game.id)"
                                @click="toggleGame(candidate.game.id)"
                            >
                                <p class="font-black text-zinc-900 dark:text-zinc-100">
                                    <span v-if="isGameSelected(candidate.game.id)" aria-hidden="true">✓ </span>
                                    {{ candidate.game.name }}
                                </p>

                                <p
                                    v-if="!games.some((game) => game.id === candidate.game.id)"
                                    class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"
                                >
                                    Este juego está inactivo o ya no pertenece a tus colecciones.
                                </p>

                                <p
                                    v-if="candidate.planned_expansion_id"
                                    class="mt-1 text-sm font-semibold text-[#5200FF] dark:text-violet-300"
                                >
                                    🧩 Expansión prevista
                                </p>
                            </button>
                        </div>

                    </div>

                    <div class="mt-6">
                        <label
                            for="other-game"
                            class="mb-2 block text-sm font-bold text-zinc-700 dark:text-zinc-300"
                        >
                            ¿Jugasteis otro?
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
                        Dejamos seleccionados los
                        participantes de la votación,
                        pero puedes corregirlos.
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

                            <div
                                class="rounded-xl border border-zinc-200 p-4 font-semibold transition peer-checked:border-[#5200FF] peer-checked:bg-[#5200FF] peer-checked:text-white dark:border-zinc-800"
                            >
                                {{
                                    participant.name
                                }}
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

                <button
                    type="submit"
                    :disabled="
                        form.processing ||
                        form.game_ids.length === 0 ||
                        form.player_ids.length === 0
                    "
                    class="w-full rounded-2xl bg-[#5200FF] px-6 py-4 text-lg font-black text-white transition hover:opacity-90 disabled:opacity-40"
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
    </AppLayout>
</template>
