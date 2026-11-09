<script setup lang="ts">
import { BuildingComplexPlus, Swords } from '@lucide/vue';
import { Link as ChainIcon } from '@lucide/vue';
import PlayingCardsFanIcon from '@/components/icons/PlayingCardsFanIcon.vue';
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import GameCollectionsField from '@/components/GameCollectionsField.vue';
import InputError from '@/components/InputError.vue';
import ScrollToTopProgress from '@/components/ScrollToTopProgress.vue';
import AppLayout from '@/layouts/AppLayout.vue';

interface Collection {
    id: number;
    name: string;
    is_default: boolean;
}

interface BggSearchResult {
    bgg_id: number;
    name: string;
    year: number | null;
}

interface BggGameDetail {
    bgg_id: number;
    name: string;

    image_url: string | null;
    thumbnail_url: string | null;

    year_published: number | null;

    min_players: number | null;
    max_players: number | null;

    min_playtime: number | null;
    max_playtime: number | null;
    playing_time: number | null;

    complexity: number | null;

    suggested_ideal_player_counts: number[];

    description: string;

    bgg_url: string;
}

interface ExistingGame {
    id: number;
    name: string;
}

interface ExistingBggGame {
    id: number;
    name: string;
    image_url: string | null;
    bgg_id: number | null;
    collection_ids: number[];
    collections: {
        id: number;
        name: string;
    }[];
}

interface BggExpansion {
    bgg_id: number;
    name: string;
    bgg_url: string;
}

interface BggImportedGame {
    bgg_id: number;
    name: string;
    image_url: string | null;
    min_players: number | null;
    max_players: number | null;
    min_playtime: number | null;
    max_playtime: number | null;
    complexity: number | null;
    description: string;
    bgg_url: string;
    expansions: BggExpansion[];
}

const bggUrl = ref('');
const importingBgg = ref(false);
const bggImportError = ref('');
const bggDescription = ref('');
const availableBggExpansions = ref<BggExpansion[]>([]);

const existingImportedBggGame = ref<ExistingBggGame | null>(null);

const existingGameForm = useForm({
    collection_ids: [] as number[],
});

const props = defineProps<{
    collections: Collection[];
    default_collection_ids: number[];
}>();

const availableCollectionsForExisting = computed(() => {
    const existingGame = existingImportedBggGame.value;

    if (!existingGame) {
        return [];
    }

    return props.collections.filter(
        (collection) => !existingGame.collection_ids.includes(collection.id)
    );
});

const form = useForm({
    name: '',

    image_url: '',

    min_players: 1 as number | null,
    max_players: 4 as number | null,

    min_playtime: null as number | null,
    max_playtime: null as number | null,

    complexity: null as number | null,

    game_modes: [] as string[],

    short_description: '',
    additional_information: '',

    bgg_url: '',
    youtube_url: '',

    ideal_player_counts: [] as number[],

    collection_ids: [...props.default_collection_ids] as number[],

    bgg_id: null as number | null,

    bgg_expansion_ids: [] as number[],
});

const bggQuery = ref('');
const bggResults = ref<BggSearchResult[]>([]);
const bggSearching = ref(false);
const bggHasSearched = ref(false);
const bggLoading = ref(false);
const bggError = ref('');
const selectedBggId = ref<number | null>(null);
const selectedBggGame = ref<BggGameDetail | null>(null);
const existingBggGame = ref<ExistingGame | null>(null);
const legacyBggGame = ref<ExistingGame | null>(null);

const bggPlaytime = (game: BggGameDetail): string => {
    if (game.min_playtime !== null && game.max_playtime !== null) {
        if (game.min_playtime === game.max_playtime) {
            return `${game.min_playtime} min`;
        }

        return `${game.min_playtime}–${game.max_playtime} min`;
    }

    if (game.playing_time !== null) {
        return `${game.playing_time} min`;
    }

    return 'Sin dato';
};

const searchBgg = async () => {
    selectedBggId.value = null;
    selectedBggGame.value = null;
    existingBggGame.value = null;
    legacyBggGame.value = null;

    const query = bggQuery.value.trim();

    if (query.length < 2) {
        bggError.value = 'Escribe al menos 2 caracteres.';
        return;
    }

    bggSearching.value = true;
    bggHasSearched.value = false;
    bggError.value = '';
    bggResults.value = [];
    existingImportedBggGame.value = null;

    try {
        const response = await fetch(
            `/games/bgg/search?q=${encodeURIComponent(query)}`,
            {
                headers: {
                    Accept: 'application/json',
                },
            }
        );

        const data = await response.json();

        if (!response.ok) {
            bggError.value =
                data?.message ?? 'No se ha podido buscar en BGG.';
            return;
        }

        bggResults.value = data.results ?? [];
        bggHasSearched.value = true;
    } catch {
        bggError.value =
            'No se ha podido conectar con BoardGameGeek.';
    } finally {
        bggSearching.value = false;
    }
};

