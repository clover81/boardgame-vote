<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import InputError from '@/components/InputError.vue';
import ScrollToTopProgress from '@/components/ScrollToTopProgress.vue';

interface Player {
    id: number;
    name: string;
}

interface Expansion {
    id: number;
    name: string;
}

interface Collection {
    id: number;
    name: string;

    is_default: boolean;

    games_count: number;
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

    collection_ids: number[];

    ideal_player_counts: number[];

    total_plays: number;
    last_played_at: string | null;

    expansions: Expansion[];
}

interface GroupRecommendation {
    game_id: number;
    rank: number;
    group_plays: number;
    familiar_players: number;
    selected_players: number;
    total_plays: number;
    last_group_played_at: string | null;
    last_selected_played_at: string | null;
}

const props = defineProps<{
    players: Player[];

    collections: Collection[];

    games: Game[];
}>();

const defaultCollectionIds =
    props.collections
        .filter(
            (collection) =>
                collection.is_default
        )
        .map(
            (collection) =>
                collection.id
        );

const form = useForm({
    name: 'Partida',

    collection_ids:
        defaultCollectionIds,

    player_ids:
        [] as number[],

    candidate_ids:
        [] as number[],

    candidate_notes:
        {} as Record<string, string>,

    /*
     * Juego -> expansión prevista.
     *
     * Ejemplo:
     *
     * {
     *     4: 7
     * }
     *
     * juego 4 -> expansión 7
     */
    candidate_expansion_ids:
        {} as Record<
            string,
            number | null
        >,
});

const togglePlayer = (playerId: number) => {
    const ids = form.player_ids.map(Number);

    if (ids.includes(playerId)) {
        form.player_ids = ids.filter(
            id => id !== playerId
        );
    } else {
        form.player_ids = [...ids, playerId];
    }
};

// La selección de jugadores se consulta siempre desde el formulario.
const isPlayerSelected = (playerId: number): boolean =>
    form.player_ids.includes(playerId);

const selectedPlayers = computed(() => {
    return props.players.filter((player) =>
        isPlayerSelected(player.id)
    );
});

const selectedCollections = computed(() => {
    return props.collections.filter((collection) =>
        form.collection_ids.includes(collection.id)
    );
});

const selectedCandidateGames = computed(() => {
    return props.games.filter((game) =>
        form.candidate_ids.includes(game.id)
    );
});

const canCreatePoll = computed(() => {
    return (
        form.name.trim().length > 0 &&
        form.collection_ids.length >= 1 &&
        form.player_ids.length >= 2 &&
        form.candidate_ids.length >= 1
    );
});

const selectedExpansionName = (game: Game): string | null => {
    const expansionId = form.candidate_expansion_ids?.[game.id];

    if (!expansionId) {
        return null;
    }

    return game.expansions?.find(
        (expansion) => expansion.id === expansionId
    )?.name ?? null;
};

const showOtherGames =
    ref(false);

const groupRecommendations = ref<GroupRecommendation[]>([]);
const recommendationsLoading = ref(false);
const recommendationsError = ref('');

const recommendationByGame = computed(() => {
    return new Map(
        groupRecommendations.value.map((recommendation) => [
            recommendation.game_id,
            recommendation,
        ])
    );
});

const groupRecommendationText = (gameId: number): string => {
    const recommendation = recommendationByGame.value.get(gameId);

    if (!recommendation) {
        return '';
    }

    if (recommendation.familiar_players === 0) {
        return 'Nadie del grupo lo ha jugado según tu historial.';
    }

    if (recommendation.group_plays === 0) {
        return `${recommendation.familiar_players} de ${recommendation.selected_players} participantes lo han jugado, pero este grupo nunca lo ha jugado junto.`;
    }

    return `Este grupo lo ha jugado ${recommendation.group_plays} ${recommendation.group_plays === 1 ? 'vez' : 'veces'}.`;
};

const gameSort = ref<
    | 'name'
    | 'group_history'
    | 'never_first'
    | 'longest_unplayed'
    | 'most_played'
    | 'least_played'
>('name');

let recommendationRequestId = 0;

const loadGroupRecommendations = async () => {
    // Invalidamos respuestas pendientes incluso si la selección deja de ser válida.
    const requestId = ++recommendationRequestId;

    if (form.player_ids.length < 2 || form.collection_ids.length === 0) {
        groupRecommendations.value = [];
        recommendationsLoading.value = false;
        recommendationsError.value = '';
        return;
    }

    recommendationsLoading.value = true;
    recommendationsError.value = '';

    const params = new URLSearchParams();

    for (const playerId of form.player_ids) {
        params.append('player_ids[]', String(playerId));
    }

    for (const collectionId of form.collection_ids) {
        params.append('collection_ids[]', String(collectionId));
    }

    try {
        const response = await fetch(
            `/polls/recommendations?${params.toString()}`,
            {
                headers: {
                    Accept: 'application/json',
                },
            }
        );

        const data = await response.json();

        // Una petición más reciente puede haber terminado antes.
        if (requestId !== recommendationRequestId) {
            return;
        }

        if (!response.ok) {
            recommendationsError.value =
                data?.message
                ?? 'No se ha podido calcular la recomendación.';
            groupRecommendations.value = [];
            return;
        }

        groupRecommendations.value = data.recommendations ?? [];
    } catch {
        if (requestId !== recommendationRequestId) {
            return;
        }

        recommendationsError.value =
            'No se ha podido calcular la recomendación del grupo.';
        groupRecommendations.value = [];
    } finally {
        if (requestId === recommendationRequestId) {
            recommendationsLoading.value = false;
        }
    }
};

let recommendationTimer: ReturnType<typeof setTimeout> | null = null;

watch(
    [
        () => [...form.player_ids],
        () => [...form.collection_ids],
        gameSort,
    ],
    () => {
        if (recommendationTimer !== null) {
            clearTimeout(recommendationTimer);
            recommendationTimer = null;
        }

        // La selección actual invalida cualquier respuesta anterior.
        recommendationRequestId++;
        recommendationsLoading.value = false;

        if (gameSort.value !== 'group_history') {
            return;
        }

        recommendationTimer = setTimeout(() => {
            recommendationTimer = null;
            void loadGroupRecommendations();
        }, 250);
    },
    { deep: true }
);

onUnmounted(() => {
    if (recommendationTimer !== null) {
        clearTimeout(recommendationTimer);
    }

    recommendationRequestId++;
});

const playerCount =
    computed(() => {
        return form.player_ids.length;
    });

/*
|--------------------------------------------------------------------------
| Juegos disponibles según las colecciones
|--------------------------------------------------------------------------
|
| Si un juego aparece en dos colecciones,
| props.games solo contiene una instancia,
| por lo que no se duplica.
|
*/

