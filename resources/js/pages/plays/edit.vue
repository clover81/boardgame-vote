<script setup lang="ts">
import { playerNameColor } from '@/lib/player-colors';
import MeepleIcon from '@/components/icons/MeepleIcon.vue';
import { BuildingComplexPlus } from '@lucide/vue';
import PlayingCardsFanIcon from '@/components/icons/PlayingCardsFanIcon.vue';
import {
    computed,
    nextTick,
    watch,
    ref,
} from 'vue';

import {
    Head,
    Link,
    useForm,
} from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import AlertError from '@/components/AlertError.vue';
import InputError from '@/components/InputError.vue';
import ScrollToTopProgress from '@/components/ScrollToTopProgress.vue';

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

interface Play {
    id: number;
    game_id: number;

    expansion_id:
        number | null;

    played_at: string;

    notes: string | null;

    player_ids: number[];
}

const props = defineProps<{
    play: Play;
    games: Game[];
    players: Player[];
}>();

/*
|--------------------------------------------------------------------------
| Formulario
|--------------------------------------------------------------------------
*/

const form = useForm({
    game_id:
        props.play.game_id,

    expansion_id:
        props.play.expansion_id,

    played_at:
        props.play.played_at,

    player_ids:
        [...props.play.player_ids],

    notes:
        props.play.notes ?? '',
});

/*
|--------------------------------------------------------------------------
| Juego seleccionado
|--------------------------------------------------------------------------
*/

const selectedGame = computed(
    () =>
        props.games.find(
            (game) =>
                game.id ===
                form.game_id
        ) ?? null
);

const availableExpansions =
    computed(() => {
        return (
            selectedGame.value
                ?.expansions ?? []
        );
    });

// Mostrar solo el juego actual hasta que se solicite cambiarlo.
const isGamePickerOpen = ref(
    !props.games.some((game) => game.id === props.play.game_id)
);
const gameSearch = ref('');
const visibleGameLimit = ref(12);
const failedGameImages = ref<Record<number, boolean>>({});

const normalizeGameSearch = (value: string) =>
    value.normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLocaleLowerCase('es')
        .trim();

const matchingGames = computed(() => {
    const query = normalizeGameSearch(gameSearch.value);
    const matches = props.games.filter((game) =>
        normalizeGameSearch(game.name).includes(query)
    );
    // El juego de la partida permanece visible al abrir el selector.
    return matches.sort((a, b) =>
        Number(b.id === form.game_id) - Number(a.id === form.game_id)
    );
});
const displayedGames = computed(() =>
    matchingGames.value.slice(0, visibleGameLimit.value)
);
watch(gameSearch, () => { visibleGameLimit.value = 12; });

const finishGameSelection = () => {
    isGamePickerOpen.value = false;
    gameSearch.value = '';
    visibleGameLimit.value = 12;
};

/*
|--------------------------------------------------------------------------
| Cambio de juego
|--------------------------------------------------------------------------
|
| Solo reseteamos la expansión cuando el usuario
| cambia realmente el juego.
|
*/

let initialGameId =
    form.game_id;

watch(
    () => form.game_id,
    (newGameId) => {
        if (
            newGameId !==
            initialGameId
        ) {
            form.expansion_id =
                null;
        }

        initialGameId =
            newGameId;
    }
);

/*
|--------------------------------------------------------------------------
| Guardar
|--------------------------------------------------------------------------
*/

const saveError = ref<HTMLDivElement | null>(null);

const submit = () => {
    form.put(`/plays/${props.play.id}`, {
        onError: async () => {
            await nextTick();
            saveError.value?.focus();
        },
    });
};
</script>