const loadBggGame = async (result: BggSearchResult) => {
    selectedBggId.value = result.bgg_id;
    selectedBggGame.value = null;
    bggLoading.value = true;
    bggError.value = '';
    existingBggGame.value = null;
    legacyBggGame.value = null;

    try {
        const response = await fetch(
            `/games/bgg/${result.bgg_id}`,
            {
                headers: {
                    Accept: 'application/json',
                },
            }
        );

        const data = await response.json();

        if (!response.ok) {
            bggError.value =
                data?.message ?? 'No se han podido cargar los datos del juego.';
            selectedBggId.value = null;
            return;
        }

        const game = data.game as BggGameDetail;

        selectedBggGame.value = game;
        existingBggGame.value = data.existing_game ?? null;
        legacyBggGame.value = data.legacy_game ?? null;

        // Limpiamos los datos del importador anterior al cambiar de juego.
        existingImportedBggGame.value = null;
        availableBggExpansions.value = [];
        form.bgg_expansion_ids = [];
        bggDescription.value = game.description;

        /*
         * Datos que sí confiamos a BGG.
         */
        form.bgg_id = game.bgg_id;
        form.name = game.name;
        form.image_url = game.image_url ?? '';

        if (game.min_players !== null) {
            form.min_players = game.min_players;
        }

        if (game.max_players !== null) {
            form.max_players = game.max_players;
        }

        form.min_playtime = game.min_playtime;
        form.max_playtime = game.max_playtime;
        form.complexity = game.complexity;
        form.bgg_url = game.bgg_url;

        /*
         * Solo en juegos nuevos precargamos la recomendación BGG.
         * Para los existentes, el backend conserva sus ideales actuales.
         */
        if (!data.existing_game && !data.legacy_game) {
            form.ideal_player_counts = game.suggested_ideal_player_counts ?? [];
        }

        /*
         * NO tocamos:
         *
         * - game_modes
         * - short_description
         * - additional_information
         * - youtube_url
         * - collection_ids
         */
    } catch {
        bggError.value =
            'No se han podido cargar los datos desde BoardGameGeek.';
        selectedBggId.value = null;
    } finally {
        bggLoading.value = false;
    }
};

