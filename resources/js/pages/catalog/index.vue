<script setup lang="ts">
import YoutubeLogoIcon from '@/components/icons/YoutubeLogoIcon.vue';
import { ChevronDown, Link as ChainIcon, UsersRound, HourglassCog, Swords, BrainCog } from '@lucide/vue';
import PlayingCardsFanIcon from '@/components/icons/PlayingCardsFanIcon.vue';

import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import ScrollToTopProgress from '@/components/ScrollToTopProgress.vue';

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
    game_mode: string | null;
    game_modes: string[];
    short_description: string | null;
    bgg_url: string | null;
    youtube_url: string | null;
    ideal_player_counts: number[];
    collections: Collection[];
}

const props = defineProps<{
    catalogToken: string;
    catalogBasePath?: string;
    catalogOwnerName?: string;
    collections: Collection[];
    games: Game[];
}>();

const search = ref('');
const playerCount = ref<number | ''>('');
const collectionId = ref<number | ''>('');
const durationLimit = ref<number | ''>('');
const difficulty = ref<'' | 'light' | 'medium' | 'heavy'>('');
const selectedGameMode = ref('');
const showAdvancedFilters = ref(false);

const brokenCoverIds = ref<number[]>([]);

const markCoverAsBroken = (gameId: number) => {
    if (!brokenCoverIds.value.includes(gameId)) {
        brokenCoverIds.value.push(gameId);
    }
};

const gameModeLabels: Record<string, string> = {
    competitive: 'Competitivo',
    cooperative: 'Cooperativo',
    semi_cooperative: 'Semicooperativo',
    solo: 'Solitario',
    unclassified: 'Sin clasificar',
};

const catalogGameModes = (game: Game): string[] =>
    game.game_modes.length ? game.game_modes : [game.max_players === 1 ? 'solo' : 'unclassified'];

const gameModeOptions = computed(() => {
    const availableModes = new Set(props.games.flatMap(catalogGameModes));

    return Object.entries(gameModeLabels).filter(([mode]) => availableModes.has(mode));
});

const playerOptions = computed(() =>
    [...new Set(props.games.flatMap(game => game.ideal_player_counts))]
        .sort((a, b) => a - b),
);

const normalizeText = (text: string): string =>
    text
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLocaleLowerCase()
        .trim();

const filteredGames = computed(() => {
    const terms = normalizeText(search.value).split(/\s+/).filter(Boolean);
    const players = Number(playerCount.value);
    const collection = Number(collectionId.value);

    // Filtrar primero por las opciones elegidas; después buscar entre esos juegos.
    const candidates = props.games.filter(game => {
        const matchesPlayers =
            !players || game.ideal_player_counts.includes(players);

        const matchesCollection =
            !collection ||
            game.collections.some(c => c.id === collection);

        const duration =
            game.max_playtime ?? game.min_playtime;

        const matchesDuration =
            durationLimit.value === '' ||
            (duration !== null &&
                duration <= Number(durationLimit.value));

        const complexity = game.complexity === null
            ? null
            : Number(game.complexity);

        const matchesDifficulty =
            difficulty.value === '' ||
            (complexity !== null &&
                Number.isFinite(complexity) &&
                (
                    (difficulty.value === 'light' && complexity <= 2) ||
                    (difficulty.value === 'medium' && complexity > 2 && complexity <= 3) ||
                    (difficulty.value === 'heavy' && complexity > 3)
                ));

        const matchesGameMode =
            !selectedGameMode.value ||
            catalogGameModes(game).includes(selectedGameMode.value);

        return matchesPlayers &&
            matchesCollection &&
            matchesDuration &&
            matchesDifficulty &&
            matchesGameMode;
    });

    if (!terms.length) return candidates;

    // Primero títulos que empiezan por lo escrito; después títulos que lo contienen.
    const prefix = terms.join(' ');
    const prefixMatches = candidates.filter(game =>
        normalizeText(game.name).startsWith(prefix)
    );
    if (prefixMatches.length) return prefixMatches;

    const titleMatches = candidates.filter(game =>
        terms.every(term => normalizeText(game.name).includes(term))
    );
    if (titleMatches.length) return titleMatches;

    // Sin títulos coincidentes, se conserva la búsqueda por temática.
    return candidates.filter(game => {
        const searchable = normalizeText(
            `${game.name} ${game.short_description ?? ''}`
        );
        return terms.every(term => searchable.includes(term));
    });
});