const availableGames =
    computed(() => {
        if (
            form.collection_ids
                .length === 0
        ) {
            return [];
        }

        return props.games.filter(
            (game) =>
                game.collection_ids.some(
                    (collectionId) =>
                        form.collection_ids.includes(
                            collectionId
                        )
                )
        );
    });

/*
|--------------------------------------------------------------------------
| Juegos ideales
|--------------------------------------------------------------------------
*/

const idealGames = computed(() => {
    if (playerCount.value === 0) {
        return [];
    }

    return availableGames.value.filter(
        (game) =>
            game
                .ideal_player_counts
                .includes(
                    playerCount.value
                )
    );
});

/*
|--------------------------------------------------------------------------
| Otros compatibles
|--------------------------------------------------------------------------
*/

const otherGames = computed(() => {
    if (playerCount.value === 0) {
        return [];
    }

    return availableGames.value.filter(
        (game) => {
            const isIdeal =
                game
                    .ideal_player_counts
                    .includes(
                        playerCount.value
                    );

            const supports =
                playerCount.value >=
                    game.min_players &&
                playerCount.value <=
                    game.max_players;

            return (
                !isIdeal &&
                supports
            );
        }
    );
});

/*
|--------------------------------------------------------------------------
| Ordenar
|--------------------------------------------------------------------------
*/

const sortGames = (
    games: Game[]
): Game[] => {
    const result = [...games];

    if (gameSort.value === 'group_history') {
        return result.sort((a, b) => {
            const aRecommendation = recommendationByGame.value.get(a.id);
            const bRecommendation = recommendationByGame.value.get(b.id);

            // Los juegos sin recomendación quedan al final.
            const aRank = aRecommendation?.rank ?? 999999;
            const bRank = bRecommendation?.rank ?? 999999;

            if (aRank !== bRank) {
                return aRank - bRank;
            }

            return a.name.localeCompare(b.name, 'es');
        });
    }

    if (
        gameSort.value ===
        'name'
    ) {
        return result.sort(
            (a, b) =>
                a.name.localeCompare(
                    b.name,
                    'es'
                )
        );
    }

    if (
        gameSort.value ===
        'never_first'
    ) {
        return result.sort(
            (a, b) => {
                if (
                    a.total_plays ===
                        0 &&
                    b.total_plays !==
                        0
                ) {
                    return -1;
                }

                if (
                    a.total_plays !==
                        0 &&
                    b.total_plays ===
                        0
                ) {
                    return 1;
                }

                return a.name.localeCompare(
                    b.name,
                    'es'
                );
            }
        );
    }

    if (
        gameSort.value ===
        'longest_unplayed'
    ) {
        return result.sort(
            (a, b) => {
                if (
                    !a.last_played_at &&
                    !b.last_played_at
                ) {
                    return a.name.localeCompare(
                        b.name,
                        'es'
                    );
                }

                if (!a.last_played_at) {
                    return -1;
                }

                if (!b.last_played_at) {
                    return 1;
                }

                return (
                    new Date(
                        a.last_played_at
                    ).getTime() -
                    new Date(
                        b.last_played_at
                    ).getTime()
                );
            }
        );
    }

    if (
        gameSort.value ===
        'most_played'
    ) {
        return result.sort(
            (a, b) => {
                if (
                    b.total_plays !==
                    a.total_plays
                ) {
                    return (
                        b.total_plays -
                        a.total_plays
                    );
                }

                return a.name.localeCompare(
                    b.name,
                    'es'
                );
            }
        );
    }

    return result.sort(
        (a, b) => {
            if (
                a.total_plays !==
                b.total_plays
            ) {
                return (
                    a.total_plays -
                    b.total_plays
                );
            }

            return a.name.localeCompare(
                b.name,
                'es'
            );
        }
    );
};

const sortedIdealGames =
    computed(() =>
        sortGames(
            idealGames.value
        )
    );

const sortedOtherGames =
    computed(() =>
        sortGames(
            otherGames.value
        )
    );

/*
|--------------------------------------------------------------------------
| Buscador de juegos
|--------------------------------------------------------------------------
*/

const gameSearch = ref('');

const normalizeGameName = (text: string): string =>
    text
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLocaleLowerCase('es');

const normalizedGameSearch = computed(() =>
    normalizeGameName(gameSearch.value.trim())
);

const matchesGameSearch = (game: Game): boolean => {
    if (!normalizedGameSearch.value) {
        return true;
    }

    const normalizedName = normalizeGameName(game.name);

    return normalizedGameSearch.value
        .split(/\s+/)
        .filter(Boolean)
        .every(term => normalizedName.includes(term));
};

/*
|--------------------------------------------------------------------------
| Filtros de dificultad y duración
|--------------------------------------------------------------------------
*/

const showAdvancedFilters = ref(false);

const gameDifficultyFilter = ref('all');
const gameDurationFilter = ref('all');
const gameModeFilter = ref('all');

const hasActiveGameFilters = computed(() =>
    gameSearch.value.trim().length > 0 ||
    gameDifficultyFilter.value !== 'all' ||
    gameDurationFilter.value !== 'all' ||
    gameModeFilter.value !== 'all'
);

const advancedFilterCount = computed(() =>
    Number(gameDifficultyFilter.value !== 'all') +
    Number(gameDurationFilter.value !== 'all') +
    Number(gameModeFilter.value !== 'all') +
    Number(gameSort.value !== 'name')
);

