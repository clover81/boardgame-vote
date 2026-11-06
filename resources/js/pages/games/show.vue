<script setup lang="ts">
import { computed, ref } from 'vue';

import {
    Head,
    Link,
    router,
    useForm,
    usePage,
} from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import BggExpansionsImporter from '@/components/BggExpansionsImporter.vue';
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
| Expansión
|--------------------------------------------------------------------------
*/

interface Expansion {
    id: number;
    bgg_id: number | null;
    name: string;
    image_url: string | null;
    bgg_url: string | null;
    youtube_url: string | null;
    notes: string | null;
    is_active: boolean;
}

/*
|--------------------------------------------------------------------------
| Juego
|--------------------------------------------------------------------------
*/

interface Game {
    id: number;
    bgg_id: number | null;
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
    full_description: string | null;
    additional_information: string | null;

    bgg_url: string | null;
    youtube_url: string | null;

    ideal_player_counts: number[];

    expansions: Expansion[];
}

/*
|--------------------------------------------------------------------------
| Historial
|--------------------------------------------------------------------------
*/

interface HistoryPlayer {
    id: number;
    name: string;
}

interface HistoryExpansion {
    id: number;
    name: string;
}

interface HistoryPlay {
    id: number;
    played_at: string;
    notes: string | null;

    expansion:
        HistoryExpansion | null;

    players: HistoryPlayer[];
}

interface History {
    total_plays: number;
    last_played_at: string | null;
    plays: HistoryPlay[];
}
interface Collection {
    id: number;
    name: string;
    is_default: boolean;
}

const props = defineProps<{
    game: Game;

    history: History;

    collections: Collection[];
}>();

const page = usePage();

const backToGames = computed(() => {
    const query = page.url.split('?')[1] ?? '';
    const params = new URLSearchParams(query);
    const back = params.get('back');

    // Solo permitir regresar al catálogo privado.
    if (back === '/games' || back?.startsWith('/games?')) {
        return back;
    }

    return '/games';
});

const removingCollectionId = ref<number | null>(null);
const coverHasError = ref(false);

const brokenExpansionIds = ref<number[]>([]);

const markExpansionAsBroken = (expansionId: number) => {
    if (!brokenExpansionIds.value.includes(expansionId)) {
        brokenExpansionIds.value.push(expansionId);
    }
};

const detachCollection = (collection: Collection) => {
    if (removingCollectionId.value !== null) {
        return;
    }

    removingCollectionId.value = collection.id;
    router.delete(`/games/${props.game.id}/collections/${collection.id}`, {
        preserveScroll: true,
        onFinish: () => {
            removingCollectionId.value = null;
        },
    });
};

/*
|--------------------------------------------------------------------------
| Nueva expansión
|--------------------------------------------------------------------------
*/
const manualExpansionOpen = ref(false);

const expansionForm = useForm({
    name: '',
    image_url: '',
    bgg_url: '',
    youtube_url: '',
    notes: '',
});

const submitExpansion = () => {
    expansionForm.post(
        `/games/${props.game.id}/expansions`,
        {
            preserveScroll: true,

            onSuccess: () => {
                expansionForm.reset();
                manualExpansionOpen.value = false;
            },
        }
    );
};

/*
|--------------------------------------------------------------------------
| Editar expansión
|--------------------------------------------------------------------------
*/

const editingExpansionId =
    ref<number | null>(null);

const editExpansionForm = useForm({
    name: '',
    image_url: '',
    bgg_url: '',
    youtube_url: '',
    notes: '',
});

const startEditingExpansion = (
    expansion: Expansion
) => {
    editingExpansionId.value =
        expansion.id;

    editExpansionForm.name =
        expansion.name;

    editExpansionForm.image_url =
        expansion.image_url ?? '';

    editExpansionForm.bgg_url =
        expansion.bgg_url ?? '';

    editExpansionForm.youtube_url =
        expansion.youtube_url ?? '';

    editExpansionForm.notes =
        expansion.notes ?? '';
};

const cancelEditingExpansion = () => {
    editingExpansionId.value =
        null;

    editExpansionForm.reset();
    editExpansionForm.clearErrors();
};

const updateExpansion = (
    expansion: Expansion
) => {
    editExpansionForm.patch(
        `/games/${props.game.id}/expansions/${expansion.id}`,
        {
            preserveScroll: true,

            onSuccess: () => {
                cancelEditingExpansion();
            },
        }
    );
};