type SortBy =
    | 'name'
    | 'name_desc'
    | 'duration_asc'
    | 'duration_desc'
    | 'difficulty_asc'
    | 'difficulty_desc';

const sortBy = ref<SortBy>('name');


type FilterKey =
    | 'search'
    | 'players'
    | 'collection'
    | 'duration'
    | 'difficulty'
    | 'mode';

interface FilterChip {
    key: FilterKey;
    label: string;
}

const activeFilterChips = computed<FilterChip[]>(() => {
    const chips: FilterChip[] = [];

    if (search.value.trim()) {
        chips.push({
            key: 'search',
            label: `Buscar: ${search.value.trim()}`,
        });
    }

    if (playerCount.value !== '') {
        chips.push({
            key: 'players',
            label: `Ideal: ${playerCount.value} ${
                Number(playerCount.value) === 1 ? 'jugador' : 'jugadores'
            }`,
        });
    }


    if (collectionId.value !== '') {
        const collection = props.collections.find(
            c => c.id === Number(collectionId.value)
        );

        chips.push({
            key: 'collection',
            label: `Colección: ${collection?.name ?? 'Seleccionada'}`,
        });
    }

    if (durationLimit.value !== '') {
        chips.push({
            key: 'duration',
            label: `Hasta ${durationLimit.value} min`,
        });
    }

    if (difficulty.value !== '') {
        const labels = {
            light: 'Ligera',
            medium: 'Media',
            heavy: 'Exigente',
        };

        chips.push({
            key: 'difficulty',
            label: `Dificultad: ${labels[difficulty.value]}`,
        });
    }

    if (selectedGameMode.value !== '') {
        chips.push({
            key: 'mode',
            label: gameModeLabels[selectedGameMode.value]
                ?? selectedGameMode.value,
        });
    }

    return chips;
});

const advancedFilterCount = computed(() =>
    activeFilterChips.value.filter(chip => chip.key !== 'search').length,
);

const removeFilter = (key: FilterKey) => {
    switch (key) {
        case 'search':
            search.value = '';
            break;
        case 'players':
            playerCount.value = '';
            break;
        case 'collection':
            collectionId.value = '';
            break;
        case 'duration':
            durationLimit.value = '';
            break;
        case 'difficulty':
            difficulty.value = '';
            break;
        case 'mode':
            selectedGameMode.value = '';
            break;
    }
};

const filtersStorageKey = `catalog-filters:${props.catalogToken}`;

// Recuperar los filtros al regresar al catálogo.
onMounted(() => {
    const saved = sessionStorage.getItem(filtersStorageKey);

    if (!saved) return;

    try {
        const filters = JSON.parse(saved);

        search.value = filters.search ?? '';
        const savedPlayerCount = Number(filters.playerCount);
        playerCount.value = playerOptions.value.includes(savedPlayerCount) ? savedPlayerCount : '';
        const savedCollectionId = Number(filters.collectionId);
        collectionId.value = props.collections.find(collection => collection.id === savedCollectionId)?.id ?? '';
        durationLimit.value = filters.durationLimit ?? '';
        difficulty.value = filters.difficulty ?? '';
        selectedGameMode.value = gameModeOptions.value.some(([mode]) => mode === filters.selectedGameMode)
            ? filters.selectedGameMode
            : '';
        sortBy.value = filters.sortBy ?? 'name';
        showAdvancedFilters.value = filters.showAdvancedFilters === true;
    } catch {
        sessionStorage.removeItem(filtersStorageKey);
    }
});

// Guardar los cambios automáticamente.
watch(
    [
        search,
        playerCount,
        collectionId,
        durationLimit,
        difficulty,
        selectedGameMode,
        sortBy,
        showAdvancedFilters,
    ],
    () => {
        sessionStorage.setItem(
            filtersStorageKey,
            JSON.stringify({
                search: search.value,
                playerCount: playerCount.value,
                collectionId: collectionId.value,
                durationLimit: durationLimit.value,
                difficulty: difficulty.value,
                selectedGameMode: selectedGameMode.value,
                sortBy: sortBy.value,
                showAdvancedFilters: showAdvancedFilters.value,
            }),
        );
    },
);