const importFromBgg = async () => {
    if (!bggUrl.value.trim()) {
        bggImportError.value = 'Introduce una URL de BoardGameGeek.';
        return;
    }

    importingBgg.value = true;
    bggImportError.value = '';

    try {
        const response = await fetch(
            `/games/import/bgg?url=${encodeURIComponent(bggUrl.value)}`,
            {
                headers: {
                    Accept: 'application/json',
                },
            }
        );

        const data = await response.json();

        if (!response.ok) {
            bggImportError.value =
                data?.errors?.url?.[0]
                ?? 'No se ha podido importar el juego.';
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | YA EXISTE
        |--------------------------------------------------------------------------
        */

        existingBggGame.value = null;
        legacyBggGame.value = null;
        selectedBggId.value = null;
        selectedBggGame.value = null;

        if (data.existing_game) {
            existingImportedBggGame.value = data.existing_game;

            /*
             * Este formulario solo añade colecciones nuevas.
             */
            existingGameForm.collection_ids = [];

            /*
             * Si no está en ninguna colección del usuario,
             * preseleccionamos la principal.
             */
            if (data.existing_game.collection_ids.length === 0) {
                existingGameForm.collection_ids = [
                    ...props.default_collection_ids,
                ];
            }

            /*
             * Limpiamos los datos de una importación anterior.
             */
            form.bgg_id = null;
            availableBggExpansions.value = [];
            bggDescription.value = '';

            return;
        }

        // No es duplicado.
        existingImportedBggGame.value = null;

        const game = data.game as BggImportedGame;

        form.bgg_id = game.bgg_id;

        availableBggExpansions.value = game.expansions ?? [];

        /*
         * No marcamos automáticamente ninguna expansión.
         * El usuario decide cuáles posee.
         */
        form.bgg_expansion_ids = [];

        /*
        |--------------------------------------------------------------------------
        | Rellenar formulario
        |--------------------------------------------------------------------------
        */

        form.name = game.name;
        form.image_url = game.image_url ?? '';
        form.min_players = game.min_players;
        form.max_players = game.max_players;
        form.min_playtime = game.min_playtime;
        form.max_playtime = game.max_playtime;
        form.complexity = game.complexity;
        form.bgg_url = game.bgg_url;

        /*
         * No tocamos game_modes, ideal_player_counts, youtube_url ni
         * additional_information porque BGG no nos da una equivalencia
         * segura para nuestra lógica interna.
         */
        bggDescription.value = game.description;
    } catch {
        bggImportError.value =
            'Se ha producido un error al conectar con BoardGameGeek.';
    } finally {
        importingBgg.value = false;
    }
};

const addExistingToCollections = () => {
    if (!existingImportedBggGame.value) {
        return;
    }

    existingGameForm.post(
        `/games/${existingImportedBggGame.value.id}/collections/attach`
    );
};

const playerNumbers = [1, 2, 3, 4, 5, 6, 7, 8];

const submit = () => {
    // Los juegos existentes solo se asocian a nuestras colecciones.
    // No requieren volver a validar ni modificar su ficha global.
    if (existingBggGame.value) {
        form.post(
            `/games/${existingBggGame.value.id}/collections/attach`
        );
        return;
    }

    // Para un juego nuevo mantenemos todas las validaciones.
    form.post('/games');
};
</script>

<template>
    <Head title="Añadir juego" />

    <AppLayout>
        <div class="mx-auto w-full max-w-5xl px-4 py-6 sm:px-6 2xl:max-w-7xl">

            <div class="mb-6">
                <Link
                    href="/games"
                    class="text-sm font-medium text-[#5200FF]"
                >
                    ← Volver a la colección
                </Link>

                <h1 class="mt-3 text-3xl font-bold">
                    Añadir juego
                </h1>

                <p class="mt-2 text-zinc-500">
                    Añade un juego a tu colección.
                </p>
            </div>

            <section
                class="rounded-3xl border border-zinc-200 bg-white p-5 sm:p-6 dark:border-zinc-800 dark:bg-zinc-950"
            >
                <div>
                    <p class="font-black uppercase tracking-wide text-[#5200FF] dark:text-[#CCFF00]">
                        BOARDGAMEGEEK
                    </p>

                    <h2 class="mt-1 text-2xl font-black">
                        Buscar juego
                    </h2>

                    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                        Busca el juego y rellenaremos
                        automáticamente sus datos principales.
                    </p>
                </div>

                <div class="mt-5 flex flex-col gap-3 sm:flex-row">
                    <input
                        v-model="bggQuery"
                        type="search"
                        placeholder="Ej. The White Castle"
                        class="min-w-0 flex-1 rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 transition focus-visible:border-[#5200FF] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[#5200FF]/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                        @keydown.enter.prevent="searchBgg"
                    />

                    <button
                        type="button"
                        :disabled="bggSearching || bggQuery.trim().length < 2"
                        class="rounded-xl bg-[#5200FF] px-5 py-3 font-black text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40"
                        @click="searchBgg"
                    >
                        {{ bggSearching ? 'Buscando...' : 'Buscar' }}
                    </button>
                </div>

                <p
                    v-if="bggError"
                    class="mt-3 text-sm font-semibold text-red-500"
                >
                    {{ bggError }}
                </p>

                <!-- RESULTADOS -->

                <div
                    v-if="!bggSearching && bggResults.length"
                    class="mt-5 space-y-2"
                >
                    <button
                        v-for="result in bggResults"
                        :key="result.bgg_id"
                        type="button"
                        :disabled="bggLoading"
                        class="flex w-full items-center justify-between gap-4 rounded-xl border border-zinc-200 p-4 text-left transition-all duration-200 hover:-translate-y-0.5 hover:border-[#5200FF] hover:bg-violet-50 hover:shadow-md focus-visible:border-[#5200FF] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[#5200FF]/20 disabled:cursor-wait disabled:opacity-50 motion-reduce:transform-none dark:border-zinc-800 dark:hover:bg-violet-950/20"
                        :class="selectedBggId === result.bgg_id
                            ? 'border-[#5200FF] bg-violet-50 dark:bg-violet-950/20'
                            : ''"
                        @click="loadBggGame(result)"
                    >
                        <div class="min-w-0">
                            <p class="truncate font-black">
                                {{ result.name }}
                            </p>

                            <p class="mt-1 text-xs text-zinc-500">
                                BGG #{{ result.bgg_id }}

                                <template v-if="result.year">
                                    · {{ result.year }}
                                </template>
                            </p>
                        </div>

                        <span class="shrink-0 font-bold text-[#5200FF] dark:text-[#CCFF00]">
                            {{ bggLoading && selectedBggId === result.bgg_id
                                ? 'Cargando...'
                                : 'Usar →' }}
                        </span>
                    </button>
                </div>

                <section
                    v-if="selectedBggGame"
                    class="mt-6 overflow-hidden rounded-2xl border border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900"
                >
                    <div class="grid gap-0 sm:grid-cols-[180px_1fr]">
                        <!-- PORTADA -->

                        <div
                            class="flex min-h-64 items-center justify-center bg-zinc-200 dark:bg-zinc-800"
                        >
                            <img
                                v-if="selectedBggGame.image_url"
                                :src="selectedBggGame.image_url"
                                :alt="selectedBggGame.name"
                                class="h-full max-h-72 w-full object-contain"
                            />

                            <span v-else class="text-4xl">
                                <PlayingCardsFanIcon class="mr-1 inline-block size-10 shrink-0 align-[-0.14em] text-violet-600 dark:text-violet-300" aria-hidden="true" />
                            </span>
                        </div>

                        <!-- DATOS -->

                        <div class="p-5 sm:p-6">
                            <div
                                class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"
                            >
                                <div>
                                    <p
                                        class="text-xs font-black uppercase tracking-wide text-[#5200FF] dark:text-[#CCFF00]"
                                    >
                                        Juego seleccionado
                                    </p>

                                    <h3 class="mt-1 text-2xl font-black">
                                        {{ selectedBggGame.name }}
                                    </h3>

                                    <p class="mt-1 text-sm text-zinc-500">
                                        BGG #{{ selectedBggGame.bgg_id }}

                                        <template v-if="selectedBggGame.year_published">
                                            · {{ selectedBggGame.year_published }}
                                        </template>
                                    </p>
                                </div>

                                <span
                                    class="w-fit rounded-full bg-[#CCFF00] px-3 py-1 text-xs font-black text-black"
                                >
                                    BGG
                                </span>
                            </div>

                            <!-- DATOS PRINCIPALES -->

                            <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <div class="rounded-xl bg-white p-3 dark:bg-zinc-950">
                                    <p class="text-xs text-zinc-500">
                                        Jugadores
                                    </p>

                                    <p class="mt-1 font-black">
                                        {{ selectedBggGame.min_players ?? '?' }}
                                        –
                                        {{ selectedBggGame.max_players ?? '?' }}
                                    </p>
                                </div>

                                <div class="rounded-xl bg-white p-3 dark:bg-zinc-950">
                                    <p class="text-xs text-zinc-500">
                                        Duración
                                    </p>

                                    <p class="mt-1 font-black">
                                        {{ bggPlaytime(selectedBggGame) }}
                                    </p>
                                </div>

                                <div class="rounded-xl bg-white p-3 dark:bg-zinc-950">
                                    <p class="text-xs text-zinc-500">
                                        Complejidad
                                    </p>

                                    <p class="mt-1 font-black">
                                        {{ selectedBggGame.complexity ?? '?' }}
                                        <span
                                            v-if="selectedBggGame.complexity !== null"
                                            class="font-normal text-zinc-400"
                                        >
                                            / 5
                                        </span>
                                    </p>
                                </div>

                                <div class="rounded-xl bg-white p-3 dark:bg-zinc-950">
                                    <p class="text-xs text-zinc-500">
                                        Ideal BGG
                                    </p>

                                    <div
                                        v-if="selectedBggGame.suggested_ideal_player_counts.length"
                                        class="mt-1 flex flex-wrap gap-1"
                                    >
                                        <span
                                            v-for="number in selectedBggGame.suggested_ideal_player_counts"
                                            :key="number"
                                            class="flex h-7 min-w-7 items-center justify-center rounded-full bg-[#CCFF00] px-2 text-sm font-black text-black"
                                        >
                                            {{ number }}
                                        </span>
                                    </div>

                                    <p v-else class="mt-1 font-black">
                                        —
                                    </p>
                                </div>
                            </div>

                            <p class="mt-5 text-sm text-zinc-500 dark:text-zinc-400">
                                Estos datos se han copiado al
                                formulario. Puedes modificarlos antes
                                de guardar el juego.
                            </p>
                        </div>
                    </div>
                </section>

                <div
                    v-if="bggHasSearched && !bggSearching && bggQuery.trim().length >= 2 && !bggResults.length && !bggError"
                    class="mt-5 rounded-xl bg-zinc-100 p-5 text-center text-sm text-zinc-500 dark:bg-zinc-900"
                >
                    No se han encontrado resultados.
                </div>
            </section>

            <div
                v-if="form.bgg_id && !existingBggGame && !legacyBggGame && !bggLoading"
                class="mt-5 rounded-2xl border border-green-200 bg-green-50 p-4 dark:border-green-900 dark:bg-green-950/20"
            >
                <p class="font-black text-green-700 dark:text-green-300">
                    ✓ Datos cargados desde BoardGameGeek
                </p>

                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                    Revisa ahora los datos y completa
                    los campos propios de tu colección.
                </p>
            </div>

            <div
                v-if="existingBggGame"
                class="mt-5 rounded-2xl border border-orange-200 bg-orange-50 p-4 dark:border-orange-900 dark:bg-orange-950/20"
            >
                <p class="font-black text-orange-700 dark:text-orange-300">
                    Este juego ya está importado desde BGG
                </p>

                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                    {{ existingBggGame.name }} ya existe.
                    Al guardar no se creará otro juego:
                    lo añadiremos a las colecciones seleccionadas
                    conservando sus datos y su historial.
                </p>
            </div>

            <div
                v-if="legacyBggGame"
                role="status"
                class="mt-5 rounded-2xl border border-orange-200 bg-orange-50 p-4 dark:border-orange-900 dark:bg-orange-950/20"
            >
                <p class="font-black text-orange-700 dark:text-orange-300">
                    ⚠ Ya tienes "{{ legacyBggGame.name }}" en tu colección.
                </p>

                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                    Al guardar no se creará otro juego.
                    Se vinculará tu juego actual con BoardGameGeek,
                    conservando tus datos manuales y tu historial.
                </p>
            </div>

            <section
                class="mt-5 rounded-3xl border border-zinc-200 bg-white p-5 sm:p-6 dark:border-zinc-800 dark:bg-zinc-950"
            >
                <!-- ================================================== -->
                <!-- O PEGAR URL                                        -->
                <!-- ================================================== -->

                <div>
                    <p
                        class="font-bold text-zinc-900 dark:text-zinc-100"
                    >
                        <ChainIcon class="inline-block h-4 w-4 mr-1 shrink-0 align-[-0.15em]" aria-hidden="true" /> O pegar URL / ID
                    </p>

                    <div
                        class="mt-3 flex flex-col gap-3 sm:flex-row"
                    >
                        <input
                            v-model="bggUrl"
                            type="text"
                            class="flex-1 rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 placeholder:text-zinc-400 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                            placeholder="URL de BGG o ID 371942"
                            @keyup.enter.prevent="
                                importFromBgg
                            "
                        />

                        <button
                            type="button"
                            :disabled="
                                importingBgg
                            "
                            class="rounded-xl border border-[#5200FF] px-5 py-3 font-bold text-[#5200FF] transition hover:bg-violet-50 disabled:opacity-50 dark:text-violet-300 dark:hover:bg-violet-950/30"
                            @click="
                                importFromBgg
                            "
                        >
                            {{
                                importingBgg
                                    ? 'Importando...'
                                    : 'Importar'
                            }}
                        </button>
                    </div>

                    <p
                        v-if="
                            bggImportError
                        "
                        class="mt-3 text-sm font-semibold text-red-500"
                    >
                        {{ bggImportError }}
                    </p>
                </div>

                <div
                    v-if="existingImportedBggGame"
                    class="mt-6 rounded-2xl border-2 border-[#FF6B00] bg-orange-50 p-6 dark:bg-orange-950/20"
                >
                    <div
                        class="flex flex-col gap-5 sm:flex-row"
                    >
                        <!-- PORTADA -->

                        <div
                            class="h-32 w-28 shrink-0 overflow-hidden rounded-xl bg-zinc-100 dark:bg-zinc-900"
                        >
                            <img
                                v-if="
                                    existingImportedBggGame.image_url
                                "
                                :src="
                                    existingImportedBggGame.image_url
                                "
                                :alt="
                                    existingImportedBggGame.name
                                "
                                class="h-full w-full object-cover"
                            />

                            <div
                                v-else
                                class="flex h-full items-center justify-center text-3xl"
                            >
                                <PlayingCardsFanIcon class="mr-1 inline-block size-8 shrink-0 align-[-0.14em] text-violet-600 dark:text-violet-300" aria-hidden="true" />
                            </div>
                        </div>

                        <div class="flex-1">
                            <p
                                class="font-black text-[#FF6B00]"
                            >
                                ✓ YA ESTÁ EN EL CATÁLOGO
                            </p>

                            <h3
                                class="mt-1 text-2xl font-black text-zinc-900 dark:text-zinc-100"
                            >
                                {{
                                    existingImportedBggGame.name
                                }}
                            </h3>

                            <p
                                class="mt-2 text-sm text-zinc-600 dark:text-zinc-300"
                            >
                                No crearemos otra ficha.
                                Puedes añadir este juego a
                                otras colecciones.
                            </p>

                            <!-- YA ESTÁ -->

                            <div
                                v-if="
                                    existingImportedBggGame
                                        .collections
                                        .length
                                "
                                class="mt-4"
                            >
                                <p
                                    class="text-sm font-bold text-zinc-500"
                                >
                                    Actualmente está en:
                                </p>

                                <div
                                    class="mt-2 flex flex-wrap gap-2"
                                >
                                    <span
                                        v-for="collection in existingImportedBggGame.collections"
                                        :key="
                                            collection.id
                                        "
                                        class="rounded-full bg-[#CCFF00] px-3 py-1 text-sm font-bold text-black"
                                    >
                                        ✓
                                        {{
                                            collection.name
                                        }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- AÑADIR A OTRAS -->

                    <div
                        v-if="
                            availableCollectionsForExisting.length
                        "
                        class="mt-6 border-t border-orange-200 pt-5 dark:border-orange-900"
                    >
                        <p
                            class="font-bold text-zinc-900 dark:text-zinc-100"
                        >
                            Añadir a otras colecciones
                        </p>

                        <div
                            class="mt-4 grid gap-3 sm:grid-cols-2"
                        >
                            <label
                                v-for="collection in availableCollectionsForExisting"
                                :key="
                                    collection.id
                                "
                                class="cursor-pointer"
                            >
                                <input
                                    v-model="
                                        existingGameForm.collection_ids
                                    "
                                    type="checkbox"
                                    :value="
                                        collection.id
                                    "
                                    class="peer sr-only"
                                />

                                <div
                                    class="rounded-xl border border-zinc-200 bg-white p-4 font-semibold transition peer-checked:border-[#5200FF] peer-checked:bg-violet-50 dark:border-zinc-800 dark:bg-zinc-900 dark:peer-checked:bg-violet-950/30"
                                >
                                    {{
                                        collection.name
                                    }}
                                </div>
                            </label>
                        </div>

                        <p
                            v-if="
                                existingGameForm.errors
                                    .collection_ids
                            "
                            class="mt-3 text-sm text-red-500"
                        >
                            {{
                                existingGameForm.errors
                                    .collection_ids
                            }}
                        </p>

                        <button
                            type="button"
                            :disabled="
                                existingGameForm.processing ||
                                existingGameForm
                                    .collection_ids
                                    .length === 0
                            "
                            class="mt-5 rounded-xl bg-[#5200FF] px-5 py-3 font-bold text-white transition hover:opacity-90 disabled:opacity-40"
                            @click="
                                addExistingToCollections
                            "
                        >
                            {{
                                existingGameForm.processing
                                    ? 'Añadiendo...'
                                    : 'Añadir a colecciones'
                            }}
                        </button>
                    </div>

                    <!-- YA ESTÁ EN TODAS -->

                    <div
                        v-else
                        class="mt-6 rounded-xl bg-green-100 p-4 text-sm font-semibold text-green-800 dark:bg-green-950/30 dark:text-green-300"
                    >
                        ✓ Este juego ya está incluido en
                        todas tus colecciones.
                    </div>

                    <Link
                        :href="
                            `/games/${existingImportedBggGame.id}`
                        "
                        class="mt-5 inline-block font-bold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
                    >
                        Ver ficha del juego →
                    </Link>
                </div>

                <div
                    v-if="bggDescription"
                    class="mt-5 rounded-xl bg-zinc-100 p-4 dark:bg-zinc-900"
                >
                    <p
                        class="font-bold text-zinc-900 dark:text-zinc-100"
                    >
                        Descripción recibida de BGG
                    </p>

                    <p
                        class="mt-2 max-h-48 overflow-y-auto whitespace-pre-line text-sm leading-6 text-zinc-600 dark:text-zinc-300"
                    >
                        {{ bggDescription }}
                    </p>

                    <p
                        class="mt-3 text-xs text-zinc-500"
                    >
                        La descripción de BGG puede estar en
                        inglés. Tu descripción corta en español
                        sigue siendo editable manualmente.
                    </p>
                </div>

                <!-- ================================================== -->
                <!-- EXPANSIONES BGG                                    -->
                <!-- ================================================== -->

                <div
                    v-if="availableBggExpansions.length"
                    class="mt-6"
                >
                    <div
                        class="border-t border-zinc-200 pt-5 dark:border-zinc-800"
                    >
                        <p
                            class="font-bold text-zinc-900 dark:text-zinc-100"
                        >
                            <BuildingComplexPlus class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-[#6D28D9] dark:text-[#CCFF00]" :stroke-width="2" aria-hidden="true" /> Expansiones encontradas
                        </p>

                        <p
                            class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"
                        >
                            Marca únicamente las expansiones
                            que quieras añadir a tu colección.
                        </p>

                        <div
                            class="mt-4 grid gap-3 sm:grid-cols-2"
                        >
                            <label
                                v-for="expansion in availableBggExpansions"
                                :key="expansion.bgg_id"
                                class="cursor-pointer"
                            >
                                <input
                                    v-model="form.bgg_expansion_ids"
                                    type="checkbox"
                                    :value="expansion.bgg_id"
                                    class="peer sr-only"
                                />

                                <div
                                    class="rounded-xl border border-zinc-200 bg-white p-4 transition peer-checked:border-[#5200FF] peer-checked:bg-violet-50 peer-checked:ring-1 peer-checked:ring-[#5200FF] dark:border-zinc-800 dark:bg-zinc-900 dark:peer-checked:bg-violet-950/30"
                                >
                                    <div class="flex gap-3">
                                        <div class="text-xl">
                                            <BuildingComplexPlus class="inline-block size-5 shrink-0 align-[-0.15em] text-[#6D28D9] dark:text-[#CCFF00]" :stroke-width="2" aria-hidden="true" />
                                        </div>

                                        <div>
                                            <p
                                                class="font-bold text-zinc-900 dark:text-zinc-100"
                                            >
                                                {{ expansion.name }}
                                            </p>

                                            <p
                                                class="mt-1 text-xs text-zinc-500 dark:text-zinc-400"
                                            >
                                                BGG #
                                                {{ expansion.bgg_id }}
                                            </p>
                                        </div>
                                    </div>

                                    <p
                                        class="mt-3 text-sm font-semibold"
                                        :class="
                                            form.bgg_expansion_ids.includes(
                                                expansion.bgg_id
                                            )
                                                ? 'text-[#5200FF] dark:text-[#CCFF00]'
                                                : 'text-zinc-400'
                                        "
                                    >
                                        {{
                                            form.bgg_expansion_ids.includes(
                                                expansion.bgg_id
                                            )
                                                ? '✓ Se añadirá'
                                                : 'No añadir'
                                        }}
                                    </p>
                                </div>
                            </label>
                        </div>

                        <p
                            class="mt-4 text-sm text-zinc-500 dark:text-zinc-400"
                        >
                            {{ form.bgg_expansion_ids.length }}

                            {{
                                form.bgg_expansion_ids.length === 1
                                    ? 'expansión seleccionada'
                                    : 'expansiones seleccionadas'
                            }}
                        </p>
                    </div>
                </div>

                <div
                    v-else-if="form.bgg_id"
                    class="mt-5 rounded-xl bg-zinc-100 p-4 text-sm text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400"
                >
                    BGG no ha devuelto expansiones
                    relacionadas con este juego.
                </div>
            </section>

            <form
                class="mt-6 space-y-6 rounded-2xl border p-6"
                @submit.prevent="submit"
            >

                <!-- Nombre -->

                <div>
                    <label class="mb-2 block font-semibold">
                        Nombre
                    </label>

                    <input
                        v-model="form.name"
                        type="text"
                        class="w-full rounded-xl border px-4 py-3"
                        placeholder="The White Castle"
                    />

                    <InputError :message="form.errors.name" />
                </div>


                <!-- Jugadores admitidos -->

                <div>
                    <h2 class="mb-3 font-semibold">
                        Jugadores admitidos
                    </h2>

                    <div class="grid grid-cols-2 gap-4">

                        <div>
                            <label class="mb-1 block text-sm">
                                Mínimo
                            </label>

                            <input
                                v-model.number="form.min_players"
                                type="number"
                                min="1"
                                class="w-full rounded-xl border px-4 py-3"
                            />
                        </div>

                        <div>
                            <label class="mb-1 block text-sm">
                                Máximo
                            </label>

                            <input
                                v-model.number="form.max_players"
                                type="number"
                                min="1"
                                class="w-full rounded-xl border px-4 py-3"
                            />
                        </div>

                    </div>
                </div>


                <!-- Jugadores ideales -->

                <div>
                    <h2 class="font-semibold">
                        Número ideal de jugadores
                    </h2>

                    <p class="mt-1 text-sm text-zinc-500">
                        Puedes seleccionar varios.
                    </p>

                    <div class="mt-3 flex flex-wrap gap-2">

                        <label
                            v-for="number in playerNumbers"
                            :key="number"
                            class="cursor-pointer"
                        >
                            <input
                                v-model="form.ideal_player_counts"
                                type="checkbox"
                                :value="number"
                                class="peer sr-only"
                            />

                            <span
                                class="
                                    flex h-11 w-11
                                    items-center justify-center
                                    rounded-full border
                                    font-semibold
                                    peer-checked:border-[#CCFF00]
                                    peer-checked:bg-[#CCFF00]
                                    peer-checked:text-black
                                "
                            >
                                {{ number }}
                            </span>
                        </label>

                    </div>

                    <p
                        v-if="form.bgg_id && form.ideal_player_counts.length"
                        class="mt-2 text-xs text-zinc-500 dark:text-zinc-400"
                    >
                        Sugerido según las votaciones de jugadores
                        de BoardGameGeek. Puedes modificarlo.
                    </p>

                    <InputError :message="form.errors.ideal_player_counts" />
                </div>


                <!-- Duración -->

                <div>
                    <h2 class="mb-3 font-semibold">
                        Duración aproximada
                    </h2>

                    <div class="grid grid-cols-2 gap-4">

                        <input
                            v-model.number="form.min_playtime"
                            type="number"
                            placeholder="Mín. minutos"
                            class="rounded-xl border px-4 py-3"
                        />

                        <input
                            v-model.number="form.max_playtime"
                            type="number"
                            placeholder="Máx. minutos"
                            class="rounded-xl border px-4 py-3"
                        />

                    </div>
                </div>


                <!-- Dificultad -->

                <div>
                    <label class="mb-2 block font-semibold">
                        Dificultad
                    </label>

                    <input
                        v-model.number="form.complexity"
                        type="number"
                        min="1"
                        max="5"
                        step="0.01"
                        class="w-full rounded-xl border px-4 py-3"
                        placeholder="Ej: 2.82"
                    />
                </div>


                <!-- Modalidades de juego -->

                <div>
                    <fieldset>
                        <legend class="mb-3 font-semibold">
                            Modalidades de juego
                        </legend>

                        <div class="space-y-3">
                            <label class="flex cursor-pointer items-center gap-3">
                                <input
                                    v-model="form.game_modes"
                                    type="checkbox"
                                    value="competitive"
                                    class="h-5 w-5 accent-[#5200FF]"
                                />
                                <span><Swords class="mr-1 inline h-3.5 w-3.5 shrink-0 text-orange-400" /> Competitivo</span>
                            </label>

                            <label class="flex cursor-pointer items-center gap-3">
                                <input
                                    v-model="form.game_modes"
                                    type="checkbox"
                                    value="cooperative"
                                    class="h-5 w-5 accent-[#5200FF]"
                                />
                                <span>Cooperativo</span>
                            </label>

                            <label class="flex cursor-pointer items-center gap-3">
                                <input
                                    v-model="form.game_modes"
                                    type="checkbox"
                                    value="semi_cooperative"
                                    class="h-5 w-5 accent-[#5200FF]"
                                />
                                <span>Semicooperativo</span>
                            </label>
                        </div>

                        <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">
                            Puedes seleccionar varias modalidades.
                            Si no las conoces, puedes dejarlas sin marcar.
                        </p>

                        <InputError :message="form.errors.game_modes" />
                    </fieldset>
                </div>


                <!-- Descripción -->

                <div>
                    <label class="mb-2 block font-semibold">
                        Descripción corta
                    </label>

                    <textarea
                        v-model="form.short_description"
                        rows="4"
                        class="w-full rounded-xl border px-4 py-3"
                        placeholder="¿De qué va el juego?"
                    />
                </div>


                <!-- Información adicional -->

                <div>
                    <label class="mb-2 block font-semibold">
                        Información adicional
                    </label>

                    <textarea
                        v-model="form.additional_information"
                        rows="3"
                        class="w-full rounded-xl border px-4 py-3"
                        placeholder="Expansiones, variantes, notas..."
                    />
                </div>


                <!-- URLs -->

                <div class="space-y-4">

                    <input
                        v-model="form.image_url"
                        type="url"
                        placeholder="URL de la portada"
                        class="w-full rounded-xl border px-4 py-3"
                    />

                    <input
                        v-model="form.bgg_url"
                        type="url"
                        placeholder="Enlace de BoardGameGeek"
                        class="w-full rounded-xl border px-4 py-3"
                    />

                    <input
                        v-model="form.youtube_url"
                        type="url"
                        placeholder="Tutorial de YouTube"
                        class="w-full rounded-xl border px-4 py-3"
                    />

                </div>


                <GameCollectionsField
                    v-model="form.collection_ids"
                    :collections="collections"
                    :error="form.errors.collection_ids"
                />

                <!-- Guardar -->

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="
                        w-full rounded-xl
                        bg-[#5200FF]
                        px-6 py-3
                        font-semibold text-white
                        transition
                        hover:opacity-90
                        disabled:opacity-50
                    "
                >
                    {{
                        form.processing
                            ? 'Guardando...'
                            : existingBggGame
                              ? 'Añadir a mis colecciones'
                              : 'Guardar juego'
                    }}
                </button>

            </form>
        </div>
        <ScrollToTopProgress :remember-position="false" />
    </AppLayout>
</template>