/*
|--------------------------------------------------------------------------
| Eliminar expansión
|--------------------------------------------------------------------------
*/

const expansionToDelete = ref<Expansion | null>(null);
const deleteExpansionDialogOpen = ref(false);
const deletingExpansion = ref(false);

const deleteExpansion = (expansion: Expansion) => {
    if (deletingExpansion.value) return;

    expansionToDelete.value = expansion;
    deleteExpansionDialogOpen.value = true;
};

const confirmDeleteExpansion = () => {
    if (!expansionToDelete.value || deletingExpansion.value) {
        return;
    }

    router.delete(
        `/games/${props.game.id}/expansions/${expansionToDelete.value.id}`,
        {
            preserveScroll: true,

            onStart: () => {
                deletingExpansion.value = true;
            },

            onSuccess: () => {
                deleteExpansionDialogOpen.value = false;
                expansionToDelete.value = null;
            },

            onFinish: () => {
                deletingExpansion.value = false;
            },
        },
    );
};

/*
|--------------------------------------------------------------------------
| Fecha
|--------------------------------------------------------------------------
*/

const formatDate = (
    date: string | null
): string => {
    if (!date) {
        return 'Sin partidas';
    }

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
| Tipo de juego
|--------------------------------------------------------------------------
*/

const formatGameMode = (
    modes: string[],
    maxPlayers: number
): string => {
    const labels: Record<string, string> = {
        competitive: 'Competitivo',
        cooperative: 'Cooperativo',
        semi_cooperative: 'Semicooperativo',
    };

    if (modes.length === 0) {
        return maxPlayers === 1
            ? 'Solitario'
            : 'Sin clasificar';
    }

    return modes
        .map(mode => labels[mode] ?? mode)
        .join(' · ');
};
</script>

<template>
    <Head :title="game.name" />

    <AppLayout>
        <div
            class="mx-auto w-full max-w-6xl px-4 py-6 sm:px-6"
        >
            <!-- VOLVER -->

            <Link
                :href="backToGames"
                class="text-sm font-semibold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
            >
                ← Mi colección
            </Link>

            <!-- CABECERA -->

            <div
                class="mt-6 flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between"
            >
                <div>
                    <p
                        class="font-bold uppercase tracking-wide text-[#5200FF]"
                    >
                        JUEGO
                    </p>

                    <h1
                        class="mt-1 text-3xl font-black text-zinc-900 dark:text-zinc-100 sm:text-4xl"
                    >
                        {{ game.name }}
                    </h1>

                    <div
                        v-if="collections.length"
                        class="mt-3 flex flex-wrap gap-2"
                    >
                        <div
                            v-for="collection in collections"
                            :key="collection.id"
                            class="rounded-xl bg-violet-100 px-3 py-2 text-xs text-[#5200FF] dark:bg-violet-950/40 dark:text-violet-300"
                        >
                            <p class="font-bold">{{ collection.name }}</p>
                            <button
                                type="button"
                                class="mt-1 underline underline-offset-2 disabled:cursor-wait disabled:opacity-50"
                                :aria-label="`Quitar ${game.name} de ${collection.name}`"
                                :disabled="removingCollectionId !== null"
                                @click="detachCollection(collection)"
                            >
                                {{ removingCollectionId === collection.id ? 'Quitando…' : 'Quitar de esta colección' }}
                            </button>
                        </div>
                    </div>
                </div>

                <div
                    class="flex flex-wrap gap-2"
                >
                    <Link
                        :href="`/plays/create?game_id=${game.id}`"
                        class="rounded-xl bg-[#5200FF] px-5 py-3 font-bold text-white transition hover:opacity-90"
                    >
                        🎲 Registrar partida
                    </Link>

                    <Link
                        :href="`/games/${game.id}/edit`"
                        class="rounded-xl border border-zinc-300 px-5 py-3 font-bold text-zinc-700 transition hover:border-[#5200FF] hover:text-[#5200FF] dark:border-zinc-700 dark:text-zinc-200"
                    >
                        ✏️ Editar
                    </Link>
                </div>
            </div>

            <!-- ================================================== -->
            <!-- INFORMACIÓN PRINCIPAL                              -->
            <!-- ================================================== -->

            <div
                class="mt-8 grid items-start gap-8 md:grid-cols-2"
            >
                <!-- PORTADA -->

                <div
                    class="mx-auto w-full max-w-md overflow-hidden rounded-3xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <img
                        v-if="game.image_url && !coverHasError"
                        :src="game.image_url"
                        :alt="game.name"
                        class="aspect-[3/4] w-full object-contain p-4 bg-zinc-100 dark:bg-zinc-900"
                        @error="coverHasError = true"
                    />

                    <div
                        v-else
                        class="flex aspect-[3/4] items-center justify-center bg-zinc-100 text-5xl dark:bg-zinc-900"
                    >
                        🎲
                    </div>
                </div>

                <!-- CARACTERÍSTICAS -->

                <div
                    class="rounded-2xl border bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <h2
                        class="text-xl font-bold"
                    >
                        Características
                    </h2>

                    <div class="mt-5 grid grid-cols-2 gap-3">
                        <div class="rounded-2xl bg-zinc-50 p-4 dark:bg-zinc-900">
                            <p class="text-xs font-bold uppercase text-zinc-400">
                                Jugadores
                            </p>

                            <p class="mt-1 text-lg font-black text-zinc-900 dark:text-zinc-100">
                                👥 {{ game.min_players }}–{{ game.max_players }}
                            </p>
                        </div>

                        <div class="rounded-2xl bg-zinc-50 p-4 dark:bg-zinc-900">
                            <p class="text-xs font-bold uppercase text-zinc-400">
                                Duración
                            </p>

                            <p class="mt-1 text-lg font-black text-zinc-900 dark:text-zinc-100">
                                ⏱
                                <template v-if="game.min_playtime !== null || game.max_playtime !== null">
                                    {{ game.min_playtime ?? game.max_playtime }}<template
                                        v-if="game.min_playtime !== null && game.max_playtime !== null && game.max_playtime !== game.min_playtime"
                                    >–{{ game.max_playtime }}</template>
                                    min
                                </template>
                                <template v-else>—</template>
                            </p>
                        </div>

                        <div class="rounded-2xl bg-zinc-50 p-4 dark:bg-zinc-900">
                            <p class="text-xs font-bold uppercase text-zinc-400">
                                Complejidad
                            </p>

                            <p class="mt-1 text-lg font-black text-zinc-900 dark:text-zinc-100">
                                🧠 {{ game.complexity ?? '—' }}
                            </p>
                        </div>

                        <div class="rounded-2xl bg-zinc-50 p-4 dark:bg-zinc-900">
                            <p class="text-xs font-bold uppercase text-zinc-400">
                                Tipo
                            </p>

                            <p class="mt-1 text-lg font-black text-zinc-900 dark:text-zinc-100">
                                🎲 {{ formatGameMode(game.game_modes, game.max_players) }}
                            </p>
                        </div>
                    </div>

                    <!-- IDEAL -->

                    <div
                        class="mt-6 border-t pt-5 dark:border-zinc-800"
                    >
                        <h3 class="font-bold">
                            Número ideal de jugadores
                        </h3>

                        <div
                            v-if="
                                game
                                    .ideal_player_counts
                                    .length
                            "
                            class="mt-3 flex flex-wrap gap-2"
                        >
                            <span
                                v-for="number in game.ideal_player_counts"
                                :key="number"
                                class="rounded-full bg-[#CCFF00] px-4 py-2 text-sm font-bold text-black"
                            >
                                Ideal a {{ number }}
                            </span>
                        </div>

                        <p
                            v-else
                            class="mt-3 text-sm text-zinc-500"
                        >
                            No se han indicado
                            jugadores ideales.
                        </p>
                    </div>

                    <div
                        v-if="game.bgg_url || game.youtube_url"
                        class="mt-6 flex flex-wrap gap-4 border-t border-zinc-200 pt-5 dark:border-zinc-800"
                    >
                        <a
                            v-if="game.bgg_url"
                            :href="game.bgg_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-sm font-bold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
                        >
                            BoardGameGeek ↗
                        </a>

                        <a
                            v-if="game.youtube_url"
                            :href="game.youtube_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-sm font-bold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
                        >
                            Tutorial YouTube ↗
                        </a>
                    </div>
                </div>
            </div>

            <!-- DESCRIPCIÓN DEL JUEGO -->

            <section
                v-if="game.full_description || game.short_description"
                class="mt-10 rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-950 sm:p-8"
            >
                <div class="mb-5 flex items-center gap-3">
                    <span class="text-2xl">📖</span>

                    <h2
                        class="text-xl font-black text-zinc-900 dark:text-zinc-100"
                    >
                        Sobre el juego
                    </h2>
                </div>

                <p
                    class="max-w-4xl whitespace-pre-line text-base leading-8 text-zinc-600 dark:text-zinc-300"
                >
                    {{ game.full_description || game.short_description }}
                </p>
            </section>

            <!-- INFORMACIÓN ADICIONAL -->

            <section
                v-if="
                    game.additional_information
                "
                class="mt-10"
            >
                <h2
                    class="text-xl font-bold"
                >
                    Información adicional
                </h2>

                <div
                    class="mt-4 rounded-2xl border bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <p
                        class="whitespace-pre-line leading-7 text-zinc-600 dark:text-zinc-300"
                    >
                        {{
                            game.additional_information
                        }}
                    </p>
                </div>
            </section>

            <!-- ================================================== -->
            <!-- EXPANSIONES                                        -->
            <!-- ================================================== -->

            <section class="mt-12">
                <div>
                    <p
                        class="font-semibold text-[#5200FF]"
                    >
                        EXPANSIONES
                    </p>

                    <h2
                        class="mt-1 text-2xl font-bold"
                    >
                        🧩 Expansiones del juego
                    </h2>

                    <p
                        class="mt-2 text-sm text-zinc-500"
                    >
                        Las expansiones pertenecen al
                        juego base. En las votaciones
                        seguirá apareciendo solamente
                        el juego base.
                    </p>
                </div>

                <!-- Importación de BGG disponible sin entrar en Editar juego -->
                <BggExpansionsImporter
                    class="mt-6"
                    :game-id="game.id"
                    :bgg-id="game.bgg_id"
                />

                <!-- LISTADO -->

                <div
                    v-if="
                        game.expansions.length
                    "
                    class="mt-6 grid gap-4 md:grid-cols-2"
                >
                    <article
                        v-for="expansion in game.expansions"
                        :key="expansion.id"
                        class="overflow-hidden rounded-2xl border bg-white dark:border-zinc-800 dark:bg-zinc-950"
                    >
                        <template
                            v-if="
                                editingExpansionId !==
                                expansion.id
                            "
                        >
                            <!-- IMAGEN -->
                            <div
                                v-if="expansion.image_url && !brokenExpansionIds.includes(expansion.id)"
                                class="flex aspect-[4/3] items-center justify-center bg-zinc-100 p-3 dark:bg-zinc-900"
                            >
                                <img
                                    :src="expansion.image_url"
                                    :alt="expansion.name"
                                    class="h-full w-full object-contain"
                                    @error="markExpansionAsBroken(expansion.id)"
                                />
                            </div>

                            <!-- PORTADA ALTERNATIVA -->
                            <div
                                v-else
                                class="flex aspect-[4/3] flex-col items-center justify-center gap-2 bg-zinc-100 text-zinc-400 dark:bg-zinc-900"
                            >
                                <span class="text-4xl">🧩</span>
                                <span class="text-sm font-medium">
                                    Sin portada disponible
                                </span>
                            </div>

                            <div class="p-5">
                                <h3
                                    class="text-lg font-bold"
                                >
                                    🧩
                                    {{
                                        expansion.name
                                    }}
                                </h3>

                                <p
                                    v-if="
                                        expansion.notes
                                    "
                                    class="mt-3 whitespace-pre-line text-sm leading-6 text-zinc-600 dark:text-zinc-300"
                                >
                                    {{
                                        expansion.notes
                                    }}
                                </p>

                                <!-- ENLACES -->

                                <div
                                    v-if="
                                        expansion.bgg_url ||
                                        expansion.youtube_url
                                    "
                                    class="mt-4 flex flex-wrap gap-4"
                                >
                                    <a
                                        v-if="
                                            expansion.bgg_url
                                        "
                                        :href="
                                            expansion.bgg_url
                                        "
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="text-sm font-semibold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
                                    >
                                        BGG ↗
                                    </a>

                                    <a
                                        v-if="
                                            expansion.youtube_url
                                        "
                                        :href="
                                            expansion.youtube_url
                                        "
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="text-sm font-semibold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
                                    >
                                        Tutorial ↗
                                    </a>
                                </div>

                                <!-- ACCIONES -->

                                <div
                                    class="mt-5 flex gap-5 border-t pt-4 dark:border-zinc-800"
                                >
                                    <button
                                        type="button"
                                        class="text-sm font-semibold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
                                        @click="
                                            startEditingExpansion(
                                                expansion
                                            )
                                        "
                                    >
                                        ✏️ Editar
                                    </button>

                                    <button
                                        type="button"
                                        class="text-sm font-semibold text-red-600 hover:underline dark:text-red-400"
                                        @click="
                                            deleteExpansion(
                                                expansion
                                            )
                                        "
                                    >
                                        🗑️ Eliminar
                                    </button>
                                </div>
                            </div>
                        </template>

                        <!-- ====================================== -->
                        <!-- EDITAR EXPANSIÓN                       -->
                        <!-- ====================================== -->

                        <form
                            v-else
                            class="p-5"
                            @submit.prevent="
                                updateExpansion(
                                    expansion
                                )
                            "
                        >
                            <h3
                                class="font-bold"
                            >
                                Editar expansión
                            </h3>

                            <div
                                class="mt-4 space-y-4"
                            >
                                <input
                                    v-model="
                                        editExpansionForm.name
                                    "
                                    type="text"
                                    class="w-full rounded-xl border bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900"
                                    placeholder="Nombre"
                                />

                                <input
                                    v-model="
                                        editExpansionForm.image_url
                                    "
                                    type="url"
                                    class="w-full rounded-xl border bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900"
                                    placeholder="URL de imagen"
                                />

                                <input
                                    v-model="
                                        editExpansionForm.bgg_url
                                    "
                                    type="url"
                                    class="w-full rounded-xl border bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900"
                                    placeholder="URL de BoardGameGeek"
                                />

                                <input
                                    v-model="
                                        editExpansionForm.youtube_url
                                    "
                                    type="url"
                                    class="w-full rounded-xl border bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900"
                                    placeholder="URL de tutorial"
                                />

                                <textarea
                                    v-model="
                                        editExpansionForm.notes
                                    "
                                    rows="3"
                                    class="w-full rounded-xl border bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900"
                                    placeholder="Notas..."
                                />
                            </div>

                            <div
                                class="mt-5 flex gap-3"
                            >
                                <button
                                    type="submit"
                                    :disabled="
                                        editExpansionForm.processing
                                    "
                                    class="rounded-xl bg-[#5200FF] px-4 py-2 font-bold text-white disabled:opacity-50"
                                >
                                    Guardar
                                </button>

                                <button
                                    type="button"
                                    class="rounded-xl border px-4 py-2 font-semibold"
                                    @click="
                                        cancelEditingExpansion
                                    "
                                >
                                    Cancelar
                                </button>
                            </div>
                        </form>
                    </article>
                </div>

                <div
                    v-else
                    class="mt-6 rounded-2xl border border-dashed p-6 text-center text-zinc-500"
                >
                    Este juego todavía no tiene
                    expansiones registradas.
                </div>

                <!-- ============================================== -->
                <!-- AÑADIR EXPANSIÓN                              -->
                <!-- ============================================== -->

                <div class="mt-6">
                    <button
                        type="button"
                        :aria-expanded="manualExpansionOpen"
                        aria-controls="manual-expansion-form"
                        class="rounded-xl border border-zinc-300 px-5 py-3 text-sm font-bold text-zinc-800 transition hover:border-[#5200FF] hover:text-[#5200FF] dark:border-zinc-700 dark:text-zinc-200 dark:hover:text-[#CCFF00]"
                        @click="manualExpansionOpen = !manualExpansionOpen"
                    >
                        {{ manualExpansionOpen ? '− Ocultar formulario manual' : '+ Añadir manualmente' }}
                    </button>
                </div>

                <form
                    v-if="manualExpansionOpen"
                    id="manual-expansion-form"
                    class="mt-4 rounded-2xl border bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                    @submit.prevent="submitExpansion"
                >
                    <h3
                        class="text-lg font-bold"
                    >
                        + Añadir expansión
                    </h3>

                    <div
                        class="mt-5 grid gap-4 md:grid-cols-2"
                    >
                        <div>
                            <label
                                class="text-sm font-semibold"
                            >
                                Nombre *
                            </label>

                            <input
                                v-model="
                                    expansionForm.name
                                "
                                type="text"
                                class="mt-2 w-full rounded-xl border bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900"
                                placeholder="Ej: Matcha"
                            />

                            <p
                                v-if="
                                    expansionForm
                                        .errors.name
                                "
                                class="mt-2 text-sm text-red-500"
                            >
                                {{
                                    expansionForm
                                        .errors.name
                                }}
                            </p>
                        </div>

                        <div>
                            <label
                                class="text-sm font-semibold"
                            >
                                Imagen
                            </label>

                            <input
                                v-model="
                                    expansionForm.image_url
                                "
                                type="url"
                                class="mt-2 w-full rounded-xl border bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900"
                                placeholder="https://..."
                            />
                        </div>

                        <div>
                            <label
                                class="text-sm font-semibold"
                            >
                                BoardGameGeek
                            </label>

                            <input
                                v-model="
                                    expansionForm.bgg_url
                                "
                                type="url"
                                class="mt-2 w-full rounded-xl border bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900"
                                placeholder="https://boardgamegeek.com/..."
                            />
                        </div>

                        <div>
                            <label
                                class="text-sm font-semibold"
                            >
                                Tutorial YouTube
                            </label>

                            <input
                                v-model="
                                    expansionForm.youtube_url
                                "
                                type="url"
                                class="mt-2 w-full rounded-xl border bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900"
                                placeholder="https://youtube.com/..."
                            />
                        </div>
                    </div>

                    <div class="mt-4">
                        <label
                            class="text-sm font-semibold"
                        >
                            Notas
                        </label>

                        <textarea
                            v-model="
                                expansionForm.notes
                            "
                            rows="3"
                            class="mt-2 w-full rounded-xl border bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900"
                            placeholder="Información sobre la expansión..."
                        />
                    </div>

                    <button
                        type="submit"
                        :disabled="
                            expansionForm.processing
                        "
                        class="mt-5 rounded-xl bg-[#5200FF] px-5 py-3 font-bold text-white transition hover:opacity-90 disabled:opacity-50"
                    >
                        {{
                            expansionForm.processing
                                ? 'Guardando...'
                                : 'Añadir expansión'
                        }}
                    </button>
                </form>
            </section>

            <!-- ================================================== -->
            <!-- HISTORIAL                                         -->
            <!-- ================================================== -->

            <section class="mt-12">
                <div class="mb-6">
                    <p
                        class="font-semibold text-[#5200FF]"
                    >
                        MI HISTORIAL
                    </p>

                    <h2
                        class="mt-1 text-2xl font-bold"
                    >
                        🎲 Partidas jugadas
                    </h2>

                    <p
                        class="mt-2 text-sm text-zinc-500"
                    >
                        Consulta las partidas
                        registradas de este juego.
                    </p>
                </div>

                <!-- SIN PARTIDAS -->

                <div
                    v-if="
                        history.total_plays ===
                        0
                    "
                    class="rounded-2xl border border-dashed p-8 text-center"
                >
                    <p
                        class="text-lg font-semibold"
                    >
                        Todavía no has registrado
                        ninguna partida.
                    </p>
                </div>

                <template v-else>
                    <!-- RESUMEN -->

                    <div
                        class="grid gap-4 sm:grid-cols-2"
                    >
                        <div
                            class="rounded-2xl border bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-950"
                        >
                            <p
                                class="text-sm font-semibold text-zinc-500"
                            >
                                Partidas registradas
                            </p>

                            <p
                                class="mt-3 text-4xl font-black text-[#5200FF]"
                            >
                                {{
                                    history.total_plays
                                }}
                            </p>
                        </div>

                        <div
                            class="rounded-2xl border bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-950"
                        >
                            <p
                                class="text-sm font-semibold text-zinc-500"
                            >
                                Última partida
                            </p>

                            <p
                                class="mt-3 text-xl font-bold"
                            >
                                {{
                                    formatDate(
                                        history.last_played_at
                                    )
                                }}
                            </p>
                        </div>
                    </div>

                    <!-- PARTIDAS -->

                    <div class="mt-8">
                        <h3
                            class="text-xl font-bold"
                        >
                            Historial de partidas
                        </h3>

                        <div
                            class="mt-4 space-y-4"
                        >
                            <article
                                v-for="play in history.plays"
                                :key="play.id"
                                class="rounded-2xl border bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-950"
                            >
                                <div
                                    class="flex flex-wrap items-start justify-between gap-3"
                                >
                                    <div>
                                        <h4 class="text-lg font-bold">
                                            📅
                                            {{ formatDate(play.played_at) }}
                                        </h4>

                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <span
                                                v-if="play.expansion"
                                                class="rounded-full bg-[#5200FF]/10 px-3 py-1 text-sm font-bold text-[#5200FF] dark:bg-[#CCFF00]/10 dark:text-[#CCFF00]"
                                            >
                                                🧩
                                                {{ play.expansion.name }}
                                            </span>

                                            <span
                                                v-else
                                                class="rounded-full bg-zinc-100 px-3 py-1 text-sm text-zinc-500 dark:bg-zinc-900"
                                            >
                                                Sin expansión
                                            </span>
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <span
                                            class="rounded-full bg-[#CCFF00] px-3 py-1 text-xs font-bold text-black"
                                        >
                                            {{ play.players.length }}
                                            {{
                                                play.players.length === 1
                                                    ? 'jugador'
                                                    : 'jugadores'
                                            }}
                                        </span>

                                        <Link
                                            :href="`/plays/${play.id}/edit`"
                                            class="rounded-lg border border-zinc-300 px-3 py-1.5 text-xs font-bold transition hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-900"
                                        >
                                            Editar
                                        </Link>
                                    </div>
                                </div>

                                <!-- PARTICIPANTES -->

                                <div class="mt-5">
                                    <p
                                        class="text-sm font-semibold text-zinc-500"
                                    >
                                        Participantes
                                    </p>

                                    <div
                                        class="mt-3 flex flex-wrap gap-2"
                                    >
                                        <span
                                            v-for="player in play.players"
                                            :key="player.id"
                                            class="rounded-xl border px-3 py-2 text-sm font-medium dark:border-zinc-700"
                                        >
                                            👤
                                            {{
                                                player.name
                                            }}
                                        </span>
                                    </div>
                                </div>

                                <!-- NOTAS -->

                                <div
                                    v-if="
                                        play.notes
                                    "
                                    class="mt-5 rounded-xl bg-zinc-100 p-4 dark:bg-zinc-900"
                                >
                                    <p
                                        class="text-sm font-bold"
                                    >
                                        📝 Notas
                                    </p>

                                    <p
                                        class="mt-2 whitespace-pre-line text-sm leading-6 text-zinc-600 dark:text-zinc-300"
                                    >
                                        {{
                                            play.notes
                                        }}
                                    </p>
                                </div>
                            </article>
                        </div>
                    </div>
                </template>
            </section>
            <ScrollToTopProgress />
        </div>
        <Dialog v-model:open="deleteExpansionDialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <div
                        class="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-red-100 text-2xl dark:bg-red-950"
                        aria-hidden="true"
                    >
                        🧩
                    </div>

                    <DialogTitle class="text-xl font-black">
                        ¿Eliminar esta expansión?
                    </DialogTitle>

                    <DialogDescription class="pt-2 text-sm leading-6">
                        Vas a eliminar
                        <strong class="font-bold text-foreground">
                            {{ expansionToDelete?.name }}
                        </strong>.
                    </DialogDescription>
                </DialogHeader>

                <div
                    class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
                >
                    <p class="font-bold">
                        ⚠️ Esta acción no se puede deshacer.
                    </p>

                    <p class="mt-2">
                        La expansión se eliminará de la ficha del
                        juego, pero las partidas registradas
                        se conservarán.
                    </p>
                </div>

                <DialogFooter class="mt-3 gap-2 sm:gap-3">
                    <button
                        type="button"
                        :disabled="deletingExpansion"
                        class="rounded-xl border border-zinc-300 px-5 py-3 font-semibold transition hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800 disabled:opacity-50"
                        @click="deleteExpansionDialogOpen = false"
                    >
                        Cancelar
                    </button>

                    <button
                        type="button"
                        :disabled="deletingExpansion"
                        class="rounded-xl bg-red-600 px-5 py-3 font-bold text-white transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
                        @click="confirmDeleteExpansion"
                    >
                        {{ deletingExpansion ? 'Eliminando...' : 'Sí, eliminar expansión' }}
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