<template>
    <Head title="Editar partida" />

    <AppLayout>
        <div
            class="mx-auto w-full max-w-5xl px-4 py-6 sm:px-6"
        >
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
                    Editar partida
                </h1>
            </div>

            <form
                class="mt-6 space-y-5"
                @submit.prevent="submit"
            >
                <!-- ================================================== -->
                <!-- JUEGO                                              -->
                <!-- ================================================== -->

                <section
                    class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm sm:p-5 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <!-- Selector compacto de juego para edicion -->
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-xl font-bold">Juego de la partida</h2>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                Conserva el juego actual o elige otro si te equivocaste.
                            </p>
                        </div>
                        <button
                            type="button"
                            :aria-expanded="isGamePickerOpen"
                            aria-controls="edit-play-game-picker"
                            class="rounded-xl border border-[#5200FF] px-4 py-2 text-sm font-bold text-[#5200FF] transition hover:bg-violet-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#5200FF] dark:text-[#CCFF00] dark:hover:bg-violet-950/30"
                            @click="isGamePickerOpen = !isGamePickerOpen"
                        >
                            {{ isGamePickerOpen ? 'Ocultar buscador' : 'Cambiar juego' }}
                        </button>
                    </div>

                    <div
                        v-if="selectedGame"
                        class="mt-4 flex min-w-0 items-center gap-4 rounded-2xl border border-violet-400/50 bg-violet-50/60 p-3 dark:bg-violet-950/20"
                    >
                        <div class="flex h-28 w-20 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-zinc-100 p-1 dark:bg-zinc-800">
                            <img
                                v-if="selectedGame.image_url && !failedGameImages[selectedGame.id]"
                                :src="selectedGame.image_url"
                                :alt="`Portada de ${selectedGame.name}`"
                                loading="lazy"
                                class="h-full w-full object-contain"
                                @error="failedGameImages[selectedGame.id] = true"
                            />
                            <span v-else class="px-1 text-center text-xs text-zinc-500 dark:text-zinc-400">Sin portada</span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="mb-1 text-xs font-bold uppercase tracking-wide text-violet-600 dark:text-[#CCFF00]">
                                Juego seleccionado
                            </p>
                            <p class="break-words text-lg font-bold leading-snug">{{ selectedGame.name }}</p>
                        </div>
                    </div>
                    <p v-else class="mt-3 text-sm text-amber-700 dark:text-amber-300">
                        Este juego ya no está disponible en tus colecciones. Selecciona otro.
                    </p>

                    <div
                        v-if="isGamePickerOpen"
                        id="edit-play-game-picker"
                        class="mt-5 border-t border-zinc-200 pt-5 dark:border-zinc-800"
                    >
                        <label for="edit-play-game-search" class="block text-sm font-bold">
                            Buscar juego en tu colección
                        </label>
                        <input
                            id="edit-play-game-search"
                            v-model="gameSearch"
                            type="search"
                            autocomplete="off"
                            placeholder="Escribe el nombre del juego..."
                            class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 outline-none transition focus:border-[#5200FF] focus:ring-2 focus:ring-violet-300 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:focus:ring-violet-700"
                        />
                        <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400" aria-live="polite">
                            {{ matchingGames.length }} {{ matchingGames.length === 1 ? 'juego encontrado' : 'juegos encontrados' }}
                        </p>

                        <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <label
                                v-for="game in displayedGames"
                                :key="game.id"
                                class="group relative cursor-pointer"
                            >
                                <input
                                    v-model="form.game_id"
                                    type="radio"
                                    :value="game.id"
                                    class="peer sr-only"
                                    @change="finishGameSelection"
                                />
                                <span
                                    class="flex h-full min-w-0 items-center gap-3 rounded-xl border border-zinc-200 bg-white p-2.5 transition-all duration-200 hover:-translate-y-0.5 hover:border-violet-500 hover:shadow-[0_6px_20px_rgba(82,0,255,0.12)] peer-checked:border-[#5200FF] peer-checked:ring-2 peer-checked:ring-[#5200FF] peer-focus-visible:outline-none peer-focus-visible:ring-2 peer-focus-visible:ring-[#5200FF] dark:border-zinc-800 dark:bg-zinc-900 motion-reduce:transition-none"
                                >
                                    <span class="flex h-20 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-zinc-100 p-1 dark:bg-zinc-800">
                                        <img
                                            v-if="game.image_url && !failedGameImages[game.id]"
                                            :src="game.image_url"
                                            :alt="`Portada de ${game.name}`"
                                            loading="lazy"
                                            class="h-full w-full object-contain"
                                            @error="failedGameImages[game.id] = true"
                                        />
                                        <span v-else class="text-center text-[10px] text-zinc-500 dark:text-zinc-400">Sin portada</span>
                                    </span>
                                    <span class="min-w-0 break-words text-sm font-semibold leading-snug">{{ game.name }}</span>
                                </span>
                            </label>
                        </div>

                        <p v-if="matchingGames.length === 0" class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">
                            {{ selectedGame
                                ? 'No hay coincidencias. El juego seleccionado se mantiene.'
                                : 'No hay coincidencias.' }}
                        </p>
                        <button
                            v-if="matchingGames.length > displayedGames.length"
                            type="button"
                            class="mt-4 w-full rounded-xl border border-violet-500 px-4 py-3 text-sm font-bold text-violet-700 transition hover:bg-violet-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#5200FF] dark:text-violet-300 dark:hover:bg-violet-950/30"
                            @click="visibleGameLimit += 12"
                        >
                            Mostrar más juegos ({{ matchingGames.length - displayedGames.length }} restantes)
                        </button>
                    </div>

                    <InputError :message="form.errors.game_id" />
                </section>

                <!-- ================================================== -->
                <!-- EXPANSIÓN                                          -->
                <!-- ================================================== -->

                <div class="grid gap-5 lg:grid-cols-[1.2fr_1fr]">
                <section
                    v-if="selectedGame"
                    class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm sm:p-5 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <h2
                        class="text-xl font-bold"
                    >
                        <BuildingComplexPlus class="mr-1 inline-block size-5 shrink-0 align-[-0.15em] text-[#6D28D9] dark:text-[#CCFF00]" :stroke-width="2" aria-hidden="true" /> Expansión
                    </h2>

                    <p
                        class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"
                    >
                        Expansión utilizada en esta
                        partida.
                    </p>

                    <div
                        class="mt-5 grid gap-3 sm:grid-cols-2"
                    >
                        <label
                            class="cursor-pointer"
                        >
                            <input
                                v-model="
                                    form.expansion_id
                                "
                                type="radio"
                                :value="null"
                                class="peer sr-only"
                            />

                            <div
                                class="rounded-xl border border-zinc-200 p-4 font-semibold transition peer-checked:border-[#5200FF] peer-checked:bg-violet-50 dark:border-zinc-800 dark:peer-checked:bg-violet-950/30"
                            >
                                <PlayingCardsFanIcon class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-violet-600 dark:text-violet-300" aria-hidden="true" /> Sin expansión
                            </div>
                        </label>

                        <label
                            v-for="expansion in availableExpansions"
                            :key="
                                expansion.id
                            "
                            class="cursor-pointer"
                        >
                            <input
                                v-model="
                                    form.expansion_id
                                "
                                type="radio"
                                :value="
                                    expansion.id
                                "
                                class="peer sr-only"
                            />

                            <div
                                class="rounded-xl border border-zinc-200 p-4 font-semibold transition peer-checked:border-[#5200FF] peer-checked:bg-violet-50 dark:border-zinc-800 dark:peer-checked:bg-violet-950/30"
                            >
                                <BuildingComplexPlus class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-[#6D28D9] dark:text-[#CCFF00]" :stroke-width="2" aria-hidden="true" />
                                {{
                                    expansion.name
                                }}
                            </div>
                        </label>
                    </div>

                    <p
                        v-if="
                            availableExpansions.length ===
                            0
                        "
                        class="mt-4 text-sm text-zinc-500"
                    >
                        Este juego no tiene expansiones
                        registradas.
                    </p>

                    <InputError :message="form.errors.expansion_id" />
                </section>

                <!-- FECHA -->

                <section
                    class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm sm:p-5 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <label for="edit-play-date" class="font-bold">
                        Fecha de la partida
                    </label>

                    <input
                        id="edit-play-date"
                        v-model="
                            form.played_at
                        "
                        type="date"
                        class="mt-3 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 [color-scheme:light] dark:[color-scheme:dark]"
                    />

                    <InputError :message="form.errors.played_at" />
                </section>
                </div>

                <!-- PARTICIPANTES -->

                <section
                    class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm sm:p-5 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <h2
                        class="text-xl font-bold"
                    >
                        Participantes
                    </h2>

                    <div
                        class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3"
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

                            <div class="flex min-h-12 items-center justify-between gap-2 rounded-xl border border-zinc-200 bg-zinc-50 px-3.5 py-2.5 font-semibold text-zinc-900 transition-all duration-200 hover:-translate-y-0.5 hover:border-violet-400 hover:shadow-sm peer-checked:border-violet-500 peer-checked:bg-violet-50 peer-checked:ring-2 peer-checked:ring-violet-400/40 peer-focus-visible:ring-2 peer-focus-visible:ring-[#5200FF] dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-100 dark:hover:border-violet-400 dark:peer-checked:border-violet-400 dark:peer-checked:bg-violet-950/30 motion-reduce:transition-none">
                                <span :class="['inline-flex items-center gap-1.5', playerNameColor(player.name)]"><MeepleIcon class="h-3.5 w-3.5 shrink-0" />{{ player.name }}</span>
                                <svg
                                    v-if="form.player_ids.includes(player.id)"
                                    class="ml-auto h-4 w-4 shrink-0 text-[#5200FF] dark:text-[#CCFF00]"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2.5"
                                    aria-hidden="true"
                                >
                                    <path d="m5 12 4 4L19 6" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </div>
                        </label>
                    </div>

                    <InputError :message="form.errors.player_ids" />
                </section>

                <!-- NOTAS -->

                <section
                    class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm sm:p-5 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <label class="font-bold">
                        Notas
                    </label>

                    <textarea
                        v-model="form.notes"
                        rows="4"
                        class="mt-3 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                        placeholder="Notas de la partida..."
                    />

                    <InputError :message="form.errors.notes" />
                </section>

                <!-- GUARDAR -->

                <div v-if="form.hasErrors" ref="saveError" tabindex="-1">
                    <AlertError
                        title="No se han podido guardar los cambios"
                        :errors="Object.values(form.errors)"
                    />
                </div>

                <button
                    type="submit"
                    :disabled="
                        form.processing
                    "
                    class="w-full rounded-xl bg-[#5200FF] px-6 py-4 text-lg font-bold text-white transition hover:opacity-90 disabled:opacity-50"
                >
                    {{
                        form.processing
                            ? 'Guardando...'
                            : 'Guardar cambios'
                    }}
                </button>
            </form>
        </div>
        <ScrollToTopProgress :remember-position="false" />
    </AppLayout>
</template>