const sortedGames = computed(() => {
    const getDuration = (game: Game): number | null =>
        game.max_playtime ?? game.min_playtime;

    const getDifficulty = (game: Game): number | null => {
        if (game.complexity === null) return null;

        const value = Number(game.complexity);

        return Number.isFinite(value) ? value : null;
    };

    const compareNumbers = (
        a: number | null,
        b: number | null,
        descending = false,
    ): number => {
        // Juegos sin datos siempre al final.
        if (a === null && b === null) return 0;
        if (a === null) return 1;
        if (b === null) return -1;

        return descending ? b - a : a - b;
    };

    return [...filteredGames.value].sort((a, b) => {
        let result = 0;

        switch (sortBy.value) {
            case 'name_desc':
                result = b.name.localeCompare(a.name, 'es');
                break;

            case 'duration_asc':
                result = compareNumbers(
                    getDuration(a), getDuration(b)
                );
                break;

            case 'duration_desc':
                result = compareNumbers(
                    getDuration(a), getDuration(b), true
                );
                break;

            case 'difficulty_asc':
                result = compareNumbers(
                    getDifficulty(a), getDifficulty(b)
                );
                break;

            case 'difficulty_desc':
                result = compareNumbers(
                    getDifficulty(a), getDifficulty(b), true
                );
                break;
        }

        // En caso de empate, ordenar por nombre.
        return result || a.name.localeCompare(b.name, 'es');
    });
});

const clearFilters = () => {
    search.value = '';
    playerCount.value = '';
    collectionId.value = '';
    durationLimit.value = '';
    difficulty.value = '';
    selectedGameMode.value = '';
};

const hasFilters = computed(() =>
    search.value.trim() !== '' ||
    playerCount.value !== '' ||
    collectionId.value !== '' ||
    durationLimit.value !== '' ||
    difficulty.value !== '' ||
    selectedGameMode.value !== ''
);

const formatDuration = (game: Game) => {
    if (game.min_playtime === null) {
        return game.max_playtime === null ? 'Sin datos' : `${game.max_playtime} min`;
    }

    if (
        game.max_playtime === null ||
        game.max_playtime === game.min_playtime
    ) {
        return `${game.min_playtime} min`;
    }

    return `${game.min_playtime}–${game.max_playtime} min`;
};

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
</script>