const matchesGameFilters = (game: Game): boolean => {
    // Buscar por nombre.
    if (!matchesGameSearch(game)) {
        return false;
    }

    // Filtrar por complejidad BGG (escala de 1 a 5).
    if (gameDifficultyFilter.value !== 'all') {
        const complexity = game.complexity == null
            ? NaN
            : Number(game.complexity);

        if (!Number.isFinite(complexity) || complexity <= 0) {
            return false;
        }

        if (
            gameDifficultyFilter.value === 'easy' &&
            complexity > 2
        ) {
            return false;
        }

        if (
            gameDifficultyFilter.value === 'medium' &&
            (complexity <= 2 || complexity > 3)
        ) {
            return false;
        }

        if (
            gameDifficultyFilter.value === 'hard' &&
            complexity <= 3
        ) {
            return false;
        }
    }

    // Filtrar por duración máxima estimada.
    if (gameDurationFilter.value !== 'all') {
        const minutes = game.max_playtime == null
            ? NaN
            : Number(game.max_playtime);

        if (
            !Number.isFinite(minutes) ||
            minutes <= 0 ||
            minutes > Number(gameDurationFilter.value)
        ) {
            return false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Filtrar por modalidades del juego
    |--------------------------------------------------------------------------
    */

    if (gameModeFilter.value !== 'all') {
        const modes = Array.isArray(game.game_modes)
            ? game.game_modes
            : game.game_mode
                ? [game.game_mode]
                : [];

        if (gameModeFilter.value === 'unknown') {
            if (modes.length > 0) {
                return false;
            }
        } else if (!modes.includes(gameModeFilter.value)) {
            return false;
        }
    }

    return true;
};

const visibleIdealGames = computed(() =>
    sortedIdealGames.value.filter(matchesGameFilters)
);

const visibleOtherGames = computed(() =>
    sortedOtherGames.value.filter(matchesGameFilters)
);

/*
|--------------------------------------------------------------------------
| Cambio de jugadores y colecciones
|--------------------------------------------------------------------------
*/

watch(
    [
        playerCount,
        () => [...form.collection_ids],
    ],
    () => {
        // No seleccionamos automáticamente los juegos ideales.
        // Conservamos los candidatos elegidos manualmente
        // mientras sigan disponibles para la nueva combinación.
        const availableIds = new Set(
            [
                ...idealGames.value,
                ...otherGames.value,
            ].map((game) => game.id)
        );

        form.candidate_ids = form.candidate_ids.filter(
            (id) => availableIds.has(id)
        );

        // El watch de candidate_ids borra solo los datos de juegos
        // descartados. Los candidatos que siguen válidos conservan
        // su nota y su expansión prevista.
        showOtherGames.value = false;
    }
);

/*
|--------------------------------------------------------------------------
| Si quitamos un candidato limpiamos su información
|--------------------------------------------------------------------------
*/

watch(
    () => [
        ...form.candidate_ids,
    ],
    (candidateIds) => {
        const selected =
            new Set(candidateIds);

        for (
            const gameId of Object.keys(
                form.candidate_notes
            )
        ) {
            const id =
                Number(gameId);

            if (!selected.has(id)) {
                delete form
                    .candidate_notes[id];
            }
        }

        for (
            const gameId of Object.keys(
                form
                    .candidate_expansion_ids
            )
        ) {
            const id =
                Number(gameId);

            if (!selected.has(id)) {
                delete form
                    .candidate_expansion_ids[
                    id
                ];
            }
        }
    }
);

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
            month: 'short',
            year: 'numeric',
        }
    ).format(
        new Date(
            `${date}T12:00:00`
        )
    );
};

const submit = () => {
    if (!canCreatePoll.value) {
        return;
    }

    form.post('/polls');
};
</script>

