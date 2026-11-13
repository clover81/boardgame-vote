<script setup lang="ts">
import PencilSparklesIcon from '@/components/icons/PencilSparklesIcon.vue';
import { playerNameColor } from '@/lib/player-colors';
import MeepleIcon from '@/components/icons/MeepleIcon.vue';
import { BuildingComplexPlus } from '@lucide/vue';
import PlayingCardsFanIcon from '@/components/icons/PlayingCardsFanIcon.vue';
import { CalendarHeart } from '@lucide/vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import {
    Head,
    Link,
    router,
    usePage,
} from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import ScrollToTopProgress from '@/components/ScrollToTopProgress.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/*
|--------------------------------------------------------------------------
| Tipos
|--------------------------------------------------------------------------
*/

interface Player {
    id: number;
    name: string;
}

interface Game {
    id: number;
    name: string;
    image_url: string | null;
}

interface Expansion {
    id: number;
    name: string;
}

interface Play {
    id: number;
    played_at: string;
    notes: string | null;
    poll: { id: number; name: string } | null;

    game: Game;

    expansion: Expansion | null;

    players: Player[];
}

interface Stats {
    total_plays: number;
    unique_games: number;
    unique_players: number;
}

const props = defineProps<{
    plays: Play[];
    stats: Stats;
}>();

// Evitar el icono de imagen rota cuando falla una portada de BGG.
const brokenCoverIds = ref<number[]>([]);

const markCoverAsBroken = (gameId: number) => {
    if (!brokenCoverIds.value.includes(gameId)) {
        brokenCoverIds.value.push(gameId);
    }
};

/*
|--------------------------------------------------------------------------
| Filtros
|--------------------------------------------------------------------------
*/

const search = ref('');

const selectedGameId =
    ref<number | null>(null);

const selectedPlayerId =
    ref<number | null>(null);

const sortBy = ref<
    'newest' | 'oldest'
>('newest');

// En móvil mantenemos los filtros secundarios plegados.
const showAdvancedFilters = ref(false);
const hasSecondaryFilters = computed(() =>
    selectedGameId.value !== null ||
    selectedPlayerId.value !== null ||
    sortBy.value !== 'newest'
);

/*
|--------------------------------------------------------------------------
| Juegos presentes en el historial
|--------------------------------------------------------------------------
*/

const games = computed(() => {
    const map =
        new Map<number, Game>();

    for (const play of props.plays) {
        map.set(
            play.game.id,
            play.game
        );
    }

    return Array.from(
        map.values()
    ).sort(
        (a, b) =>
            a.name.localeCompare(
                b.name,
                'es'
            )
    );
});

/*
|--------------------------------------------------------------------------
| Jugadores presentes en el historial
|--------------------------------------------------------------------------
*/

const players = computed(() => {
    const map =
        new Map<number, Player>();

    for (const play of props.plays) {
        for (
            const player of play.players
        ) {
            map.set(
                player.id,
                player
            );
        }
    }

    return Array.from(
        map.values()
    ).sort(
        (a, b) =>
            a.name.localeCompare(
                b.name,
                'es'
            )
    );
});

watch([games, players], ([availableGames, availablePlayers]) => {
    if (!availableGames.some((game) => game.id === selectedGameId.value)) {
        selectedGameId.value = null;
    }

    if (!availablePlayers.some((player) => player.id === selectedPlayerId.value)) {
        selectedPlayerId.value = null;
    }
});

/*
|--------------------------------------------------------------------------
| Partidas filtradas
|--------------------------------------------------------------------------
*/

const normalizeHistorySearch = (value: string): string =>
    value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();

const historyFiltersKey = `play-history-filters:${usePage().props.auth.user?.id}`;