<template>
    <Head :title="catalogOwnerName ? `Colección de ${catalogOwnerName}` : 'Catálogo de juegos de mesa'" />

    <div class="min-h-screen bg-[#f7f7f5] text-zinc-950">

        <!-- CABECERA PUBLICA COMPACTA - Clover -->
        <div class="mx-auto max-w-7xl px-4 pt-5 sm:px-8 sm:pt-8">
            <header class="relative overflow-hidden rounded-[28px] bg-[#181424] px-5 py-5 text-white shadow-lg shadow-[#181424]/10 sm:px-8 sm:py-6">
                <Link
                    v-if="catalogBasePath"
                    href="/collections"
                    class="relative mb-5 inline-flex min-h-11 items-center text-sm font-bold text-[#CCFF00] underline-offset-4 hover:underline focus-visible:underline"
                >
                    ← Volver a mis colecciones
                </Link>
                <!-- Iluminación discreta, sin alterar el contraste ni añadir imágenes. -->
                <div aria-hidden="true" class="pointer-events-none absolute -right-20 -top-24 h-64 w-64 rounded-full bg-[#5200FF]/20 blur-3xl"></div>

                <div class="relative flex items-center gap-3">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-[#CCFF00] text-[#5200FF] shadow-sm" aria-hidden="true">
                        <PlayingCardsFanIcon class="!m-0 !size-6 shrink-0" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-base font-black leading-tight tracking-tight sm:text-lg">Juegos de mesa</p>
                        <p class="mt-1 text-xs font-medium text-zinc-300">Clover</p>
                    </div>
                </div>

                <div class="relative mt-5 grid gap-3 sm:mt-6 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end lg:gap-6">
                    <div class="min-w-0">
                        <h1 class="break-words text-2xl font-bold leading-tight sm:text-3xl">
                            {{ catalogOwnerName ? `Colección de ${catalogOwnerName}` : 'Catálogo de juegos' }}
                        </h1>
                        <p v-if="catalogBasePath" class="mt-2 text-sm font-semibold text-[#CCFF00]">
                            Solo consulta
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2 text-xs font-semibold sm:text-sm lg:justify-end">
                        <span class="rounded-full border border-white/10 bg-white/10 px-3 py-1.5">
                            {{ games.length }} {{ games.length === 1 ? 'juego' : 'juegos' }}
                        </span>
                        <span class="rounded-full border border-white/10 bg-white/10 px-3 py-1.5">
                            {{ collections.length }} {{ collections.length === 1 ? 'colección' : 'colecciones' }}
                        </span>
                    </div>
                </div>
            </header>
        </div>

        <main class="mx-auto max-w-7xl px-4 py-5 sm:px-8 sm:py-7">
            <!-- FILTROS -->
            <section class="rounded-3xl border border-zinc-200 bg-white p-4 shadow-sm sm:p-6">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-xl font-black">
                        Encuentra tu juego
                    </h2>

                    <button
                        type="button"
                        class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-[#5200FF]/20 bg-[#f0ebff] px-3 py-2 text-sm font-bold text-[#5200FF] transition hover:bg-[#e5dcff] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF] md:hidden"
                        :aria-expanded="showAdvancedFilters"
                        aria-controls="catalog-filter-fields"
                        @click="showAdvancedFilters = !showAdvancedFilters"
                    >
                        {{ showAdvancedFilters ? 'Ocultar filtros' : 'Más filtros' }}
                        <span v-if="!showAdvancedFilters && advancedFilterCount" class="rounded-full bg-white px-2 py-0.5 text-xs">
                            {{ advancedFilterCount }} <span class="sr-only">{{ advancedFilterCount === 1 ? 'activo' : 'activos' }}</span>
                        </span>
                        <ChevronDown class="size-4 shrink-0 transition-transform motion-reduce:transition-none" :class="{ 'rotate-180': showAdvancedFilters }" aria-hidden="true" />
                    </button>

                    <button
                        v-if="hasFilters"
                        type="button"
                        class="text-sm font-bold text-[#5200FF] hover:underline"
                        @click="clearFilters"
                    >
                        Limpiar filtros
                    </button>
                </div>

                <div
                    id="catalog-filter-fields"
                    class="catalog-filters-grid grid gap-3 md:grid-cols-2 xl:grid-cols-3"
                    :class="{ 'catalog-filters-collapsed': !showAdvancedFilters }"
                >
                    <div>
                        <label for="catalog-search" class="text-sm font-bold">
                            Buscar juego
                        </label>

                        <input
                            id="catalog-search"
                            v-model="search"
                            type="search"
                            placeholder="Busca por nombre o temática..."
                            class="mt-2 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 outline-none focus:border-[#5200FF]"
                        />
                    </div>

                    <div>
                        <label for="catalog-players" class="text-sm font-bold">
                            Jugadores ideales
                        </label>

                        <select
                            id="catalog-players"
                            v-model.number="playerCount"
                            class="mt-2 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 outline-none focus:border-[#5200FF]"
                        >
                            <option value="">Cualquier número</option>
                            <option
                                v-for="number in playerOptions"
                                :key="number"
                                :value="number"
                            >
                                {{ number }} {{ number === 1 ? 'jugador' : 'jugadores' }}
                            </option>
                        </select>
                        <p class="mt-2 text-xs text-zinc-500">Solo juegos recomendados para ese número de jugadores.</p>
                    </div>

                    <div>
                        <label for="catalog-collection" class="text-sm font-bold">
                            Colección
                        </label>

                        <select
                            id="catalog-collection"
                            v-model.number="collectionId"
                            class="mt-2 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 outline-none focus:border-[#5200FF]"
                        >
                            <option value="">Todas las colecciones</option>
                            <option
                                v-for="collection in collections"
                                :key="collection.id"
                                :value="collection.id"
                            >
                                {{ collection.name }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label
                            for="catalog-duration"
                            class="text-sm font-bold"
                        >
                            Tiempo disponible
                        </label>

                        <select
                            id="catalog-duration"
                            v-model.number="durationLimit"
                            class="mt-2 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 outline-none focus:border-[#5200FF]"
                        >
                            <option value="">Cualquier duración</option>
                            <option :value="30">Hasta 30 minutos</option>
                            <option :value="60">Hasta 1 hora</option>
                            <option :value="90">Hasta 1 h 30 min</option>
                            <option :value="120">Hasta 2 horas</option>
                            <option :value="180">Hasta 3 horas</option>
                        </select>
                    </div>

                    <div>
                        <label
                            for="catalog-difficulty"
                            class="text-sm font-bold"
                        >
                            Dificultad
                        </label>

                        <select
                            id="catalog-difficulty"
                            v-model="difficulty"
                            class="mt-2 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 outline-none focus:border-[#5200FF]"
                        >
                            <option value="">Cualquier dificultad</option>
                            <option value="light">🟢 Ligero (hasta 2)</option>
                            <option value="medium">🟡 Medio (2–3)</option>
                            <option value="heavy">🔴 Exigente (más de 3)</option>
                        </select>
                    </div>

                    <div>
                        <label
                            for="catalog-game-mode"
                            class="text-sm font-bold"
                        >
                            Tipo de juego
                        </label>

                        <select
                            id="catalog-game-mode"
                            v-model="selectedGameMode"
                            class="mt-2 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 outline-none focus:border-[#5200FF]"
                        >
                            <option value="">Todos los tipos</option>
                            <option v-for="[mode, label] in gameModeOptions" :key="mode" :value="mode">
                                {{ label }}
                            </option>
                        </select>
                    </div>
                </div>
            </section>


            <!-- FILTROS ACTIVOS -->
            <div
                v-if="activeFilterChips.length"
                class="mt-6 flex flex-wrap items-center gap-2"
                aria-label="Filtros aplicados"
            >
                <span class="mr-1 text-sm font-semibold text-zinc-600">
                    Filtros aplicados:
                </span>

                <button
                    v-for="chip in activeFilterChips"
                    :key="chip.key"
                    type="button"
                    :aria-label="`Quitar filtro ${chip.label}`"
                    class="inline-flex max-w-full items-center gap-2 rounded-full bg-violet-100 px-3 py-2 text-xs font-bold text-violet-800 transition hover:bg-violet-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600"
                    @click="removeFilter(chip.key)"
                >
                    <span class="max-w-[180px] truncate sm:max-w-xs">
                        {{ chip.label }}
                    </span>
                    <span aria-hidden="true">×</span>
                </button>
            </div>

            <!-- RESULTADOS -->
            <div class="mb-6 flex flex-wrap items-end justify-between gap-3" :class="activeFilterChips.length ? 'mt-5' : 'mt-7'">
                <div>
                    <p class="text-sm font-bold uppercase tracking-wider text-[#5200FF]">
                        Explorar biblioteca
                    </p>
                    <h2 class="mt-1 text-2xl font-black">
                        {{ hasFilters ? 'Resultados filtrados' : 'Todos los juegos' }}
                    </h2>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <p class="text-sm text-zinc-500" aria-live="polite" aria-atomic="true">
                        {{ filteredGames.length }} de {{ games.length }} {{ games.length === 1 ? 'juego' : 'juegos' }}
                    </p>

                    <label for="catalog-sort" class="sr-only">
                        Ordenar juegos
                    </label>

                    <select
                        id="catalog-sort"
                        v-model="sortBy"
                        class="rounded-xl border border-zinc-200 bg-white px-4 py-2.5 text-sm font-semibold outline-none focus:border-[#5200FF]"
                    >
                        <option value="name">Nombre (A–Z)</option>
                        <option value="name_desc">Nombre (Z–A)</option>
                        <option value="duration_asc">Duración: más cortos</option>
                        <option value="duration_desc">Duración: más largos</option>
                        <option value="difficulty_asc">Dificultad: más fáciles</option>
                        <option value="difficulty_desc">Dificultad: más difíciles</option>
                    </select>
                </div>
            </div>

            <div
                v-if="filteredGames.length"
                class="grid gap-4 sm:grid-cols-2 sm:gap-5 lg:grid-cols-3 xl:grid-cols-4"
            >
                <article
                    v-for="game in sortedGames"
                    :key="game.id"
                    class="group relative flex h-full flex-col overflow-hidden rounded-3xl border border-zinc-200 bg-white shadow-sm transition duration-300 hover:-translate-y-1 hover:border-[#5200FF] hover:shadow-xl"
                >
                    <!-- Enlace que permite abrir la ficha pulsando
                         sobre cualquier zona libre de la tarjeta -->
                    <Link
                        :href="`${catalogBasePath ?? `/catalog/${catalogToken}`}/games/${game.id}`"
                        :aria-label="`Ver ficha de ${game.name}`"
                        class="absolute inset-0 z-10 rounded-3xl focus-visible:outline-2 focus-visible:outline-offset-[-3px] focus-visible:outline-[#5200FF]"
                    />

                    <!-- PORTADA DEL JUEGO -->
                    <div
                        class="relative flex h-60 items-center justify-center overflow-hidden bg-gradient-to-br from-[#f0ebff] via-white to-[#eef2fa] p-4 sm:aspect-square sm:h-auto sm:p-6"
                    >
                        <img
                            v-if="game.image_url && !brokenCoverIds.includes(game.id)"
                            :src="game.image_url"
                            @error="markCoverAsBroken(game.id)"
                            :alt="`Portada de ${game.name}`"
                            loading="lazy"
                            class="h-full w-full object-contain drop-shadow-xl transition-transform duration-500 group-hover:scale-105"
                        />

                        <div
                            v-else
                            class="flex flex-col items-center gap-2 text-zinc-400"
                        >
                            <span class="text-5xl"><PlayingCardsFanIcon class="mr-1 inline-block size-12 shrink-0 align-[-0.14em] text-violet-700" aria-hidden="true" /></span>
                            <span class="text-sm font-medium">
                                Sin portada disponible
                            </span>
                        </div>


                    </div>

                    <div class="flex flex-1 flex-col p-4 sm:p-5">
                        <div class="sm:min-h-14">
                            <h3 class="line-clamp-2 text-lg font-black leading-snug tracking-tight sm:text-xl" :title="game.name">
                                {{ game.name }}
                            </h3>
                        </div>

                        <!-- Los jugadores ideales no ocultan la portada. -->
                        <div v-if="game.ideal_player_counts.length" class="mt-2">
                            <span class="inline-flex max-w-full items-center gap-1.5 rounded-full bg-[#f0ebff] px-3 py-1.5 text-xs font-bold text-[#5200FF]">
                                <UsersRound class="size-3.5 shrink-0" aria-hidden="true" />
                                Ideal: {{ game.ideal_player_counts.join(', ') }}
                                {{ game.ideal_player_counts.length === 1 && game.ideal_player_counts[0] === 1 ? 'jugador' : 'jugadores' }}
                            </span>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <span
                                v-for="mode in catalogGameModes(game)"
                                :key="mode"
                                class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-bold sm:px-3 sm:py-1.5"
                                :class="{
                                    'bg-blue-100 text-blue-800':
                                        mode === 'cooperative',
                                    'bg-orange-100 text-orange-800':
                                        mode === 'competitive',
                                    'bg-purple-100 text-purple-800':
                                        mode === 'semi_cooperative',
                                    'bg-zinc-100 text-zinc-600':
                                        mode === 'solo' || mode === 'unclassified',
                                }"
                            >
                                <Swords v-if="mode === 'competitive'" class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-orange-700" :stroke-width="2.2" aria-hidden="true" />
                                {{ gameModeLabels[mode] ?? mode }}
                            </span>

                            <template v-if="game.collections.length">
                                <span
                                    v-for="(collection, index) in game.collections"
                                    :key="collection.id"
                                    class="inline-block max-w-full truncate rounded-full bg-violet-50 px-2.5 py-1 text-xs font-bold text-violet-700"
                                    :class="index >= 2 ? 'hidden sm:inline-flex' : ''"
                                >
                                    {{ collection.name }}
                                </span>

                                <!-- Resumen de colecciones adicionales en móvil -->
                                <span
                                    v-if="game.collections.length > 2"
                                    class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-bold text-zinc-600 sm:hidden"
                                >
                                    +{{ game.collections.length - 2 }}
                                    más
                                </span>
                            </template>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-zinc-100 pt-3 text-sm font-semibold text-zinc-800 sm:pt-4">
                            <span class="inline-flex items-center gap-1.5 whitespace-nowrap">
                                <UsersRound class="size-4 shrink-0 text-[#6D28D9]" :stroke-width="2.2" aria-hidden="true" />
                                <span class="sr-only">Jugadores:</span>
                                {{ game.min_players }}–{{ game.max_players }}
                            </span>
                            <span class="inline-flex items-center gap-1.5 whitespace-nowrap">
                                <HourglassCog class="size-4 shrink-0 text-[#1D4ED8]" :stroke-width="2.2" aria-hidden="true" />
                                <span class="sr-only">Duración:</span>
                                {{ formatDuration(game) }}
                            </span>
                            <span v-if="game.complexity !== null" class="inline-flex items-center gap-1.5 whitespace-nowrap" title="Dificultad sobre 5">
                                <BrainCog class="size-4 shrink-0 text-pink-600" :stroke-width="2" aria-hidden="true" />
                                <span class="sr-only">Dificultad:</span>
                                {{ Number(game.complexity).toFixed(2) }}/5
                            </span>
                        </div>

                        <p
                            v-if="game.short_description"
                            class="mt-3 line-clamp-2 text-sm leading-relaxed text-zinc-600 sm:mt-4 sm:line-clamp-3"
                        >
                            {{ game.short_description }}
                        </p>

                        <!-- El Link superpuesto ya abre la ficha al pulsar cualquier zona libre. -->
                        <div class="mt-auto pt-4 sm:pt-5">
                            <!-- Enlaces externos independientes de la ficha de nuestra aplicación. -->
                            <div
                                v-if="externalUrl(game.bgg_url) || externalUrl(game.youtube_url)"
                                class="relative z-20 mb-2 flex flex-wrap items-center justify-center gap-x-5 gap-y-1"
                            >
                                <a
                                    v-if="externalUrl(game.bgg_url)"
                                    :href="externalUrl(game.bgg_url)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    :aria-label="`Consultar ${game.name} en BoardGameGeek (nueva pestaña)`"
                                    class="inline-flex min-h-11 items-center gap-1.5 text-sm font-semibold text-[#5200FF] underline-offset-4 transition-colors hover:text-[#4000cc] hover:underline focus-visible:rounded focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF]"
                                >
                                    BGG <ChainIcon class="size-4 shrink-0" aria-hidden="true" />
                                </a>

                                <a
                                    v-if="externalUrl(game.youtube_url)"
                                    :href="externalUrl(game.youtube_url)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    :aria-label="`Ver tutorial de ${game.name} (nueva pestaña)`"
                                    class="inline-flex min-h-11 items-center gap-1.5 text-sm font-semibold text-[#5200FF] underline-offset-4 transition-colors hover:text-[#4000cc] hover:underline focus-visible:rounded focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF]"
                                >
                                    <YoutubeLogoIcon class="size-4 shrink-0" aria-hidden="true" /> Tutorial <ChainIcon class="size-3.5 shrink-0" aria-hidden="true" />
                                </a>
                            </div>

                            <div
                                aria-hidden="true"
                                class="pointer-events-none flex min-h-11 items-center justify-center gap-2 rounded-xl bg-[#5200FF] px-3 py-2.5 text-center text-sm font-extrabold text-white shadow-sm transition-all duration-200 group-hover:bg-[#4000cc] group-hover:shadow-lg group-focus-within:ring-2 group-focus-within:ring-[#5200FF] group-focus-within:ring-offset-2 motion-reduce:transition-none"
                            >
                                Ver ficha del juego <span aria-hidden="true">→</span>
                            </div>
                        </div>
                    </div>
                </article>
            </div>

            <div
                v-else
                class="rounded-3xl border border-dashed border-zinc-300 bg-white px-6 py-16 text-center"
            >
                <p class="text-4xl"><PlayingCardsFanIcon class="mr-1 inline-block size-10 shrink-0 align-[-0.14em] text-violet-700" aria-hidden="true" /></p>
                <h3 class="mt-4 text-xl font-black">
                    No hay juegos que coincidan
                </h3>
                <p class="mt-2 text-sm text-zinc-500">
                    Prueba con otras palabras o elimina alguno de los filtros aplicados.
                </p>
                <button
                    v-if="hasFilters"
                    class="mt-5 font-bold text-[#5200FF] hover:underline"
                    @click="clearFilters"
                >
                    Mostrar todos
                </button>
            </div>
        </main>

        <footer class="mt-12 border-t border-zinc-200 py-8 text-center text-sm text-zinc-500">
            Juegos de mesa · Catálogo de juegos compartidos

            <!-- CRÉDITOS DE ICONOS - CLOVER -->
            <a href="/creditos-iconos.html" class="ml-2 inline-block font-semibold text-[#5200FF] underline-offset-4 hover:underline focus-visible:underline">Créditos de iconos</a>
        </footer>

        <ScrollToTopProgress compact-on-mobile />
    </div>
</template>

<style scoped>
@media (max-width: 767px) {
    .catalog-filters-collapsed > div:not(:first-child) {
        display: none;
    }
}
</style>