<template>
    <Head title="Nueva votación" />

    <AppLayout>
        <div
            class="mx-auto box-border w-full min-w-0 max-w-5xl px-4 py-6 sm:px-6"
        >
            <Link
                href="/polls"
                class="text-sm font-semibold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
            >
                ← Volver a votaciones
            </Link>

            <!-- CABECERA -->

            <div class="mt-4">
                <p
                    class="font-semibold text-[#5200FF]"
                >
                    NUEVA VOTACIÓN
                </p>

                <h1
                    class="text-3xl font-bold text-zinc-900 dark:text-zinc-100"
                >
                    ¿A qué jugamos?
                </h1>

                <p
                    class="mt-2 text-zinc-500 dark:text-zinc-400"
                >
                    Selecciona quién juega y
                    te mostraremos los juegos
                    ideales.
                </p>
            </div>

            <!-- Resumen persistente de la preparación de la votación. -->
            <nav
                aria-label="Progreso de creación de la votación"
                class="sticky top-2 z-30 mt-6 rounded-2xl border border-zinc-200 bg-white/95 p-3 shadow-md backdrop-blur dark:border-zinc-700 dark:bg-zinc-950/95"
            >
                <div class="flex flex-wrap items-center gap-2 text-xs font-bold sm:text-sm">
                    <a href="#poll-collections" class="rounded-full px-3 py-2 transition hover:opacity-80"
                        :class="form.collection_ids.length ? 'bg-violet-100 text-[#5200FF] dark:bg-violet-950 dark:text-violet-200' : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-300'">
                        {{ form.collection_ids.length ? '✓' : '1' }} Colecciones · {{ form.collection_ids.length }}
                    </a>
                    <a href="#poll-players" class="rounded-full px-3 py-2 transition hover:opacity-80"
                        :class="playerCount >= 2 ? 'bg-violet-100 text-[#5200FF] dark:bg-violet-950 dark:text-violet-200' : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-300'">
                        {{ playerCount >= 2 ? '✓' : '2' }} Jugadores · {{ playerCount }}
                    </a>
                    <a href="#poll-candidates" class="rounded-full px-3 py-2 transition hover:opacity-80"
                        :class="form.candidate_ids.length ? 'bg-violet-100 text-[#5200FF] dark:bg-violet-950 dark:text-violet-200' : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-300'">
                        {{ form.candidate_ids.length ? '✓' : '3' }} Juegos · {{ form.candidate_ids.length }}
                    </a>
                    <a href="#poll-review" class="ml-auto whitespace-nowrap px-2 py-2 text-[#5200FF] hover:underline dark:text-[#CCFF00]">
                        Revisar ↓
                    </a>
                </div>
            </nav>

            <form
                class="mt-5 w-full min-w-0 space-y-6 sm:space-y-8"
                @submit.prevent="submit"
            >
                <!-- ================================================== -->
                <!-- NOMBRE                                             -->
                <!-- ================================================== -->

                <section
                    class="box-border w-full min-w-0 scroll-mt-24 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950 sm:p-6"
                >
                    <label
                        class="mb-2 block font-semibold"
                    >
                        Nombre de la partida
                    </label>

                    <input
                        v-model="form.name"
                        type="text"
                        class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                        placeholder="Partida del domingo"
                    />

                    <InputError :message="form.errors.name" />
                </section>

                <!-- ================================================== -->
                <!-- COLECCIONES                                        -->
                <!-- ================================================== -->

                <section
                    id="poll-collections"
                    class="box-border w-full min-w-0 scroll-mt-24 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950 sm:p-6"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="flex h-9 w-9 items-center justify-center rounded-full bg-[#5200FF] font-black text-white"
                        >
                            1
                        </div>

                        <div>
                            <p
                                class="font-bold text-zinc-900 dark:text-zinc-100"
                            >
                                Elige las colecciones
                            </p>

                            <p
                                class="text-sm text-zinc-500 dark:text-zinc-400"
                            >
                                Usaremos los juegos de todas las
                                colecciones seleccionadas.
                            </p>
                        </div>
                    </div>

                    <div
                        class="mt-5 grid gap-3 sm:grid-cols-2"
                    >
                        <label
                            v-for="collection in collections"
                            :key="collection.id"
                            class="cursor-pointer"
                        >
                            <input
                                v-model="form.collection_ids"
                                type="checkbox"
                                :value="collection.id"
                                class="peer sr-only"
                            />

                            <div
                                class="rounded-xl border border-zinc-200 bg-white p-4 transition peer-checked:border-[#5200FF] peer-checked:bg-violet-50 dark:border-zinc-800 dark:bg-zinc-900 dark:peer-checked:bg-violet-950/30"
                            >
                                <div
                                    class="flex items-center justify-between gap-3"
                                >
                                    <span
                                        class="font-bold text-zinc-900 dark:text-zinc-100"
                                    >
                                        {{ collection.name }}
                                    </span>

                                    <span
                                        v-if="collection.is_default"
                                        class="rounded-full bg-[#CCFF00] px-2 py-1 text-xs font-bold text-black"
                                    >
                                        Principal
                                    </span>
                                </div>

                                <p
                                    class="mt-2 text-sm text-zinc-500 dark:text-zinc-400"
                                >
                                    🎲
                                    {{ collection.games_count }}
                                    {{
                                        collection.games_count === 1
                                            ? 'juego'
                                            : 'juegos'
                                    }}
                                </p>
                            </div>
                        </label>
                    </div>

                    <InputError :message="form.errors.collection_ids" />

                    <!-- RESUMEN -->

                    <div
                        v-if="form.collection_ids.length"
                        class="mt-5 rounded-xl bg-zinc-100 p-4 dark:bg-zinc-900"
                    >
                        <p
                            class="text-sm font-semibold text-zinc-700 dark:text-zinc-300"
                        >
                            🎲
                            {{ availableGames.length }}

                            {{
                                availableGames.length === 1
                                    ? 'juego disponible'
                                    : 'juegos disponibles'
                            }}

                            entre las colecciones
                            seleccionadas.
                        </p>
                    </div>

                    <div
                        v-else
                        class="mt-5 rounded-xl bg-orange-100 p-4 text-sm font-semibold text-orange-800 dark:bg-orange-950/30 dark:text-orange-300"
                    >
                        Selecciona al menos una
                        colección.
                    </div>
                </section>

                <!-- ================================================== -->
                <!-- JUGADORES                                          -->
                <!-- ================================================== -->

                <section
                    id="poll-players"
                    class="box-border w-full min-w-0 scroll-mt-24 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950 sm:p-6"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="flex h-9 w-9 items-center justify-center rounded-full bg-[#5200FF] font-black text-white"
                        >
                            2
                        </div>

                        <div>
                            <p
                                class="font-bold text-zinc-900 dark:text-zinc-100"
                            >
                                ¿Quién juega?
                            </p>

                            <p
                                class="text-sm text-zinc-500 dark:text-zinc-400"
                            >
                                El número de participantes determinará
                                qué juegos son ideales.
                            </p>
                        </div>
                    </div>

                    <!-- Tarjetas de tamaño fijo: seleccionar solo cambia colores y marca -->
                    <div class="mt-4 grid w-full grid-cols-1 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                        <button
                            v-for="player in players"
                            :key="player.id"
                            type="button"
                            @click="togglePlayer(player.id)"
                            :aria-pressed="isPlayerSelected(player.id)"
                            class="box-border flex h-14 w-full min-w-0 items-center justify-between gap-2 rounded-xl border-2 px-3 text-left transition-colors duration-150 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#CCFF00] sm:h-16"
                            :class="
                                isPlayerSelected(player.id)
                                    ? 'border-[#5200FF] bg-[#5200FF]/15 dark:bg-[#5200FF]/20'
                                    : 'border-zinc-200 bg-zinc-50 hover:border-zinc-400 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-600'
                            "
                        >
                            <span class="min-w-0 truncate text-sm font-bold text-zinc-900 dark:text-white sm:text-base">
                                {{ player.name }}
                            </span>

                            <!-- Mantener el espacio del check también al deseleccionar -->
                            <span
                                aria-hidden="true"
                                class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[#CCFF00] text-sm font-black text-black transition-opacity duration-150"
                                :class="isPlayerSelected(player.id) ? 'opacity-100' : 'opacity-0'"
                            >
                                ✓
                            </span>
                        </button>
                    </div>

                    <div
                        v-if="playerCount"
                        class="mt-5 inline-flex rounded-full bg-[#CCFF00] px-4 py-2 font-bold text-black"
                    >
                        👥 {{ playerCount }}

                        {{
                            playerCount === 1
                                ? 'jugador'
                                : 'jugadores'
                        }}
                    </div>

                    <InputError :message="form.errors.player_ids" />
                </section>

                <!-- ================================================== -->
                <!-- CANDIDATOS                                         -->
                <!-- ================================================== -->

                <section
                    id="poll-candidates"
                    v-if="playerCount >= 2"
                    class="box-border w-full min-w-0 scroll-mt-24 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950 sm:p-6"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="flex h-9 w-9 items-center justify-center rounded-full bg-[#5200FF] font-black text-white"
                        >
                            3
                        </div>

                        <div>
                            <p
                                class="font-bold text-zinc-900 dark:text-zinc-100"
                            >
                                Elige los juegos
                            </p>

                            <p
                                class="text-sm text-zinc-500 dark:text-zinc-400"
                            >
                                Partimos de los ideales, pero puedes
                                añadir cualquier juego compatible.
                            </p>
                        </div>
                    </div>

                    <!-- BUSCADOR DE JUEGOS -->
                    <div class="mt-5">
                        <label
                            for="game-search"
                            class="text-sm font-bold"
                        >
                            Buscar juego
                        </label>

                        <div class="relative mt-2">
                            <input
                                id="game-search"
                                v-model="gameSearch"
                                type="search"
                                placeholder="Buscar por nombre: castillo blanco..."
                                class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 outline-none focus:border-[#5200FF] dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                            />
                        </div>

                        <p
                            v-if="hasActiveGameFilters"
                            class="mt-2 text-sm text-zinc-500"
                        >
                            {{
                                visibleIdealGames.length +
                                visibleOtherGames.length
                            }}
                            juegos encontrados
                        </p>
                    </div>

                    <!-- FILTROS DE JUEGOS -->
                    <button
                        type="button"
                        :aria-expanded="showAdvancedFilters"
                        aria-controls="poll-advanced-filters"
                        class="mt-3 flex w-full items-center justify-between rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-left text-sm font-bold text-[#5200FF] dark:border-zinc-700 dark:bg-zinc-900 dark:text-[#CCFF00] sm:hidden"
                        @click="showAdvancedFilters = !showAdvancedFilters"
                    >
                        <span>Filtros y orden <span v-if="advancedFilterCount">({{ advancedFilterCount }} activos)</span></span>
                        <span aria-hidden="true">{{ showAdvancedFilters ? '−' : '+' }}</span>
                    </button>
                    <div
                        id="poll-advanced-filters"
                        :class="showAdvancedFilters ? 'block' : 'hidden sm:block'"
                    >
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="text-sm font-bold">
                                Dificultad
                            </label>

                            <select
                                v-model="gameDifficultyFilter"
                                class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                            >
                                <option value="all">Todas las dificultades</option>
                                <option value="easy">Ligero (hasta 2)</option>
                                <option value="medium">Medio (más de 2 hasta 3)</option>
                                <option value="hard">Experto (más de 3)</option>
                            </select>
                        </div>

                        <div>
                            <label class="text-sm font-bold">
                                Duración máxima
                            </label>

                            <select
                                v-model="gameDurationFilter"
                                class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                            >
                                <option value="all">Cualquier duración</option>
                                <option value="30">Hasta 30 minutos</option>
                                <option value="60">Hasta 60 minutos</option>
                                <option value="90">Hasta 90 minutos</option>
                                <option value="120">Hasta 120 minutos</option>
                                <option value="180">Hasta 180 minutos</option>
                            </select>
                        </div>
                    </div>

                    <!-- FILTRO DE MODALIDAD -->
                    <div class="mt-4">
                        <label class="text-sm font-bold">
                            Modalidad de juego
                        </label>

                        <select
                            v-model="gameModeFilter"
                            class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                        >
                            <option value="all">
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

                            <option value="unknown">
                                Sin clasificar
                            </option>
                        </select>
                    </div>

                    <button
                        v-if="hasActiveGameFilters"
                        type="button"
                        class="mt-3 text-sm font-semibold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
                        @click="
                            gameSearch = '';
                            gameDifficultyFilter = 'all';
                            gameDurationFilter = 'all';
                            gameModeFilter = 'all'
                        "
                    >
                        Limpiar filtros
                    </button>

                    <!-- ORDEN -->

                    <div
                        class="mt-5 max-w-sm"
                    >
                        <label
                            class="text-sm font-bold"
                        >
                            Ordenar juegos por
                        </label>

                        <select
                            v-model="gameSort"
                            class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                        >
                            <option value="name">
                                Nombre
                            </option>

                            <option value="group_history">
                                ✨ Sugerencia del grupo
                            </option>

                            <option
                                value="never_first"
                            >
                                Nunca jugados primero
                            </option>

                            <option
                                value="longest_unplayed"
                            >
                                Hace más tiempo que no
                                salen
                            </option>

                            <option
                                value="most_played"
                            >
                                Más jugados
                            </option>

                            <option
                                value="least_played"
                            >
                                Menos jugados
                            </option>
                        </select>
                    </div>

                    <p v-if="gameSort === 'group_history' && recommendationsLoading"
                        role="status"
                        class="mt-3 flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
                        <span class="h-4 w-4 animate-spin rounded-full border-2 border-[#5200FF] border-t-transparent" aria-hidden="true"></span>
                        Calculando sugerencias para el grupo...
                    </p>
                    <div v-else-if="gameSort === 'group_history' && recommendationsError"
                        class="mt-3 flex flex-wrap items-center gap-2 text-sm text-orange-700 dark:text-orange-300">
                        <span>{{ recommendationsError }}</span>
                        <button type="button" class="font-bold underline" @click="loadGroupRecommendations">Reintentar</button>
                    </div>
                    </div>

                    <div
                        v-if="playerCount >= 2"
                        class="mt-6 flex flex-wrap items-center justify-between gap-3"
                    >
                        <p
                            class="text-sm text-zinc-500 dark:text-zinc-400"
                        >
                            <span
                                class="font-black text-[#5200FF] dark:text-[#CCFF00]"
                            >
                                {{ form.candidate_ids.length }}
                            </span>

                            juegos seleccionados
                        </p>

                        <div class="flex gap-2">
                            <button
                                type="button"
                                class="text-sm font-bold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
                                @click="
                                    form.candidate_ids = [
                                        ...new Set([
                                            ...form.candidate_ids,
                                            ...visibleIdealGames.map((game) => game.id),
                                        ]),
                                    ]
                                "
                            >
                                Seleccionar ideales
                            </button>

                            <button
                                v-if="form.candidate_ids.length"
                                type="button"
                                class="text-sm font-semibold text-zinc-500 hover:text-red-500"
                                @click="form.candidate_ids = []"
                            >
                                Limpiar
                            </button>
                        </div>
                    </div>

                    <!-- ============================================== -->

                    <!-- CANDIDATOS SELECCIONADOS -->
                    <div
                        v-if="selectedCandidateGames.length"
                        class="mt-5 rounded-2xl border border-violet-200 bg-violet-50 p-4 dark:border-violet-900 dark:bg-violet-950/20"
                    >
                        <p class="text-sm font-bold text-violet-900 dark:text-violet-200">
                            Juegos seleccionados ({{ selectedCandidateGames.length }})
                        </p>

                        <p class="mt-1 text-xs text-violet-700 dark:text-violet-300">
                            Pulsa la × para quitar un juego de la votación.
                        </p>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <button
                                v-for="game in selectedCandidateGames"
                                :key="game.id"
                                type="button"
                                :aria-label="`Quitar ${game.name} de los candidatos`"
                                class="inline-flex max-w-full items-center gap-2 rounded-full border border-violet-200 bg-white px-3 py-2 text-xs font-bold text-violet-800 transition hover:border-red-300 hover:bg-red-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600 dark:border-violet-800 dark:bg-zinc-900 dark:text-violet-200"
                                @click="form.candidate_ids = form.candidate_ids.filter(id => id !== game.id)"
                            >
                                <span class="max-w-[220px] truncate">
                                    {{ game.name }}
                                </span>
                                <span aria-hidden="true">×</span>
                            </button>
                        </div>
                    </div>

                    <div
                        v-if="hasActiveGameFilters && !visibleIdealGames.length && !visibleOtherGames.length"
                        class="mt-6 rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-5 text-center dark:border-zinc-700 dark:bg-zinc-900"
                    >
                        <p class="font-bold text-zinc-800 dark:text-zinc-100">No hay juegos que coincidan con los filtros.</p>
                        <button type="button" class="mt-2 text-sm font-bold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
                            @click="gameSearch = ''; gameDifficultyFilter = 'all'; gameDurationFilter = 'all'; gameModeFilter = 'all'">
                            Limpiar filtros
                        </button>
                    </div>

                    <!-- IDEALES                                        -->
                    <!-- ============================================== -->
                    <div v-if="visibleIdealGames.length" class="mt-7 flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="text-lg font-black text-zinc-900 dark:text-zinc-100">Ideales para {{ playerCount }} jugadores</h2>
                        <span class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">{{ visibleIdealGames.length }} juegos</span>
                    </div>

                    <div
                        v-if="
                            visibleIdealGames.length
                        "
                        class="mt-6 grid gap-4 sm:grid-cols-2"
                    >
                        <article
                            v-for="game in visibleIdealGames"
                            :key="game.id"
                            class="flex self-start min-w-0 items-start overflow-hidden rounded-2xl border border-zinc-200 bg-white transition-colors dark:border-zinc-800 dark:bg-zinc-900"
                            :class="form.candidate_ids.includes(game.id)
                                ? 'border-violet-500 ring-2 ring-violet-500/50 dark:border-violet-400'
                                : ''"
                        >
                            <!-- PORTADA -->

                            <div
                                v-if="
                                    game.image_url
                                "
                                class="flex h-32 w-24 shrink-0 items-center justify-center overflow-hidden bg-zinc-100 p-2 dark:bg-zinc-800 sm:h-40 sm:w-28"
                            >
                                <img
                                    :src="
                                        game.image_url
                                    "
                                    :alt="
                                        game.name
                                    "
                                    loading="lazy"
                                    class="h-full w-full object-contain"
                                />
                            </div>

                            <div
                                v-else
                                class="flex h-32 w-24 shrink-0 items-center justify-center bg-zinc-100 px-2 text-center text-xs text-zinc-400 dark:bg-zinc-800 dark:text-zinc-500 sm:h-40 sm:w-28"
                            >
                                Sin portada
                            </div>

                            <div class="min-w-0 flex-1 p-3 sm:p-4">
                                <!-- SELECCIÓN -->

                                <label
                                    class="flex cursor-pointer items-center gap-3"
                                >
                                    <input
                                        v-model="
                                            form.candidate_ids
                                        "
                                        type="checkbox"
                                        :value="game.id"
                                        class="h-5 w-5 accent-[#5200FF]"
                                    />

                                    <span
                                        class="min-w-0 break-words text-base font-bold leading-snug sm:text-lg"
                                    >
                                        {{ game.name }}
                                    </span>
                                </label>

                                <!-- DATOS -->

                                <div
                                    class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm text-zinc-500 dark:text-zinc-400"
                                >
                                    <span>
                                        👥
                                        {{
                                            game.min_players
                                        }}–{{
                                            game.max_players
                                        }}
                                    </span>

                                    <span
                                        v-if="
                                            game.min_playtime
                                        "
                                    >
                                        ⏱
                                        {{
                                            game.min_playtime
                                        }}

                                        <template
                                            v-if="
                                                game.max_playtime &&
                                                game.max_playtime !==
                                                    game.min_playtime
                                            "
                                        >
                                            –{{
                                                game.max_playtime
                                            }}
                                        </template>

                                        min
                                    </span>

                                    <span
                                        v-if="
                                            game.complexity !==
                                            null
                                        "
                                    >
                                        🧠
                                        {{
                                            game.complexity
                                        }}/5
                                    </span>
                                </div>

                                <!-- HISTORIAL -->

                                <div
                                    class="mt-3 rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-800"
                                >
                                    <p
                                        v-if="
                                            game.total_plays ===
                                            0
                                        "
                                        class="text-sm font-semibold text-zinc-500 dark:text-zinc-400"
                                    >
                                        🎲 Nunca jugado
                                    </p>

                                    <div
                                        v-else
                                        class="space-y-1 text-sm"
                                    >
                                        <p
                                            class="font-bold text-[#5200FF] dark:text-[#CCFF00]"
                                        >
                                            🎲
                                            {{
                                                game.total_plays
                                            }}
                                            {{
                                                game.total_plays ===
                                                1
                                                    ? 'partida'
                                                    : 'partidas'
                                            }}
                                        </p>

                                        <p
                                            v-if="
                                                game.last_played_at
                                            "
                                            class="text-zinc-500 dark:text-zinc-400"
                                        >
                                            📅 Última:
                                            {{
                                                formatDate(
                                                    game.last_played_at
                                                )
                                            }}
                                        </p>
                                    </div>
                                </div>

                                <!-- ================================== -->
                                <!-- INFORMACIÓN DEL CANDIDATO          -->
                                <!-- ================================== -->

                                <div
                                    v-if="
                                        form.candidate_ids.includes(
                                            game.id
                                        )
                                    "
                                    class="mt-5 space-y-4 border-t border-zinc-200 pt-4 dark:border-zinc-700"
                                >
                                    <!-- EXPANSIÓN -->

                                    <div
                                        v-if="
                                            game.expansions.length
                                        "
                                    >
                                        <label
                                            class="text-sm font-semibold"
                                        >
                                            🧩 Expansión prevista
                                            <span
                                                class="font-normal text-zinc-500"
                                            >
                                                (opcional)
                                            </span>
                                        </label>

                                        <select
                                            v-model="
                                                form
                                                    .candidate_expansion_ids[
                                                    game.id
                                                ]
                                            "
                                            class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100"
                                        >
                                            <option
                                                :value="null"
                                            >
                                                No especificar
                                            </option>

                                            <option
                                                v-for="expansion in game.expansions"
                                                :key="
                                                    expansion.id
                                                "
                                                :value="
                                                    expansion.id
                                                "
                                            >
                                                {{
                                                    expansion.name
                                                }}
                                            </option>
                                        </select>

                                        <p
                                            class="mt-2 text-xs text-zinc-500 dark:text-zinc-400"
                                        >
                                            Se mostrará como información
                                            en la votación. Al registrar
                                            la partida podrás cambiarla.
                                        </p>

                                        <p
                                            v-if="
                                                form.errors[
                                                    `candidate_expansion_ids.${game.id}`
                                                ]
                                            "
                                            class="mt-2 text-sm text-red-500"
                                        >
                                            {{
                                                form.errors[
                                                    `candidate_expansion_ids.${game.id}`
                                                ]
                                            }}
                                        </p>
                                    </div>

                                    <!-- NOTA -->

                                    <div>
                                        <label
                                            class="text-sm font-semibold"
                                        >
                                            Información para esta
                                            partida
                                        </label>

                                        <textarea
                                            v-model="
                                                form
                                                    .candidate_notes[
                                                    game.id
                                                ]
                                            "
                                            rows="2"
                                            class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100"
                                            placeholder="Ej: Podemos jugar el escenario avanzado..."
                                        ></textarea>
                                    </div>
                                </div>
                            </div>
                        </article>
                    </div>

                    <div
                        v-if="playerCount >= 2 && idealGames.length === 0 && !hasActiveGameFilters"
                        class="mt-6 rounded-2xl border border-orange-200 bg-orange-50 p-5 dark:border-orange-900 dark:bg-orange-950/20"
                    >
                        <p
                            class="font-bold text-orange-700 dark:text-orange-300"
                        >
                            No hay juegos marcados como ideales
                            para {{ playerCount }} jugadores.
                        </p>

                        <p
                            class="mt-2 text-sm text-zinc-600 dark:text-zinc-300"
                        >
                            Puedes elegir entre los juegos compatibles
                            que admiten este número de jugadores.
                        </p>

                        <button
                            type="button"
                            class="mt-3 font-bold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
                            @click="showOtherGames = true"
                        >
                            Ver juegos compatibles →
                        </button>
                    </div>

                    <!-- ============================================== -->
                    <!-- OTROS                                          -->
                    <!-- ============================================== -->

                    <div
                        v-if="
                            visibleOtherGames.length
                        "
                        class="mt-8 border-t border-zinc-200 pt-6 dark:border-zinc-800"
                    >
                        <button
                            v-if="!hasActiveGameFilters"
                            type="button"
                            class="font-bold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
                            @click="
                                showOtherGames =
                                    !showOtherGames
                            "
                        >
                            {{
                                showOtherGames
                                    ? '− Ocultar otros juegos'
                                    : `+ Ver otros juegos compatibles (${visibleOtherGames.length})`
                            }}
                        </button>

                        <p
                            class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"
                        >
                            Otros juegos que admiten
                            {{ playerCount }}
                            jugadores.
                        </p>

                        <div
                            v-if="
                                showOtherGames || hasActiveGameFilters
                            "
                            class="mt-5 grid gap-4 sm:grid-cols-2"
                        >
                            <article
                                v-for="game in visibleOtherGames"
                                :key="game.id"
                                class="self-start rounded-2xl border border-zinc-200 bg-white p-4 transition-colors dark:border-zinc-800 dark:bg-zinc-900"
                                    :class="form.candidate_ids.includes(game.id)
                                        ? 'border-violet-500 ring-2 ring-violet-500/50 dark:border-violet-400'
                                        : ''"
                            >
                                <label
                                    class="flex cursor-pointer items-start gap-3"
                                >
                                    <input
                                        v-model="
                                            form.candidate_ids
                                        "
                                        type="checkbox"
                                        :value="game.id"
                                        class="mt-1 h-5 w-5 shrink-0 accent-[#5200FF]"
                                    />

                                    <div class="flex h-20 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-zinc-100 p-1 dark:bg-zinc-800">
                                            <img
                                                v-if="game.image_url"
                                                :src="game.image_url"
                                                :alt="`Portada de ${game.name}`"
                                                loading="lazy"
                                                class="h-full w-full object-contain"
                                            />
                                            <span v-else aria-hidden="true" class="text-lg">🎲</span>
                                        </div>

                                        <div class="min-w-0 flex-1">
                                        <p
                                            class="font-bold"
                                        >
                                            {{ game.name }}
                                        </p>

                                        <div
                                            class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-sm text-zinc-500 dark:text-zinc-400"
                                        >
                                            <span>
                                                👥
                                                {{
                                                    game.min_players
                                                }}–{{
                                                    game.max_players
                                                }}
                                            </span>

                                            <span
                                                v-if="
                                                    game.min_playtime
                                                "
                                            >
                                                ⏱
                                                {{
                                                    game.min_playtime
                                                }}

                                                <template
                                                    v-if="
                                                        game.max_playtime &&
                                                        game.max_playtime !==
                                                            game.min_playtime
                                                    "
                                                >
                                                    –{{
                                                        game.max_playtime
                                                    }}
                                                </template>

                                                min
                                            </span>
                                        </div>

                                        <div
                                            class="mt-3 rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-800"
                                        >
                                            <p
                                                v-if="
                                                    game.total_plays ===
                                                    0
                                                "
                                                class="text-sm font-semibold text-zinc-500 dark:text-zinc-400"
                                            >
                                                🎲 Nunca jugado
                                            </p>

                                            <div
                                                v-else
                                                class="space-y-1 text-sm"
                                            >
                                                <p
                                                    class="font-bold text-[#5200FF] dark:text-[#CCFF00]"
                                                >
                                                    🎲
                                                    {{
                                                        game.total_plays
                                                    }}
                                                    {{
                                                        game.total_plays ===
                                                        1
                                                            ? 'partida'
                                                            : 'partidas'
                                                    }}
                                                </p>

                                                <p
                                                    v-if="
                                                        game.last_played_at
                                                    "
                                                    class="text-zinc-500 dark:text-zinc-400"
                                                >
                                                    📅 Última:
                                                    {{
                                                        formatDate(
                                                            game.last_played_at
                                                        )
                                                    }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </label>

                                <!-- DATOS DEL CANDIDATO -->

                                <div
                                    v-if="
                                        form.candidate_ids.includes(
                                            game.id
                                        )
                                    "
                                    class="mt-5 space-y-4 border-t border-zinc-200 pt-4 dark:border-zinc-700"
                                >
                                    <div
                                        v-if="
                                            game.expansions.length
                                        "
                                    >
                                        <label
                                            class="text-sm font-semibold"
                                        >
                                            🧩 Expansión prevista
                                            <span
                                                class="font-normal text-zinc-500"
                                            >
                                                (opcional)
                                            </span>
                                        </label>

                                        <select
                                            v-model="
                                                form
                                                    .candidate_expansion_ids[
                                                    game.id
                                                ]
                                            "
                                            class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100"
                                        >
                                            <option
                                                :value="null"
                                            >
                                                No especificar
                                            </option>

                                            <option
                                                v-for="expansion in game.expansions"
                                                :key="
                                                    expansion.id
                                                "
                                                :value="
                                                    expansion.id
                                                "
                                            >
                                                {{
                                                    expansion.name
                                                }}
                                            </option>
                                        </select>
                                    </div>

                                    <div>
                                        <label
                                            class="text-sm font-semibold"
                                        >
                                            Información para esta
                                            partida
                                        </label>

                                        <textarea
                                            v-model="
                                                form
                                                    .candidate_notes[
                                                    game.id
                                                ]
                                            "
                                            rows="2"
                                            class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100"
                                            placeholder="Información opcional..."
                                        ></textarea>
                                    </div>
                                </div>
                            </article>
                        </div>
                    </div>

                    <InputError :message="form.errors.candidate_ids" />
                </section>

                <!-- MÍNIMO -->

                <p
                    v-if="
                        playerCount >= 2 &&
                        form.candidate_ids.length <
                            1
                    "
                    class="rounded-xl bg-orange-100 p-4 text-sm font-semibold text-orange-800 dark:bg-orange-950/40 dark:text-orange-300"
                >
                    Selecciona al menos 1 juego candidato
                    para crear la votación.
                </p>

                <!-- ================================================== -->
                <!-- 4. REVISAR                                         -->
                <!-- ================================================== -->

                <section
                    id="poll-review"
                    class="box-border w-full min-w-0 scroll-mt-24 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950 sm:p-6"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="flex h-9 w-9 items-center justify-center rounded-full bg-[#5200FF] font-black text-white"
                        >
                            4
                        </div>

                        <div>
                            <p
                                class="font-bold text-zinc-900 dark:text-zinc-100"
                            >
                                Revisar y crear
                            </p>

                            <p
                                class="text-sm text-zinc-500 dark:text-zinc-400"
                            >
                                Comprueba rápidamente la sesión
                                antes de crearla.
                            </p>
                        </div>
                    </div>

                    <!-- RESUMEN -->

                    <div
                        class="mt-6 grid gap-5 md:grid-cols-3"
                    >
                        <!-- COLECCIONES -->

                        <div>
                            <p
                                class="text-xs font-black uppercase tracking-wide text-zinc-400"
                            >
                                Colecciones
                            </p>

                            <div
                                class="mt-2 flex flex-wrap gap-2"
                            >
                                <span
                                    v-for="collection in selectedCollections"
                                    :key="collection.id"
                                    class="rounded-full bg-violet-100 px-3 py-1 text-sm font-bold text-[#5200FF] dark:bg-violet-950/40 dark:text-violet-300"
                                >
                                    {{ collection.name }}
                                </span>

                                <span
                                    v-if="selectedCollections.length === 0"
                                    class="text-sm text-red-500"
                                >
                                    Falta seleccionar
                                </span>
                            </div>
                        </div>

                        <!-- JUGADORES -->

                        <div>
                            <p
                                class="text-xs font-black uppercase tracking-wide text-zinc-400"
                            >
                                Jugadores
                            </p>

                            <div
                                class="mt-2 flex flex-wrap gap-2"
                            >
                                <span
                                    v-for="player in selectedPlayers"
                                    :key="player.id"
                                    class="rounded-full bg-zinc-100 px-3 py-1 text-sm font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                                >
                                    {{ player.name }}
                                </span>

                                <span
                                    v-if="selectedPlayers.length < 2"
                                    class="text-sm text-red-500"
                                >
                                    Mínimo 2
                                </span>
                            </div>
                        </div>

                        <!-- CANDIDATOS -->

                        <div>
                            <p
                                class="text-xs font-black uppercase tracking-wide text-zinc-400"
                            >
                                Juegos candidatos
                            </p>

                            <p
                                class="mt-2 text-3xl font-black"
                                :class="
                                    form.candidate_ids.length >= 1
                                        ? 'text-[#5200FF] dark:text-[#CCFF00]'
                                        : 'text-red-500'
                                "
                            >
                                {{ form.candidate_ids.length }}
                            </p>

                            <p
                                class="text-sm text-zinc-500"
                            >
                                mínimo 1
                            </p>
                        </div>
                    </div>

                    <!-- JUEGOS -->

                    <div
                        v-if="selectedCandidateGames.length"
                        class="mt-6 border-t border-zinc-200 pt-5 dark:border-zinc-800"
                    >
                        <p
                            class="text-xs font-black uppercase tracking-wide text-zinc-400"
                        >
                            Se votará entre
                        </p>

                        <div
                            class="mt-3 grid gap-2 sm:grid-cols-2"
                        >
                            <div
                                v-for="game in selectedCandidateGames"
                                :key="game.id"
                                class="flex items-center gap-3 rounded-xl bg-zinc-50 p-3 dark:bg-zinc-900"
                            >
                                <div
                                    class="h-12 w-10 shrink-0 overflow-hidden rounded-md bg-zinc-200 dark:bg-zinc-800"
                                >
                                    <img
                                        v-if="game.image_url"
                                        :src="game.image_url"
                                        :alt="game.name"
                                        class="h-full w-full object-cover"
                                    />
                                </div>

                                <div class="min-w-0">
                                    <p
                                        class="truncate font-bold text-zinc-900 dark:text-zinc-100"
                                    >
                                        {{ game.name }}
                                    </p>

                                    <p
                                        v-if="
                                            selectedExpansionName(
                                                game
                                            )
                                        "
                                        class="truncate text-xs font-semibold text-[#5200FF] dark:text-violet-300"
                                    >
                                        🧩
                                        {{
                                            selectedExpansionName(
                                                game
                                            )
                                        }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- MENSAJE -->

                    <div
                        class="mt-6 rounded-xl p-4"
                        :class="
                            canCreatePoll
                                ? 'bg-green-50 text-green-700 dark:bg-green-950/20 dark:text-green-300'
                                : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-300'
                        "
                    >
                        <p class="font-semibold">
                            <template
                                v-if="canCreatePoll"
                            >
                                ✓ Todo preparado para crear
                                la votación.
                            </template>

                            <template v-else>
                                Completa los pasos anteriores
                                para continuar.
                            </template>
                        </p>
                    </div>
                </section>

                <!-- CREAR -->

                <button
                    type="submit"
                    :disabled="
                        form.processing ||
                        !canCreatePoll
                    "
                    class="w-full rounded-2xl bg-[#5200FF] px-6 py-4 text-lg font-black text-white shadow-sm transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40"
                >
                    {{
                        form.processing
                            ? 'Creando votación...'
                            : 'Crear votación'
                    }}
                </button>

                <p
                    class="text-center text-sm text-zinc-500 dark:text-zinc-400"
                >
                    Se creará como borrador para que puedas
                    revisarla antes de compartirla con el grupo.
                </p>
            </form>

            <!-- Mismo indicador circular del catálogo, sin restaurar la posición
                 anterior al iniciar una votación nueva. -->
            <ScrollToTopProgress :remember-position="false" />
        </div>
    </AppLayout>
</template>

<!-- Mantiene estable el espacio de la barra de desplazamiento cuando
     aparecen los juegos al seleccionar al segundo participante. -->
<style>
html {
    scrollbar-gutter: stable;
}
</style>
