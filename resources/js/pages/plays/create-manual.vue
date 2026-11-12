<script setup lang="ts">
import { playerNameColor } from '@/lib/player-colors';
import MeepleIcon from '@/components/icons/MeepleIcon.vue';
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
} from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import InputError from '@/components/InputError.vue';
import ScrollToTopProgress from '@/components/ScrollToTopProgress.vue';

/*
|--------------------------------------------------------------------------
| Tipos
|--------------------------------------------------------------------------
*/

interface Expansion {
    id: number;
    name: string;
}

interface Game {
    id: number;
    name: string;
    image_url: string | null;

    expansions: Expansion[];
}

interface Player {
    id: number;
    name: string;
}

const props = defineProps<{
    games: Game[];
    players: Player[];

    selected_game_id:
        number | null;
    selected_player_id: number | null;
}>();

/*
|--------------------------------------------------------------------------
| Fecha local de hoy
|--------------------------------------------------------------------------
*/

const today = (): string => {
    const date = new Date();

    const year =
        date.getFullYear();

    const month =
        String(
            date.getMonth() + 1
        ).padStart(
            2,
            '0'
        );

    const day =
        String(
            date.getDate()
        ).padStart(
            2,
            '0'
        );

    return `${year}-${month}-${day}`;
};

/*
|--------------------------------------------------------------------------
| Formulario
|--------------------------------------------------------------------------
*/

const form = useForm({
    game_id:
        props.selected_game_id,

    expansion_id:
        null as number | null,

    played_at:
        today(),

    player_ids:
        (props.selected_player_id !== null ? [props.selected_player_id] : []) as number[],

    notes:
        '',
});

/*
|--------------------------------------------------------------------------
| Juego seleccionado
|--------------------------------------------------------------------------
*/


// Buscar juegos sin distinguir tildes, mayúsculas ni orden de palabras.
const gameSearch = ref('');

const normalizeGameText = (value: string): string =>
    value
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLocaleLowerCase('es')
        .trim();

const gameSearchTerms = computed(() =>
    normalizeGameText(gameSearch.value)
        .split(/\s+/)
        .filter(Boolean)
);

const matchingGamesCount = computed(() =>
    props.games.filter(game =>
        gameSearchTerms.value.every(term =>
            normalizeGameText(game.name).includes(term)
        )
    ).length
);

const searchableGames = computed(() =>
    props.games.filter(game =>
        game.id === Number(form.game_id) ||
        gameSearchTerms.value.every(term =>
            normalizeGameText(game.name).includes(term)
        )
    )
);

// Mostrar primero unas pocas fichas: la colección puede tener cientos de juegos.
// Si ya hay un juego elegido, mantenerlo visible aunque no esté entre los primeros.
const visibleGameLimit = ref(24);
watch(gameSearch, () => {
    visibleGameLimit.value = 24;
});

const visibleGames = computed(() => {
    const firstGames = searchableGames.value.slice(0, visibleGameLimit.value);
    const selected = props.games.find(game => game.id === Number(form.game_id));

    return selected && !firstGames.some(game => game.id === selected.id)
        ? [selected, ...firstGames]
        : firstGames;
});

const brokenCoverIds = ref<number[]>([]);
const markCoverAsBroken = (id: number) => {
    if (!brokenCoverIds.value.includes(id)) {
        brokenCoverIds.value.push(id);
    }
};

// Pulsar de nuevo el mismo juego elimina la selección.
// Al cambiar game_id, el watch existente limpia la expansión anterior.
const toggleGame = (gameId: number): void => {
    form.game_id = Number(form.game_id) === gameId ? null : gameId;
};

const selectedGame = computed(
    () =>
        props.games.find(
            (game) =>
                game.id ===
                form.game_id
        ) ?? null
);

/*
|--------------------------------------------------------------------------
| Expansiones disponibles
|--------------------------------------------------------------------------
*/

const availableExpansions =
    computed(() => {
        return (
            selectedGame.value
                ?.expansions ?? []
        );
    });

/*
|--------------------------------------------------------------------------
| Cambio de juego
|--------------------------------------------------------------------------
*/

watch(
    () => form.game_id,
    (newGameId, oldGameId) => {
        if (oldGameId !== null && newGameId !== oldGameId) {
            form.expansion_id = null;
        }
    }
);

/*
|--------------------------------------------------------------------------
| Guardar
|--------------------------------------------------------------------------
*/

