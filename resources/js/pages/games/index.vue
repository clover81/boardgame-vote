<script setup lang="ts">
import PencilSparklesIcon from '@/components/icons/PencilSparklesIcon.vue';
import PlayingCardsFanIcon from '@/components/icons/PlayingCardsFanIcon.vue';
import { HourglassCog, Swords, UsersRound, BrainCog } from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    Head,
    Link,
    router,
} from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import ScrollToTopProgress from '@/components/ScrollToTopProgress.vue';
import RollingDicesIcon from '@/components/icons/RollingDicesIcon.vue';

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

    bgg_id: number | null;

    ideal_player_counts: number[];

    collections: Collection[];

    total_plays: number;
    last_played_at: string | null;
}

interface CollectionOption extends Collection {
    is_default: boolean;

    games_count: number;
}

const props = defineProps<{
    games: Game[];

    collections: CollectionOption[];

    selected_collection_id:
        number | null;

    filters: {
        q: string;
        players: number | null;
        mode: string;
        duration: number | null;
        difficulty: '' | 'light' | 'medium' | 'heavy';
        sort: string;
    };
}>();

/*
|--------------------------------------------------------------------------
| Filtros
|--------------------------------------------------------------------------
*/

const search = ref(props.filters.q);
// En móvil, los filtros secundarios se pueden plegar para ver los juegos antes.
const showMobileFilters = ref(Boolean(
    props.filters.players || props.filters.mode || props.filters.duration ||
    props.filters.difficulty || props.selected_collection_id
));
const gameMode = ref(props.filters.mode ?? '');
const sortOrder = ref(props.filters.sort ?? 'name');
const playerCount = ref<number | ''>(props.filters.players ?? '');
const selectedCollectionId = ref(props.selected_collection_id);
const durationLimit = ref<number | ''>(props.filters.duration ?? '');
const difficulty = ref<'' | 'light' | 'medium' | 'heavy'>(props.filters.difficulty ?? '');
const playerOptions = Array.from({ length: 12 }, (_, index) => index + 1);

const brokenCoverIds = ref<number[]>([]);

const markCoverAsBroken = (gameId: number) => {
    if (!brokenCoverIds.value.includes(gameId)) {
        brokenCoverIds.value.push(gameId);
    }
};

const applyFilters = () => {
    router.get('/games', {
        collection_id: selectedCollectionId.value ?? undefined,
        q: search.value.trim() || undefined,
        players: Number(playerCount.value) || undefined,
        mode: gameMode.value || undefined,
        duration: durationLimit.value === '' ? undefined : Number(durationLimit.value),
        difficulty: difficulty.value || undefined,
        sort: sortOrder.value === 'name' ? undefined : sortOrder.value,
    }, {
        preserveScroll: true,
    });
};

const clearFilters = () => {
    search.value = '';
    gameMode.value = '';
    durationLimit.value = '';
    difficulty.value = '';
    playerCount.value = '';
    selectedCollectionId.value = null;
    showMobileFilters.value = false;
    applyFilters();
};

const advancedFilterCount = computed(() =>
    Number(playerCount.value !== '') +
    Number(gameMode.value !== '') +
    Number(durationLimit.value !== '') +
    Number(difficulty.value !== '') +
    Number(selectedCollectionId.value !== null)
);

const hasActiveFilters = computed(() =>
    search.value.trim() !== '' ||
    advancedFilterCount.value > 0
);

const gameModeLabels: Record<string, string> = {
    competitive: 'Competitivo',
    cooperative: 'Cooperativo',
    semi_cooperative: 'Semicooperativo',
};