onMounted(() => {
    try {
        const saved = JSON.parse(sessionStorage.getItem(historyFiltersKey) ?? 'null');
        if (!saved) return;

        search.value = typeof saved.search === 'string' ? saved.search : '';
        selectedGameId.value = games.value.find((game) => game.id === saved.gameId)?.id ?? null;
        selectedPlayerId.value = players.value.find((player) => player.id === saved.playerId)?.id ?? null;
        sortBy.value = saved.sort === 'oldest' ? 'oldest' : 'newest';
        showAdvancedFilters.value = saved.expanded === true;
    } catch {
        // Si el almacenamiento no está disponible, se usan los filtros iniciales.
    }
});

watch([search, selectedGameId, selectedPlayerId, sortBy, showAdvancedFilters], () => {
    try {
        sessionStorage.setItem(historyFiltersKey, JSON.stringify({
            search: search.value,
            gameId: selectedGameId.value,
            playerId: selectedPlayerId.value,
            sort: sortBy.value,
            expanded: showAdvancedFilters.value,
        }));
    } catch {
        // El buscador sigue funcionando sin almacenamiento.
    }
});

const filteredPlays = computed(() => {
    let result = [...props.plays];

    /*
    |--------------------------------------------------------------------------
    | Buscar
    |--------------------------------------------------------------------------
    |
    | Buscamos por:
    |
    | - juego
    | - expansión
    | - jugador
    | - notas
    |
    */

    const text = normalizeHistorySearch(search.value);

    if (text) {
        result = result.filter((play) =>
            [
                play.game.name,
                play.expansion?.name ?? '',
                play.notes ?? '',
                play.poll?.name ?? '',
                play.players.map((player) => player.name).join(' '),
            ].some((value) => normalizeHistorySearch(value).includes(text))
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Filtro por juego
    |--------------------------------------------------------------------------
    */

    if (
        selectedGameId.value !==
        null
    ) {
        result = result.filter(
            (play) =>
                play.game.id ===
                selectedGameId.value
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Filtro por jugador
    |--------------------------------------------------------------------------
    */

    if (
        selectedPlayerId.value !==
        null
    ) {
        result = result.filter(
            (play) =>
                play.players.some(
                    (player) =>
                        player.id ===
                        selectedPlayerId.value
                )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Orden
    |--------------------------------------------------------------------------
    */

    result.sort(
        (a, b) => {
            const dateA =
                new Date(
                    `${a.played_at}T12:00:00`
                ).getTime();

            const dateB =
                new Date(
                    `${b.played_at}T12:00:00`
                ).getTime();

            if (
                sortBy.value ===
                'newest'
            ) {
                if (
                    dateA !== dateB
                ) {
                    return (
                        dateB -
                        dateA
                    );
                }

                return b.id - a.id;
            }

            if (
                dateA !== dateB
            ) {
                return (
                    dateA -
                    dateB
                );
            }

            return a.id - b.id;
        }
    );

    return result;
});

/*
|--------------------------------------------------------------------------
| Saber si hay filtros activos
|--------------------------------------------------------------------------
*/

const hasActiveFilters =
    computed(() => {
        return (
            search.value.trim() !==
                '' ||
            selectedGameId.value !==
                null ||
            selectedPlayerId.value !==
                null ||
            sortBy.value !==
                'newest'
        );
    });

/*
|--------------------------------------------------------------------------
| Limpiar filtros
|--------------------------------------------------------------------------
*/

const historyHeading = ref<HTMLHeadingElement | null>(null);

const clearFilters = async () => {
    search.value = '';
    selectedGameId.value = null;
    selectedPlayerId.value = null;
    sortBy.value = 'newest';

    await nextTick();
    historyHeading.value?.focus();
};

/*
|--------------------------------------------------------------------------
| Formatear fecha
|--------------------------------------------------------------------------
*/

const formatDate = (
    date: string
): string => {
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

/*
|--------------------------------------------------------------------------
| Eliminar partida
|--------------------------------------------------------------------------
*/

const playToDelete = ref<Play | null>(null);
const deletePlayDialogOpen = ref(false);
const deletingPlay = ref(false);
let deleteTrigger: HTMLButtonElement | null = null;

const restoreDeleteFocus = (event: Event) => {
    event.preventDefault();

    const target = deleteTrigger?.isConnected
        ? deleteTrigger
        : historyHeading.value;

    target?.focus();
    deleteTrigger = null;
};

const deletePlay = (play: Play, event: MouseEvent) => {
    if (deletingPlay.value) return;
    deleteTrigger = event.currentTarget as HTMLButtonElement;

    playToDelete.value = play;
    deletePlayDialogOpen.value = true;
};

const confirmDeletePlay = () => {
    if (!playToDelete.value || deletingPlay.value) {
        return;
    }

    router.delete(`/plays/${playToDelete.value.id}`, {
        preserveScroll: true,

        onStart: () => {
            deletingPlay.value = true;
        },

        onSuccess: () => {
            deletePlayDialogOpen.value = false;
            playToDelete.value = null;
        },

        onFinish: () => {
            deletingPlay.value = false;
        },
    });
};
</script>

<template>
    <Head title="Historial de partidas" />

    <AppLayout>
        <div
            class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6"
        >
            <!-- ================================================== -->
            <!-- CABECERA                                           -->
            <!-- ================================================== -->

            <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <h1 ref="historyHeading" tabindex="-1" class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 sm:text-3xl">
                    Partidas jugadas
                </h1>

                <Link
                    href="/plays/create"
                    class="inline-flex w-full items-center justify-center rounded-xl bg-[#5200FF] px-5 py-3 font-bold text-white transition hover:opacity-90 sm:w-auto"
                >
                    + Registrar partida
                </Link>
            </div>

            <!-- ================================================== -->
            <!-- ESTADÍSTICAS                                       -->
            <!-- ================================================== -->

            <div
                class="mb-6 grid grid-cols-3 gap-2 sm:gap-4"
            >
                <!-- PARTIDAS -->

                <div
                    class="rounded-2xl border border-zinc-200 bg-white p-3 shadow-sm sm:p-5 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <p
                        class="text-xs font-semibold leading-snug text-zinc-500 sm:text-sm dark:text-zinc-400"
                    >
                        Partidas
                    </p>

                    <p
                        class="mt-2 text-2xl font-black text-[#5200FF] sm:text-3xl"
                    >
                        {{
                            stats.total_plays
                        }}
                    </p>
                </div>

                <!-- JUEGOS -->

                <div
                    class="rounded-2xl border border-zinc-200 bg-white p-3 shadow-sm sm:p-5 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <p
                        class="text-xs font-semibold leading-snug text-zinc-500 sm:text-sm dark:text-zinc-400"
                    >
                        Juegos distintos
                    </p>

                    <p
                        class="mt-2 text-2xl font-black text-zinc-900 sm:text-3xl dark:text-zinc-100"
                    >
                        {{
                            stats.unique_games
                        }}
                    </p>
                </div>

                <!-- JUGADORES -->

                <div
                    class="rounded-2xl border border-zinc-200 bg-white p-3 shadow-sm sm:p-5 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <p
                        class="text-xs font-semibold leading-snug text-zinc-500 sm:text-sm dark:text-zinc-400"
                    >
                        Jugadores distintos
                    </p>

                    <p
                        class="mt-2 text-2xl font-black text-zinc-900 sm:text-3xl dark:text-zinc-100"
                    >
                        {{
                            stats.unique_players
                        }}
                    </p>
                </div>
            </div>

            <!-- ================================================== -->
            <!-- FILTROS                                            -->
            <!-- ================================================== -->

            <section
                v-if="plays.length"
                class="mb-6 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm sm:p-5 dark:border-zinc-800 dark:bg-zinc-950"
            >
                <!-- BUSCAR -->

                <div>
                    <label
                        for="history-search"
                        class="text-sm font-bold text-zinc-800 dark:text-zinc-200"
                    >
                        Buscar
                    </label>

                    <input
                        id="history-search"
                        v-model="search"
                        type="search"
                        class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 outline-none placeholder:text-zinc-400 focus:border-[#5200FF] dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:placeholder:text-zinc-500"
                        placeholder="Juego, expansión, jugador, votación o nota..."
                    />
                </div>

                <!-- En móvil, los filtros secundarios no ocupan espacio al entrar. -->
                <button
                    type="button"
                    class="mt-3 inline-flex items-center gap-2 rounded-xl border border-zinc-200 px-3 py-2 text-sm font-semibold text-[#5200FF] sm:hidden dark:border-zinc-700 dark:text-[#CCFF00]"
                    :aria-expanded="showAdvancedFilters"
                    aria-controls="history-advanced-filters"
                    @click="showAdvancedFilters = !showAdvancedFilters"
                >
                    ⚙️ {{ showAdvancedFilters ? 'Ocultar filtros' : 'Más filtros' }}
                    <span v-if="hasSecondaryFilters" class="text-xs">(activos)</span>
                </button>

                <div
                    id="history-advanced-filters"
                    class="mt-4 gap-3 sm:grid sm:grid-cols-3"
                    :class="showAdvancedFilters ? 'grid' : 'hidden'"
                >
                    <!-- JUEGO -->

                    <div>
                        <label
                            for="history-game"
                            class="text-sm font-bold text-zinc-800 dark:text-zinc-200"
                        >
                            Juego
                        </label>

                        <select
                            id="history-game"
                            v-model="
                                selectedGameId
                            "
                            class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                        >
                            <option
                                :value="null"
                            >
                                Todos los juegos
                            </option>

                            <option
                                v-for="game in games"
                                :key="game.id"
                                :value="game.id"
                            >
                                {{ game.name }}
                            </option>
                        </select>
                    </div>

                    <!-- JUGADOR -->

                    <div>
                        <label
                            for="history-player"
                            class="text-sm font-bold text-zinc-800 dark:text-zinc-200"
                        >
                            Jugador
                        </label>

                        <select
                            id="history-player"
                            v-model="
                                selectedPlayerId
                            "
                            class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                        >
                            <option
                                :value="null"
                            >
                                Todos los jugadores
                            </option>

                            <option
                                v-for="player in players"
                                :key="player.id"
                                :value="player.id"
                            >
                                {{ player.name }}
                            </option>
                        </select>
                    </div>

                    <!-- ORDEN -->

                    <div>
                        <label
                            for="history-sort"
                            class="text-sm font-bold text-zinc-800 dark:text-zinc-200"
                        >
                            Orden
                        </label>

                        <select
                            id="history-sort"
                            v-model="sortBy"
                            class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                        >
                            <option value="newest">
                                Más recientes
                            </option>

                            <option value="oldest">
                                Más antiguas
                            </option>
                        </select>
                    </div>
                </div>

                <!-- RESULTADOS -->

                <div
                    class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-800"
                >
                    <p
                        aria-live="polite"
                        class="text-sm text-zinc-500 dark:text-zinc-400"
                    >
                        Mostrando

                        <strong
                            class="text-zinc-900 dark:text-zinc-100"
                        >
                            {{
                                filteredPlays.length
                            }}
                        </strong>

                        de

                        <strong
                            class="text-zinc-900 dark:text-zinc-100"
                        >
                            {{
                                plays.length
                            }}
                        </strong>

                        partidas
                    </p>

                    <button
                        v-if="
                            hasActiveFilters
                        "
                        type="button"
                        class="text-sm font-bold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
                        @click="
                            clearFilters
                        "
                    >
                        Limpiar filtros
                    </button>
                </div>
            </section>

            <!-- ================================================== -->
            <!-- PARTIDAS                                           -->
            <!-- ================================================== -->

            <div
                v-if="
                    filteredPlays.length
                "
                class="space-y-4"
            >
                <article
                    v-for="play in filteredPlays"
                    :key="play.id"
                    class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <!-- La portada y los datos principales comparten una sola fila. -->
                    <div class="flex items-start gap-3 p-3 sm:gap-5 sm:p-4">
                        <div class="flex w-24 shrink-0 items-center justify-center rounded-xl bg-zinc-100 p-2 dark:bg-zinc-900 sm:w-36 sm:p-3">
                            <img
                                v-if="play.game.image_url && !brokenCoverIds.includes(play.game.id)"
                                :src="play.game.image_url"
                                :alt="`Portada de ${play.game.name}`"
                                loading="lazy"
                                class="max-h-36 w-full object-contain sm:max-h-44"
                                @error="markCoverAsBroken(play.game.id)"
                            />
                            <div v-else class="flex min-h-28 items-center justify-center text-center text-xs text-zinc-500 dark:text-zinc-400">
                                <PlayingCardsFanIcon class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-violet-600 dark:text-violet-300" aria-hidden="true" /> Sin portada
                            </div>
                        </div>

                        <div class="min-w-0 flex-1">
                            <Link
                                :href="`/games/${play.game.id}`"
                                class="block break-words text-lg font-bold leading-snug text-zinc-900 transition hover:text-[#5200FF] sm:text-xl dark:text-zinc-100 dark:hover:text-[#CCFF00]"
                            >
                                {{ play.game.name }}
                            </Link>
                            <p class="mt-1 text-sm font-semibold text-[#5200FF] dark:text-[#CCFF00]">
                                <CalendarHeart class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-lime-700 dark:text-[#CCFF00]" :stroke-width="2.2" aria-hidden="true" /> {{ formatDate(play.played_at) }}
                            </p>

                            <Link
                                v-if="play.poll"
                                :href="`/polls/${play.poll.id}?from=history`"
                                class="mt-2 inline-block max-w-full break-words text-sm font-semibold text-[#5200FF] hover:underline dark:text-violet-300"
                            >
                                Votación: {{ play.poll.name }}
                            </Link>

                            <p
                                v-if="play.expansion"
                                class="mt-2 break-words text-sm font-semibold text-violet-700 dark:text-violet-300"
                            >
                                <BuildingComplexPlus class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-[#6D28D9] dark:text-[#CCFF00]" :stroke-width="2" aria-hidden="true" /> {{ play.expansion.name }}
                            </p>

                            <div class="mt-3">
                                <p class="text-xs font-bold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                    Participantes ({{ play.players.length }})
                                </p>
                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    <span
                                        v-for="player in play.players"
                                        :key="player.id"
                                        class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-semibold text-zinc-700 sm:text-sm dark:bg-zinc-800 dark:text-zinc-200"
                                    >
                                        <span :class="['inline-flex items-center gap-1.5', playerNameColor(player.name)]"><MeepleIcon class="h-3.5 w-3.5 shrink-0" />{{ player.name }}</span>
                                    </span>
                                    <span
                                        v-if="play.players.length === 0"
                                        class="text-xs text-zinc-500 dark:text-zinc-400"
                                    >
                                        Sin participantes registrados
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Las notas siguen disponibles sin alargar todas las tarjetas. -->
                    <details
                        v-if="play.notes"
                        class="mx-3 border-t border-zinc-200 sm:mx-4 dark:border-zinc-800"
                    >
                        <summary class="cursor-pointer py-3 text-sm font-semibold text-zinc-600 hover:text-[#5200FF] dark:text-zinc-300 dark:hover:text-[#CCFF00]">
                            📝 Ver notas de la partida
                        </summary>
                        <p class="whitespace-pre-line break-words pb-3 text-sm leading-relaxed text-zinc-700 dark:text-zinc-300">
                            {{ play.notes }}
                        </p>
                    </details>

                    <div class="mx-3 flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-zinc-200 py-3 text-sm sm:mx-4 dark:border-zinc-800">
                        <Link
                            :href="`/games/${play.game.id}`"
                            class="font-bold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
                        >
                            Ver ficha →
                        </Link>
                        <Link
                            :href="`/plays/${play.id}/edit`"
                            class="font-semibold text-zinc-600 hover:underline dark:text-zinc-300"
                        >
                            <span class="inline-flex items-center justify-center gap-1.5"><PencilSparklesIcon class="h-4 w-4 shrink-0 text-violet-500 dark:text-[#CCFF00]" />Editar</span>
                        </Link>
                        <button
                            type="button"
                            class="ml-auto font-semibold text-red-600 hover:underline dark:text-red-400"
                            :aria-label="`Eliminar partida de ${play.game.name} del ${formatDate(play.played_at)}`"
                            @click="deletePlay(play, $event)"
                        >
                            Eliminar
                        </button>
                    </div>
                </article>
            </div>

            <!-- ================================================== -->
            <!-- SIN RESULTADOS                                     -->
            <!-- ================================================== -->

            <div
                v-else-if="
                    plays.length
                "
                class="rounded-2xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700"
            >
                <p
                    class="text-lg font-bold text-zinc-900 dark:text-zinc-100"
                >
                    No hay partidas con estos filtros
                </p>

                <p
                    class="mt-2 text-sm text-zinc-500 dark:text-zinc-400"
                >
                    Prueba con otra búsqueda,
                    juego o jugador.
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
            <!-- HISTORIAL VACÍO                                    -->
            <!-- ================================================== -->

            <div
                v-else
                class="rounded-2xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700"
            >
                <p
                    class="text-lg font-bold text-zinc-900 dark:text-zinc-100"
                >
                    Todavía no hay partidas
                    registradas
                </p>

                <p
                    class="mt-2 text-zinc-500 dark:text-zinc-400"
                >
                    Cuando cierres una votación
                    podrás registrar qué juego
                    jugasteis finalmente.
                </p>

                <Link
                    href="/polls"
                    class="mt-5 inline-flex rounded-xl bg-[#5200FF] px-5 py-3 font-bold text-white transition hover:opacity-90"
                >
                    Ir a votaciones
                </Link>
            </div>
            <ScrollToTopProgress />
        </div>
        <Dialog v-model:open="deletePlayDialogOpen">
            <DialogContent class="sm:max-w-md" @close-auto-focus="restoreDeleteFocus">
                <DialogHeader>
                    <div
                        class="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-red-100 text-2xl dark:bg-red-950"
                        aria-hidden="true"
                    >
                        <PlayingCardsFanIcon class="mr-1 inline-block size-6 shrink-0 align-[-0.14em] text-violet-600 dark:text-violet-300" aria-hidden="true" />
                    </div>

                    <DialogTitle class="text-xl font-black">
                        ¿Eliminar esta partida?
                    </DialogTitle>

                    <DialogDescription class="pt-2 text-sm leading-6">
                        Vas a eliminar la partida de
                        <strong class="font-bold text-foreground">
                            {{ playToDelete?.game.name }}
                        </strong>
                        registrada el
                        <strong class="font-bold text-foreground">
                            {{ playToDelete ? formatDate(playToDelete.played_at) : '' }}
                        </strong>.
                    </DialogDescription>
                </DialogHeader>

                <div
                    class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm leading-6 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300"
                >
                    <p class="font-bold">
                        ⚠️ Esta acción no se puede deshacer.
                    </p>

                    <p class="mt-2">
                        Se eliminará este registro del historial
                        de partidas, pero el juego seguirá
                        disponible en tu colección.
                    </p>
                </div>

                <DialogFooter class="mt-3 gap-2 sm:gap-3">
                    <button
                        type="button"
                        :disabled="deletingPlay"
                        class="rounded-xl border border-zinc-300 px-5 py-3 font-semibold transition hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800 disabled:opacity-50"
                        @click="deletePlayDialogOpen = false"
                    >
                        Cancelar
                    </button>

                    <button
                        type="button"
                        :disabled="deletingPlay"
                        class="rounded-xl bg-red-600 px-5 py-3 font-bold text-white transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
                        @click="confirmDeletePlay"
                    >
                        {{ deletingPlay ? 'Eliminando...' : 'Sí, eliminar partida' }}
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