const submit = () => {
    form.transform(({ game_id, expansion_id, ...data }) => ({
        ...data,
        game_ids: game_id === null ? [] : [game_id],
        expansion_ids: game_id === null ? {} : { [game_id]: expansion_id },
    })).post('/plays', {
        onError: (errors) => {
            const gameError = errors.game_ids ?? errors['game_ids.0'];
            if (gameError) {
                form.setError('game_id', gameError);
            }

            const expansionError = errors.expansion_ids ?? errors[`expansion_ids.${form.game_id}`];
            if (expansionError) {
                form.setError('expansion_id', expansionError);
            }
        },
    });
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
                href="/plays"
                class="text-sm font-semibold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
            >
                ← Volver al historial
            </Link>

            <!-- CABECERA -->

            <div class="mt-4">
                <h1
                    class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 sm:text-3xl"
                >
                    Registrar partida
                </h1>
            </div>

            <form
                class="mt-8 space-y-8"
                @submit.prevent="submit"
            >
                <!-- ================================================== -->
                <!-- JUEGO                                              -->
                <!-- ================================================== -->

                <section
                    class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <h2
                        class="text-xl font-bold"
                    >
                        ¿A qué jugasteis?
                    </h2>

                    <p
                        class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"
                    >
                        Busca y selecciona el juego base.
                    </p>

                    <div class="mt-5">
                        <label
                            for="manual-game-search"
                            class="block text-sm font-semibold text-zinc-700 dark:text-zinc-200"
                        >
                            Buscar juego
                        </label>
                        <input
                            id="manual-game-search"
                            v-model="gameSearch"
                            type="search"
                            autocomplete="off"
                            placeholder="Ej.: castillo blanco, agrícola..."
                            class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 outline-none transition focus:border-[#5200FF] focus:ring-4 focus:ring-violet-400/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                        />
                        <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400" aria-live="polite">
                            {{ gameSearch.trim() ? matchingGamesCount + ' juegos encontrados' : props.games.length + ' juegos disponibles' }}
                        </p>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <label
                            v-for="game in visibleGames"
                            :key="game.id"
                            class="group min-w-0 cursor-pointer"
                        >
                            <input
                                :checked="Number(form.game_id) === game.id"
                                type="checkbox"
                                :value="game.id"
                                @change="toggleGame(game.id)"
                                class="peer sr-only"
                            />

                            <div
                                class="flex h-full min-w-0 items-center gap-3 rounded-2xl border-2 border-zinc-200 bg-white p-3 shadow-sm transition-[transform,border-color,box-shadow,background-color] duration-200 group-hover:-translate-y-0.5 group-hover:border-violet-500 group-hover:shadow-[0_12px_26px_rgba(82,0,255,0.14)] peer-focus-visible:ring-4 peer-focus-visible:ring-violet-400/40 peer-checked:border-[#5200FF] peer-checked:bg-violet-50 peer-checked:ring-2 peer-checked:ring-[#5200FF]/30 motion-reduce:transform-none motion-reduce:transition-none dark:border-zinc-800 dark:bg-zinc-900 dark:peer-checked:bg-violet-950/30"
                            >
                                <span class="flex h-28 w-20 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-zinc-100 p-1.5 dark:bg-zinc-800">
                                    <img
                                        v-if="game.image_url && !brokenCoverIds.includes(game.id)"
                                        :src="game.image_url"
                                        :alt="`Portada de ${game.name}`"
                                        loading="lazy"
                                        class="h-full w-full object-contain transition-transform duration-200 group-hover:scale-105 motion-reduce:transition-none"
                                        @error="markCoverAsBroken(game.id)"
                                    />
                                    <span v-else class="text-center text-xs text-zinc-500 dark:text-zinc-400">
                                        <PlayingCardsFanIcon class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-violet-600 dark:text-violet-300" aria-hidden="true" /> Sin portada
                                    </span>
                                </span>

                                <span class="min-w-0 flex-1">
                                    <span class="block break-words font-bold leading-snug text-zinc-900 dark:text-zinc-100">
                                        {{ game.name }}
                                    </span>
                                    <span
                                        v-if="Number(form.game_id) === game.id"
                                        class="mt-2 inline-flex rounded-full bg-[#5200FF] px-2.5 py-1 text-xs font-bold text-white"
                                    >
                                        ✓ Seleccionado
                                    </span>
                                </span>
                            </div>
                        </label>
                    </div>

                    <p
                        v-if="gameSearch.trim() && matchingGamesCount === 0"
                        class="mt-4 text-sm text-zinc-500 dark:text-zinc-400"
                    >
                        No se encontraron juegos con esa búsqueda.
                    </p>

                    <button
                        v-if="searchableGames.length > visibleGameLimit"
                        type="button"
                        class="mt-4 w-full rounded-xl border border-violet-300 px-4 py-3 text-sm font-bold text-[#5200FF] transition hover:border-[#5200FF] hover:bg-violet-50 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-violet-400/30 dark:border-violet-600 dark:text-violet-300 dark:hover:bg-violet-950/30"
                        @click="visibleGameLimit += 24"
                    >
                        Mostrar más juegos ({{ Math.min(visibleGameLimit, searchableGames.length) }} de {{ searchableGames.length }})
                    </button>

                    <InputError :message="form.errors.game_id" />
                </section>

                <!-- ================================================== -->
                <!-- EXPANSIÓN                                          -->
                <!-- ================================================== -->

                <section
                    class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <div>
                        <label
                            for="played-expansion"
                            class="block text-sm font-bold"
                        >
                            Expansión jugada

                            <span class="font-normal text-zinc-500">
                                (opcional)
                            </span>
                        </label>

                        <select
                            id="played-expansion"
                            v-model="form.expansion_id"
                            :disabled="!form.game_id || !availableExpansions.length"
                            class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100"
                        >
                            <option :value="null">
                                Sin expansión
                            </option>

                            <option
                                v-for="expansion in availableExpansions"
                                :key="expansion.id"
                                :value="expansion.id"
                            >
                                {{ expansion.name }}
                            </option>
                        </select>

                        <p
                            v-if="form.game_id && !availableExpansions.length"
                            class="mt-2 text-xs text-zinc-500"
                        >
                            Este juego no tiene
                            expansiones activas.
                        </p>

                        <InputError :message="form.errors.expansion_id" />
                    </div>
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
                <!-- PARTICIPANTES                                      -->
                <!-- ================================================== -->

                <section
                    class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <h2
                        class="text-xl font-bold"
                    >
                        ¿Quién jugó?
                    </h2>

                    <p
                        class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"
                    >
                        Selecciona los participantes
                        reales de la partida.
                    </p>

                    <div
                        class="mt-5 grid gap-3 sm:grid-cols-2"
                    >
                        <label
                            v-for="player in players"
                            :key="player.id"
                            class="cursor-pointer"
                        >
                            <input
                                v-model="
                                    form.player_ids
                                "
                                type="checkbox"
                                :value="player.id"
                                class="peer sr-only"
                            />

                            <div
                                class="flex min-h-14 items-center justify-between gap-3 rounded-xl border-2 border-zinc-200 bg-white p-4 font-semibold text-zinc-900 shadow-sm transition-[transform,border-color,background-color,box-shadow] duration-200 hover:-translate-y-0.5 hover:border-violet-400 hover:shadow-[0_8px_20px_rgba(82,0,255,0.10)] peer-focus-visible:ring-4 peer-focus-visible:ring-violet-400/40 peer-checked:border-[#5200FF] peer-checked:bg-violet-50 peer-checked:ring-2 peer-checked:ring-[#5200FF]/20 motion-reduce:transform-none motion-reduce:transition-none dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-100 dark:peer-checked:bg-violet-950/30"
                            >
                                <span class="min-w-0 break-words"><span :class="['inline-flex items-center gap-1.5', playerNameColor(player.name)]"><MeepleIcon class="h-3.5 w-3.5 shrink-0" />{{ player.name }}</span></span>
                                <span
                                    v-if="form.player_ids.includes(player.id)"
                                    class="shrink-0 rounded-full bg-[#5200FF] px-2 py-0.5 text-xs font-bold text-white"
                                >
                                    ✓
                                </span>
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
                        class="mt-3 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 placeholder:text-zinc-400 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:placeholder:text-zinc-500"
                        placeholder="Ej: Primera partida con Matcha, partida muy igualada..."
                    ></textarea>

                    <InputError :message="form.errors.notes" />
                </section>

                <!-- ================================================== -->
                <!-- GUARDAR                                            -->
                <!-- ================================================== -->

                <button
                    type="submit"
                    :disabled="
                        form.processing
                    "
                    class="w-full rounded-2xl bg-[#5200FF] px-6 py-4 text-lg font-black text-white shadow-[0_12px_28px_rgba(82,0,255,0.18)] transition-[background-color,transform,box-shadow] duration-200 hover:-translate-y-0.5 hover:bg-[#4100cc] hover:shadow-[0_14px_32px_rgba(82,0,255,0.28)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-violet-400/40 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:translate-y-0 motion-reduce:transform-none motion-reduce:transition-none"
                >
                    {{
                        form.processing
                            ? 'Registrando...'
                            : 'Registrar partida'
                    }}
                </button>
            </form>
        </div>
        <ScrollToTopProgress :remember-position="false" />
    </AppLayout>
</template>
