<script setup lang="ts">
import { computed, ref } from 'vue';
import {
    Head,
    Link,
    router,
} from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
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

/*
|--------------------------------------------------------------------------
| Partidas filtradas
|--------------------------------------------------------------------------
*/

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

    const text =
        search.value
            .trim()
            .toLowerCase();

    if (text) {
        result = result.filter(
            (play) => {
                const gameName =
                    play.game.name
                        .toLowerCase();

                const expansionName =
                    (
                        play.expansion
                            ?.name ?? ''
                    ).toLowerCase();

                const notes =
                    (
                        play.notes ?? ''
                    ).toLowerCase();

                const playerNames =
                    play.players
                        .map(
                            (player) =>
                                player.name
                                    .toLowerCase()
                        )
                        .join(' ');

                return (
                    gameName.includes(
                        text
                    ) ||
                    expansionName.includes(
                        text
                    ) ||
                    notes.includes(
                        text
                    ) ||
                    playerNames.includes(
                        text
                    )
                );
            }
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

const clearFilters = () => {
    search.value = '';

    selectedGameId.value =
        null;

    selectedPlayerId.value =
        null;

    sortBy.value =
        'newest';
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

const deletePlay = (play: Play) => {
    if (deletingPlay.value) return;

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
            class="mx-auto w-full max-w-6xl px-4 py-6 sm:px-6"
        >
            <!-- ================================================== -->
            <!-- CABECERA                                           -->
            <!-- ================================================== -->

            <div
    class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
>
    <div>
        <p
            class="font-semibold text-[#5200FF]"
        >
            HISTORIAL
        </p>

        <h1
            class="text-3xl font-bold text-zinc-900 dark:text-zinc-100"
        >
            Partidas jugadas
        </h1>

        <p
            class="mt-2 text-zinc-500 dark:text-zinc-400"
        >
            Consulta, corrige o elimina las
            partidas que has registrado.
        </p>
    </div>

    <Link
        href="/plays/create"
        class="inline-flex items-center justify-center rounded-xl bg-[#5200FF] px-5 py-3 font-bold text-white transition hover:opacity-90"
    >
        + Registrar partida
    </Link>
</div>

            <!-- ================================================== -->
            <!-- ESTADÍSTICAS                                       -->
            <!-- ================================================== -->

            <div
                class="mb-8 grid gap-4 sm:grid-cols-3"
            >
                <!-- PARTIDAS -->

                <div
                    class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <p
                        class="text-sm font-semibold text-zinc-500 dark:text-zinc-400"
                    >
                        Partidas registradas
                    </p>

                    <p
                        class="mt-2 text-3xl font-black text-[#5200FF]"
                    >
                        {{
                            stats.total_plays
                        }}
                    </p>
                </div>

                <!-- JUEGOS -->

                <div
                    class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <p
                        class="text-sm font-semibold text-zinc-500 dark:text-zinc-400"
                    >
                        Juegos diferentes
                    </p>

                    <p
                        class="mt-2 text-3xl font-black text-zinc-900 dark:text-zinc-100"
                    >
                        {{
                            stats.unique_games
                        }}
                    </p>
                </div>

                <!-- JUGADORES -->

                <div
                    class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <p
                        class="text-sm font-semibold text-zinc-500 dark:text-zinc-400"
                    >
                        Jugadores diferentes
                    </p>

                    <p
                        class="mt-2 text-3xl font-black text-zinc-900 dark:text-zinc-100"
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
                class="mb-8 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-950"
            >
                <!-- BUSCAR -->

                <div>
                    <label
                        class="text-sm font-bold text-zinc-800 dark:text-zinc-200"
                    >
                        Buscar
                    </label>

                    <input
                        v-model="search"
                        type="search"
                        class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 outline-none placeholder:text-zinc-400 focus:border-[#5200FF] dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:placeholder:text-zinc-500"
                        placeholder="Juego, expansión, jugador o nota..."
                    />
                </div>

                <div
                    class="mt-5 grid gap-4 md:grid-cols-3"
                >
                    <!-- JUEGO -->

                    <div>
                        <label
                            class="text-sm font-bold text-zinc-800 dark:text-zinc-200"
                        >
                            Juego
                        </label>

                        <select
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
                            class="text-sm font-bold text-zinc-800 dark:text-zinc-200"
                        >
                            Jugador
                        </label>

                        <select
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
                            class="text-sm font-bold text-zinc-800 dark:text-zinc-200"
                        >
                            Orden
                        </label>

                        <select
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
                    <div
                        class="flex flex-col sm:flex-row"
                    >
                        <!-- PORTADA -->

                        <div
                            class="shrink-0 bg-zinc-100 dark:bg-zinc-900 sm:w-40"
                        >
                            <img
                                v-if="
                                    play.game.image_url
                                "
                                :src="
                                    play.game.image_url
                                "
                                :alt="
                                    play.game.name
                                "
                                class="h-44 w-full object-cover sm:h-full"
                            />

                            <div
                                v-else
                                class="flex h-44 items-center justify-center text-sm text-zinc-400 dark:text-zinc-500 sm:h-full"
                            >
                                Sin portada
                            </div>
                        </div>

                        <!-- INFORMACIÓN -->

                        <div
                            class="flex-1 p-5"
                        >
                            <!-- CABECERA PARTIDA -->

                            <div
                                class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                            >
                                <div>
                                    <Link
                                        :href="`/games/${play.game.id}`"
                                        class="text-xl font-bold text-zinc-900 transition hover:text-[#5200FF] dark:text-zinc-100 dark:hover:text-[#CCFF00]"
                                    >
                                        {{
                                            play.game.name
                                        }}
                                    </Link>

                                    <p
                                        class="mt-1 text-sm font-semibold text-[#5200FF] dark:text-[#CCFF00]"
                                    >
                                        📅
                                        {{
                                            formatDate(
                                                play.played_at
                                            )
                                        }}
                                    </p>
                                </div>

                                <span
                                    class="self-start rounded-full bg-[#CCFF00] px-3 py-1 text-xs font-bold text-black"
                                >
                                    🎲 Partida
                                    #{{ play.id }}
                                </span>
                            </div>

                            <!-- ================================================== -->
                            <!-- EXPANSIÓN                                          -->
                            <!-- ================================================== -->

                            <div
                                v-if="
                                    play.expansion
                                "
                                class="mt-4 inline-flex rounded-xl bg-violet-50 px-4 py-2 text-sm font-bold text-[#5200FF] dark:bg-violet-950/40 dark:text-violet-300"
                            >
                                🧩
                                {{
                                    play.expansion
                                        .name
                                }}
                            </div>

                            <!-- PARTICIPANTES -->

                            <div class="mt-5">
                                <p
                                    class="text-xs font-bold uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                                >
                                    Participantes
                                </p>

                                <div
                                    class="mt-2 flex flex-wrap gap-2"
                                >
                                    <span
                                        v-for="player in play.players"
                                        :key="player.id"
                                        class="rounded-full bg-zinc-100 px-3 py-1 text-sm font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                                    >
                                        👤
                                        {{ player.name }}
                                    </span>
                                </div>
                            </div>

                            <!-- NOTAS -->

                            <div
                                v-if="play.notes"
                                class="mt-5 rounded-xl bg-zinc-50 p-4 dark:bg-zinc-900"
                            >
                                <p
                                    class="text-xs font-bold uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                                >
                                    Notas
                                </p>

                                <p
                                    class="mt-2 text-sm leading-6 text-zinc-700 dark:text-zinc-300"
                                >
                                    {{ play.notes }}
                                </p>
                            </div>

                            <!-- ACCIONES -->

                            <div
                                class="mt-5 flex flex-wrap items-center gap-5 border-t border-zinc-200 pt-4 dark:border-zinc-800"
                            >
                                <Link
                                    :href="`/games/${play.game.id}`"
                                    class="font-semibold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
                                >
                                    Ver ficha →
                                </Link>

                                <Link
                                    :href="`/plays/${play.id}/edit`"
                                    class="font-semibold text-zinc-600 hover:text-[#5200FF] hover:underline dark:text-zinc-300 dark:hover:text-[#CCFF00]"
                                >
                                    ✏️ Editar
                                </Link>

                                <button
                                    type="button"
                                    class="font-semibold text-red-600 hover:underline dark:text-red-400"
                                    @click="
                                        deletePlay(
                                            play
                                        )
                                    "
                                >
                                    🗑️ Eliminar
                                </button>
                            </div>
                        </div>
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
        </div>
        <Dialog v-model:open="deletePlayDialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <div
                        class="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-red-100 text-2xl dark:bg-red-950"
                        aria-hidden="true"
                    >
                        🎲
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