const formatDate = (date: string | null): string => {
    if (!date) {
        return 'Nunca';
    }

    return new Date(`${date}T12:00:00`).toLocaleDateString('es-ES', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
};
</script>

<template>
    <Head title="Mi colección" />

    <AppLayout>
        <div
            class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6"
        >
            <!-- ================================================== -->
            <!-- CABECERA                                           -->
            <!-- ================================================== -->

            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between sm:gap-5"
            >
                <div>
                    <p
                        class="font-bold uppercase tracking-wide text-[#5200FF]"
                    >
                        MI COLECCIÓN
                    </p>

                    <h1
                        class="mt-1 text-3xl font-black text-zinc-900 dark:text-zinc-100"
                    >
                        Mis juegos
                    </h1>

                    <p
                        class="mt-2 text-zinc-500 dark:text-zinc-400"
                    >
                        Consulta juegos, expansiones
                        e historial de partidas.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <Link
                        href="/polls/create"
                        class="inline-flex min-h-12 items-center justify-center gap-2.5 rounded-xl border border-[#5200FF]/35 bg-[#5200FF]/5 px-4 py-2 font-bold text-[#5200FF] shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-[#5200FF] hover:bg-[#5200FF]/10 hover:shadow-[0_8px_22px_rgba(82,0,255,0.16)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF] motion-reduce:transform-none dark:border-[#CCFF00]/45 dark:bg-[#5200FF]/10 dark:text-[#CCFF00] dark:hover:bg-[#5200FF]/20"
                    >
                        <RollingDicesIcon class="size-7 shrink-0 text-[#5200FF] dark:text-[#CCFF00]" aria-hidden="true" />
                        <span>Nueva votación</span>
                    </Link>

                    <Link
                        href="/games/create"
                        class="inline-flex min-h-12 items-center justify-center rounded-xl bg-[#5200FF] px-5 py-2.5 font-bold text-white transition hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF]"
                    >
                        + Añadir juego
                    </Link>
                </div>
            </div>

            <!-- ================================================== -->
            <!-- BUSCADOR Y FILTROS                                 -->
            <!-- ================================================== -->

            <form
                class="mt-5 mb-6 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-950 sm:mt-5 sm:mb-6 sm:p-5"
                @submit.prevent="applyFilters"
            >
                <div class="grid gap-x-4 gap-y-3 md:grid-cols-2 xl:grid-cols-3">
                    <div class="min-w-0">
                        <label for="game-search" class="text-sm font-bold">Buscar juego</label>
                        <input id="game-search" v-model="search" type="search"
                            placeholder="Busca por nombre o temática..."
                            class="mt-1.5 w-full rounded-xl border border-zinc-300 bg-zinc-50 px-4 py-2.5 text-zinc-900 outline-none focus:border-[#5200FF] dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100" />
                    </div>
                    <button type="button" class="rounded-xl border border-zinc-300 px-4 py-2.5 text-sm font-bold md:hidden dark:border-zinc-700"
                        :aria-expanded="showMobileFilters" aria-controls="game-advanced-filters"
                        @click="showMobileFilters = !showMobileFilters">
                        Filtros <span v-if="advancedFilterCount">({{ advancedFilterCount }})</span>
                        <span aria-hidden="true">{{ showMobileFilters ? ' −' : ' +' }}</span>
                    </button>
                    <div id="game-advanced-filters" :class="showMobileFilters ? 'contents' : 'hidden md:contents'">
                        <div class="min-w-0">
                            <label for="game-player-count" class="text-sm font-bold">Jugadores ideales</label>
                            <select id="game-player-count" v-model.number="playerCount" class="mt-1.5 w-full rounded-xl border border-zinc-300 bg-zinc-50 px-4 py-2.5 text-zinc-900 outline-none focus:border-[#5200FF] dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100">
                                <option value="">Cualquier número</option>
                                <option v-for="number in playerOptions" :key="number" :value="number">
                                    {{ number }} {{ number === 1 ? 'jugador' : 'jugadores' }}
                                </option>
                            </select>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Solo juegos recomendados para ese número de jugadores.</p>
                        </div>
                        <div class="min-w-0">
                            <label for="games-collection" class="text-sm font-bold">Colección</label>
                            <select id="games-collection" v-model="selectedCollectionId" class="mt-1.5 w-full rounded-xl border border-zinc-300 bg-zinc-50 px-4 py-2.5 text-zinc-900 outline-none focus:border-[#5200FF] dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100">
                                <option :value="null">Todas las colecciones</option>
                                <option v-for="collection in collections" :key="collection.id" :value="collection.id">{{ collection.name }}</option>
                            </select>
                        </div>
                        <div class="min-w-0">
                            <label for="games-duration" class="text-sm font-bold">Tiempo disponible</label>
                            <select id="games-duration" v-model.number="durationLimit" class="mt-1.5 w-full rounded-xl border border-zinc-300 bg-zinc-50 px-4 py-2.5 text-zinc-900 outline-none focus:border-[#5200FF] dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100">
                                <option value="">Cualquier duración</option>
                                <option :value="30">Hasta 30 minutos</option>
                                <option :value="60">Hasta 1 hora</option>
                                <option :value="90">Hasta 1 h 30 min</option>
                                <option :value="120">Hasta 2 horas</option>
                                <option :value="180">Hasta 3 horas</option>
                            </select>
                        </div>
                        <div class="min-w-0">
                            <label for="games-difficulty" class="text-sm font-bold">Dificultad</label>
                            <select id="games-difficulty" v-model="difficulty" class="mt-1.5 w-full rounded-xl border border-zinc-300 bg-zinc-50 px-4 py-2.5 text-zinc-900 outline-none focus:border-[#5200FF] dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100">
                                <option value="">Cualquier dificultad</option>
                                <option value="light">Ligero (hasta 2)</option>
                                <option value="medium">Medio (más de 2 a 3)</option>
                                <option value="heavy">Exigente (más de 3)</option>
                            </select>
                        </div>
                        <div class="min-w-0">
                            <label for="game-mode-filter" class="text-sm font-bold">Tipo de juego</label>
                            <select id="game-mode-filter" v-model="gameMode" class="mt-1.5 w-full rounded-xl border border-zinc-300 bg-zinc-50 px-4 py-2.5 text-zinc-900 outline-none focus:border-[#5200FF] dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100">
                                <option value="">Todos los tipos</option>
                                <option value="competitive">Competitivo</option>
                                <option value="cooperative">Cooperativo</option>
                                <option value="semi_cooperative">Semicooperativo</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 pt-3 dark:border-zinc-800">
                    <div class="flex min-w-0 flex-wrap items-center gap-x-4 gap-y-2">
                        <p class="text-sm text-zinc-500 dark:text-zinc-400" aria-live="polite">
                            Mostrando <strong class="text-zinc-900 dark:text-zinc-100">{{ games.length }}</strong>
                            {{ games.length === 1 ? 'juego' : 'juegos' }}
                        </p>
                        <div class="flex min-w-0 items-center gap-2">
                            <label for="game-sort" class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Ordenar</label>
                            <select
                                id="game-sort"
                                v-model="sortOrder"
                                class="min-w-0 max-w-[12rem] rounded-xl border border-zinc-300 bg-zinc-50 px-3 py-2 text-sm font-semibold text-zinc-800 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                                @change="applyFilters"
                            >
                                <option value="name">Nombre (A–Z)</option>
                                <option value="name_desc">Nombre (Z–A)</option>
                                <option value="recent">Últimos jugados</option>
                                <option value="most_played">Más jugados</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <button v-if="hasActiveFilters" type="button"
                            class="text-sm font-bold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
                            @click="clearFilters">Limpiar filtros</button>
                        <button type="submit"
                            class="rounded-xl bg-[#5200FF] px-5 py-3 text-sm font-black text-white transition hover:bg-[#3F00C7]">
                            Aplicar filtros
                        </button>
                    </div>
                </div>
            </form>

            <!-- ================================================== -->
            <!-- JUEGOS                                             -->
            <!-- ================================================== -->

            <div
                v-if="games.length"
                class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3"
            >
                <article
                    v-for="game in games"
                    :key="game.id"
                    class="group flex h-full flex-col overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm transition duration-200 motion-safe:hover:-translate-y-0.5 hover:border-violet-300 hover:shadow-md focus-within:ring-2 focus-within:ring-[#5200FF]/20 dark:border-zinc-800 dark:bg-zinc-950 dark:hover:border-violet-700"
                >
                    <!-- PORTADA -->

                    <Link
                        :href="`/games/${game.id}?back=${encodeURIComponent($page.url)}`"
                        class="block"
                    >
                        <div
                            class="aspect-[5/4] overflow-hidden bg-zinc-100 dark:bg-zinc-900"
                        >
                            <img
                                v-if="game.image_url && !brokenCoverIds.includes(game.id)"
                                :src="game.image_url"
                                @error="markCoverAsBroken(game.id)"
                                :alt="game.name"
                                class="h-full w-full object-contain p-2"
                                loading="lazy"
                            />

                            <div
                                v-else
                                class="flex h-full items-center justify-center text-sm text-zinc-400"
                            >
                                Sin portada
                            </div>
                        </div>
                    </Link>

                    <div class="flex flex-1 flex-col p-5">

                        <!-- NOMBRE -->

                        <div
                            class="flex min-h-[3.5rem] items-start justify-between gap-3"
                        >
                            <Link
                                :href="`/games/${game.id}?back=${encodeURIComponent($page.url)}`"
                                class="min-w-0"
                            >
                                <h2
                                    class="line-clamp-2 text-lg font-black leading-snug hover:text-[#5200FF]"
                                    :title="game.name"
                                >
                                    {{ game.name }}
                                </h2>
                            </Link>

                            <span
                                v-if="game.bgg_id"
                                class="shrink-0 rounded-full bg-[#CCFF00] px-2 py-1 text-xs font-black text-black"
                            >
                                BGG
                            </span>
                        </div>

                        <!-- MODALIDADES -->

                        <div class="mt-3 flex flex-wrap gap-2">
                            <span
                                v-for="mode in game.game_modes"
                                :key="mode"
                                class="rounded-full px-3 py-1 text-xs font-bold"
                                :class="{
                                    'bg-orange-100 text-orange-800':
                                        mode === 'competitive',
                                    'bg-blue-100 text-blue-800':
                                        mode === 'cooperative',
                                    'bg-purple-100 text-purple-800':
                                        mode === 'semi_cooperative',
                                }"
                            >
                                <Swords v-if="mode === 'competitive'" class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-orange-500 dark:text-orange-300" :stroke-width="2.2" aria-hidden="true" />
                                {{ gameModeLabels[mode] ?? mode }}
                            </span>

                            <span
                                v-if="game.game_modes.length === 0"
                                class="rounded-full bg-zinc-100 px-3 py-1 text-xs font-bold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300"
                            >
                                {{
                                    game.max_players === 1
                                        ? 'Solitario'
                                        : 'Sin clasificar'
                                }}
                            </span>
                        </div>

                        <!-- COLECCIONES -->

                        <div
                            v-if="game.collections.length"
                            class="mt-3 flex flex-wrap gap-1.5"
                        >
                            <span
                                v-for="
                                    collection in
                                    game.collections
                                "
                                :key="collection.id"
                                class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-semibold text-zinc-600 dark:bg-zinc-900 dark:text-zinc-300"
                            >
                                {{ collection.name }}
                            </span>
                        </div>

                        <!-- DATOS -->

                        <div
                            class="mt-4 flex flex-wrap gap-x-4 gap-y-2 text-sm text-zinc-600 dark:text-zinc-300"
                        >
                            <span>
                                <UsersRound class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-[#6D28D9] dark:text-[#C4B5FD]" :stroke-width="2.2" role="img" aria-label="Jugadores" />
                                {{ game.min_players }}–{{
                                    game.max_players
                                }}
                            </span>

                            <span
                                v-if="
                                    game.min_playtime !== null
                                "
                            >
                                <HourglassCog class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-[#1D4ED8] dark:text-[#CCFF00]" :stroke-width="2.2" role="img" aria-label="Duración" />
                                {{ game.min_playtime }}

                                <template
                                    v-if="
                                        game.max_playtime !== null &&
                                        game.max_playtime !==
                                            game.min_playtime
                                    "
                                >
                                    –{{ game.max_playtime }}
                                </template>

                                min
                            </span>

                            <span
                                v-if="
                                    game.complexity !== null
                                "
                             class="inline-flex items-center gap-1.5">
                                <BrainCog class="size-[18px] shrink-0 text-pink-400 dark:text-pink-300" :stroke-width="2.2" aria-hidden="true" /> {{ game.complexity }}/5
                            </span>
                        </div>

                        <!-- IDEALES -->

                        <div
                            v-if="
                                game.ideal_player_counts.length
                            "
                            class="mt-4 flex flex-wrap gap-2"
                        >
                            <span
                                v-for="
                                    number in
                                    game.ideal_player_counts
                                "
                                :key="number"
                                class="rounded-full bg-[#CCFF00] px-2.5 py-1 text-xs font-black text-black"
                            >
                                Ideal {{ number }}
                            </span>
                        </div>

                        <!-- HISTORIAL -->

                        <div
                            class="mt-5 rounded-xl bg-zinc-50 p-3 dark:bg-zinc-900"
                        >
                            <p
                                v-if="
                                    game.total_plays === 0
                                "
                                class="text-sm font-semibold text-zinc-500"
                            >
                                <PlayingCardsFanIcon class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-violet-600 dark:text-violet-300" aria-hidden="true" /> Sin partidas registradas
                            </p>

                            <div
                                v-else
                                class="flex flex-wrap items-center justify-between gap-2 text-sm"
                            >
                                <p
                                    class="font-bold text-[#5200FF] dark:text-[#CCFF00]"
                                >
                                    <PlayingCardsFanIcon class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-violet-600 dark:text-violet-300" aria-hidden="true" />
                                    {{ game.total_plays }}
                                    {{
                                        game.total_plays === 1
                                            ? 'partida'
                                            : 'partidas'
                                    }}
                                </p>

                                <p
                                    class="text-zinc-500"
                                >
                                    Última:
                                    {{
                                        formatDate(
                                            game.last_played_at
                                        )
                                    }}
                                </p>
                            </div>
                        </div>

                        <!-- ACCIONES -->

                        <div
                            class="mt-auto grid grid-cols-2 gap-2 pt-5"
                        >
                            <Link
                                :href="`/games/${game.id}?back=${encodeURIComponent($page.url)}`"
                                class="flex min-h-11 items-center justify-center rounded-xl bg-[#5200FF] px-3 py-2.5 text-center text-sm font-bold text-white transition hover:bg-[#3F00C7] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF]"
                            >
                                Ver ficha
                            </Link>

                            <Link
                                :href="
                                    `/games/${game.id}/edit`
                                "
                                class="flex min-h-11 items-center justify-center rounded-xl border border-zinc-300 px-3 py-2.5 text-center text-sm font-bold transition hover:border-[#5200FF] hover:text-[#5200FF] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF] dark:border-zinc-700 dark:text-zinc-200 dark:hover:border-[#CCFF00] dark:hover:text-[#CCFF00]"
                            >
                                <span class="inline-flex items-center justify-center gap-1.5"><PencilSparklesIcon class="h-4 w-4 shrink-0 text-violet-500 dark:text-[#CCFF00]" />Editar</span>
                            </Link>
                        </div>
                    </div>
                </article>
            </div>

            <!-- ================================================== -->
            <!-- SIN RESULTADOS                                     -->
            <!-- ================================================== -->

            <div
                v-else-if="hasActiveFilters"
                class="rounded-2xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700"
            >
                <p
                    class="text-lg font-black text-zinc-900 dark:text-zinc-100"
                >
                    No hay juegos que coincidan
                </p>

                <p
                    class="mt-2 text-sm text-zinc-500 dark:text-zinc-400"
                >
                    Prueba con otra colección,
                    búsqueda o número de jugadores.
                </p>

                <button
                    type="button"
                    class="mt-4 font-bold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
                    @click="
                        clearFilters
                    "
                >
                    Limpiar filtros
                </button>
            </div>

            <!-- ================================================== -->
            <!-- COLECCIÓN VACÍA                                    -->
            <!-- ================================================== -->

            <div
                v-else
                class="rounded-2xl border border-dashed border-zinc-300 p-10 text-center text-zinc-500 dark:border-zinc-700 dark:text-zinc-400"
            >
                Todavía no hay juegos en tus colecciones.
            </div>

            <ScrollToTopProgress />
        </div>
    </AppLayout>
</template>
