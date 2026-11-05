<script setup lang="ts">
import { computed, ref } from 'vue';
import {
    Head,
    Link,
    router,
} from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
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
    };
}>();

/*
|--------------------------------------------------------------------------
| Filtros
|--------------------------------------------------------------------------
*/

const search = ref(props.filters.q);
const gameMode = ref(props.filters.mode ?? '');
const playerCount = ref<number | ''>(props.filters.players ?? '');
const selectedCollectionId = ref(props.selected_collection_id);

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
    }, {
        preserveScroll: true,
    });
};

const clearFilters = () => {
    search.value = '';
    gameMode.value = '';
    playerCount.value = '';
    selectedCollectionId.value = null;
    applyFilters();
};

const hasActiveFilters = computed(() =>
    search.value.trim() !== '' ||
    gameMode.value !== '' ||
    playerCount.value !== '' ||
    selectedCollectionId.value !== null
);

const changeCollection = (event: Event) => {
    const value = (event.target as HTMLSelectElement).value;
    selectedCollectionId.value = value ? Number(value) : null;
    applyFilters();
};

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
                class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between"
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
                        Consulta tus juegos, expansiones
                        e historial de partidas.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <Link
                        href="/polls/create"
                        class="rounded-xl border border-zinc-300 px-4 py-3 font-bold text-zinc-700 transition hover:border-[#5200FF] dark:border-zinc-700 dark:text-zinc-200"
                    >
                        🎲 Nueva votación
                    </Link>

                    <Link
                        href="/games/create"
                        class="rounded-xl bg-[#5200FF] px-5 py-3 font-bold text-white transition hover:opacity-90"
                    >
                        + Añadir juego
                    </Link>
                </div>
            </div>

            <!-- ================================================== -->
            <!-- COLECCIÓN                                          -->
            <!-- ================================================== -->

            <div
                v-if="collections.length"
                class="mt-7 flex flex-col gap-2 sm:flex-row sm:items-center"
            >
                <label
                    for="collection-selector"
                    class="text-sm font-bold text-zinc-600 dark:text-zinc-300"
                >
                    Colección
                </label>

                <select
                    id="collection-selector"
                    :value="selectedCollectionId ?? ''"
                    class="rounded-xl border border-zinc-300 bg-white px-4 py-3 font-semibold text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                    @change="changeCollection"
                >
                    <option :value="''">Todas mis colecciones</option>
                    <option
                        v-for="collection in collections"
                        :key="collection.id"
                        :value="collection.id"
                    >
                        {{ collection.name }}
                        ({{ collection.games_count }})
                    </option>
                </select>
            </div>

            <!-- ================================================== -->
            <!-- BUSCADOR Y FILTROS                                 -->
            <!-- ================================================== -->

            <form
                class="mt-6 mb-8 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-950"
                @submit.prevent="applyFilters"
            >
                <div class="grid gap-4 sm:grid-cols-2 sm:items-end lg:grid-cols-[1fr_auto_auto_auto]">
                    <div>
                        <label
                            for="game-search"
                            class="text-sm font-bold text-zinc-800 dark:text-zinc-200"
                        >
                            Buscar por nombre
                        </label>
                        <input
                            id="game-search"
                            v-model="search"
                            type="search"
                            placeholder="Ej: White Castle..."
                            class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 outline-none placeholder:text-zinc-400 focus:border-[#5200FF] dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:placeholder:text-zinc-500"
                        />
                    </div>

                    <div>
                        <label
                            for="game-player-count"
                            class="text-sm font-bold text-zinc-800 dark:text-zinc-200"
                        >
                            N.º de jugadores
                        </label>
                        <input
                            id="game-player-count"
                            v-model.number="playerCount"
                            type="number"
                            min="1"
                            step="1"
                            placeholder="Todos"
                            class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                        />
                    </div>

                    <!-- FILTRO POR MODALIDAD -->
                    <div>
                        <label
                            for="game-mode-filter"
                            class="mb-2 block text-sm font-semibold"
                        >
                            Modalidad
                        </label>

                        <select
                            id="game-mode-filter"
                            v-model="gameMode"
                            @change="applyFilters"
                            class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                        >
                            <option value="">
                                Todas las modalidades
                            </option>

                            <option value="competitive">
                                Competitivo
                            </option>

                            <option value="cooperative">
                                Cooperativo
                            </option>

                            <option value="semi_cooperative">
                                Semicooperativo
                            </option>
                        </select>
                    </div>

                    <button
                        type="submit"
                        class="rounded-xl bg-[#5200FF] px-5 py-3 font-bold text-white transition hover:opacity-90"
                    >
                        Aplicar filtros
                    </button>
                </div>

                <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-800">
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        Mostrando
                        <strong class="text-zinc-900 dark:text-zinc-100">{{ games.length }}</strong>
                        {{ games.length === 1 ? 'juego' : 'juegos' }}
                    </p>
                    <button
                        v-if="hasActiveFilters"
                        type="button"
                        class="text-sm font-bold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
                        @click="clearFilters"
                    >
                        Limpiar filtros
                    </button>
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
                    class="overflow-hidden rounded-2xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950"
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

                    <div class="p-5">

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
                                👥
                                {{ game.min_players }}–{{
                                    game.max_players
                                }}
                            </span>

                            <span
                                v-if="
                                    game.min_playtime !== null
                                "
                            >
                                ⏱
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
                            >
                                🧠 {{ game.complexity }}/5
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
                                🎲 Sin partidas registradas
                            </p>

                            <div
                                v-else
                                class="flex flex-wrap items-center justify-between gap-2 text-sm"
                            >
                                <p
                                    class="font-bold text-[#5200FF] dark:text-[#CCFF00]"
                                >
                                    🎲
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
                            class="mt-5 grid grid-cols-2 gap-2"
                        >
                            <Link
                                :href="`/games/${game.id}?back=${encodeURIComponent($page.url)}`"
                                class="rounded-xl bg-[#5200FF] px-4 py-2.5 text-center text-sm font-bold text-white"
                            >
                                Ver ficha
                            </Link>

                            <Link
                                :href="
                                    `/games/${game.id}/edit`
                                "
                                class="rounded-xl border border-zinc-300 px-4 py-2.5 text-center text-sm font-bold dark:border-zinc-700"
                            >
                                Editar
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
