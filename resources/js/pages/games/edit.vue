<script setup lang="ts">
import PencilSparklesIcon from '@/components/icons/PencilSparklesIcon.vue';
import { BuildingComplexPlus, Swords } from '@lucide/vue';
import { Link as ChainIcon } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import GameCollectionsField from '@/components/GameCollectionsField.vue';
import BggExpansionsImporter from '@/components/BggExpansionsImporter.vue';
import InputError from '@/components/InputError.vue';
import ScrollToTopProgress from '@/components/ScrollToTopProgress.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/AppLayout.vue';

interface Collection {
    id: number;
    name: string;
    is_default: boolean;
}

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

interface BggSearchResult {
    id: number;
    name: string;
    year_published: number | null;
}

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
    game_modes: string[] | null;

    short_description: string | null;
    additional_information: string | null;

    bgg_url: string | null;
    youtube_url: string | null;

    ideal_player_counts: number[];
    expansions: Expansion[];
}

const props = defineProps<{
    game: Game;
    collections: Collection[];
    game_collection_ids: number[];
}>();

const form = useForm({
    name: props.game.name,

    image_url: props.game.image_url ?? '',

    min_players: props.game.min_players,
    max_players: props.game.max_players,

    min_playtime: props.game.min_playtime,
    max_playtime: props.game.max_playtime,

    complexity: props.game.complexity
        ? Number(props.game.complexity)
        : null,

    game_modes: [
        ...(props.game.game_modes ??
            (props.game.game_mode
                ? [props.game.game_mode]
                : [])),
    ] as string[],

    short_description: props.game.short_description ?? '',
    additional_information:
        props.game.additional_information ?? '',

    bgg_url: props.game.bgg_url ?? '',
    youtube_url: props.game.youtube_url ?? '',

    ideal_player_counts: [
        ...props.game.ideal_player_counts,
    ],

    collection_ids: [...props.game_collection_ids] as number[],
});

const bggQuery = ref(props.game.name);
const bggResults = ref<BggSearchResult[]>([]);
const bggSearching = ref(false);
const bggLinkingId = ref<number | null>(null);
const bggError = ref('');
const bggSearchCompleted = ref(false);

const searchBgg = async () => {
    const query = bggQuery.value.trim();

    if (!query || bggSearching.value || bggLinkingId.value !== null) {
        return;
    }

    bggSearching.value = true;
    bggError.value = '';
    bggResults.value = [];
    bggSearchCompleted.value = false;

    try {
        const params = new URLSearchParams({ q: query });
        const response = await fetch(
            `/games/${props.game.id}/bgg-link/search?${params.toString()}`,
            { headers: { Accept: 'application/json' } }
        );
        const data = await response.json();

        if (!response.ok) {
            bggError.value = data?.message ?? 'No se ha podido buscar en BGG';
            return;
        }

        bggResults.value = data.results ?? [];
        bggSearchCompleted.value = true;
    } catch {
        bggError.value = 'No se ha podido conectar con BGG.';
    } finally {
        bggSearching.value = false;
    }
};

const linkWithBgg = (result: BggSearchResult) => {
    if (
        bggLinkingId.value !== null || form.processing || deleting.value ||
        removingCollectionId.value !== null
    ) {
        return;
    }

    bggLinkingId.value = result.id;
    bggError.value = '';

    router.post(`/games/${props.game.id}/bgg-link/${result.id}`, {}, {
        preserveScroll: true,
        onSuccess: () => {
            bggResults.value = [];
            bggSearchCompleted.value = false;
            form.bgg_url = props.game.bgg_url ?? '';
            if (!form.image_url) {
                form.image_url = props.game.image_url ?? '';
            }
            form.min_playtime ??= props.game.min_playtime;
            form.max_playtime ??= props.game.max_playtime;
            form.complexity ??= props.game.complexity !== null ? Number(props.game.complexity) : null;
        },
        onError: (errors) => {
            bggError.value = String(errors.bgg_link ?? 'No se ha podido vincular el juego.');
        },
        onFinish: () => {
            bggLinkingId.value = null;
        },
    });
};

const editingExpansionId = ref<number | null>(null);
const expansionForm = useForm({
    name: '',
    image_url: '',
    bgg_url: '',
    youtube_url: '',
    notes: '',
    is_active: true,
});

const editExpansion = (expansion: Expansion) => {
    editingExpansionId.value = expansion.id;
    expansionForm.clearErrors();
    expansionForm.name = expansion.name;
    expansionForm.image_url = expansion.image_url ?? '';
    expansionForm.bgg_url = expansion.bgg_url ?? '';
    expansionForm.youtube_url = expansion.youtube_url ?? '';
    expansionForm.notes = expansion.notes ?? '';
    expansionForm.is_active = expansion.is_active;
};

const cancelExpansionEdit = () => {
    editingExpansionId.value = null;
    expansionForm.reset();
    expansionForm.clearErrors();
};

const saveExpansion = (expansion: Expansion) => {
    expansionForm.patch(
        `/games/${props.game.id}/expansions/${expansion.id}`,
        {
            preserveScroll: true,
            onSuccess: () => {
                editingExpansionId.value = null;
            },
        }
    );
};

const expansionToDelete = ref<Expansion | null>(null);
const expansionDeleteDialogOpen = ref(false);
const deletingExpansion = ref(false);

const deleteExpansion = (expansion: Expansion) => {
    if (deletingExpansion.value) return;

    expansionToDelete.value = expansion;
    expansionDeleteDialogOpen.value = true;
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
                expansionDeleteDialogOpen.value = false;
                expansionToDelete.value = null;
            },
            onFinish: () => {
                deletingExpansion.value = false;
            },
        },
    );
};

const playerNumbers = [1, 2, 3, 4, 5, 6, 7, 8];

const submit = () => {
    if (deleting.value || removingCollectionId.value !== null || bggLinkingId.value !== null) {
        return;
    }

    form.put(`/games/${props.game.id}?back=${encodeURIComponent(backToGames.value)}`);
};

const page = usePage();
const backToGames = computed(() => {
    const back = new URLSearchParams(page.url.split('?')[1]).get('back');

    return back?.startsWith('/games?') ? back : '/games';
});

const deleting = ref(false);
const removingCollectionId = ref<number | null>(null);
const currentCollections = computed(() => props.collections.filter(
    (collection) => props.game_collection_ids.includes(collection.id)
));

const detachCollection = (collection: Collection) => {
    if (deleting.value || form.processing || removingCollectionId.value !== null || bggLinkingId.value !== null) {
        return;
    }

    removingCollectionId.value = collection.id;
    router.delete(`/games/${props.game.id}/collections/${collection.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            form.collection_ids = form.collection_ids.filter((id) => id !== collection.id);
            form.defaults('collection_ids', [...props.game_collection_ids]);
        },
        onFinish: () => {
            removingCollectionId.value = null;
        },
    });
};

const deleteError = computed(() => {
    const errors = page.props.errors as Record<string, string>;

    return errors.game ?? null;
});

const deleteDialogOpen = ref(false);

const deleteGame = () => {
    if (
        deleting.value ||
        form.processing ||
        removingCollectionId.value !== null ||
        bggLinkingId.value !== null
    ) {
        return;
    }

    deleteDialogOpen.value = true;
};

const confirmDeleteGame = () => {
    if (
        deleting.value ||
        form.processing ||
        removingCollectionId.value !== null ||
        bggLinkingId.value !== null
    ) {
        return;
    }

    router.delete(`/games/${props.game.id}`, {
        preserveScroll: true,
        onStart: () => {
            deleting.value = true;
            deleteDialogOpen.value = false;
        },
        onFinish: () => {
            deleting.value = false;
        },
    });
};
</script>

<template>
    <Head :title="`Editar ${game.name}`" />

    <AppLayout>
        <div class="mx-auto w-full max-w-5xl px-4 py-6 sm:px-6 2xl:max-w-7xl">
            <div class="mb-6">
                <Link
                    :href="backToGames"
                    class="text-sm font-medium text-[#5200FF] hover:underline dark:text-[#CCFF00]"
                >
                    ← Volver a la colección
                </Link>

                <h1 class="mt-4 text-2xl font-bold text-zinc-900 dark:text-zinc-100 sm:text-3xl">
                    Editar juego
                </h1>

                <p class="mt-2 text-zinc-500 dark:text-zinc-400">
                    {{ game.name }}
                </p>
            </div>

            <section
                aria-labelledby="bgg-link-title"
                class="mt-6 mb-6 rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
            >
                <p class="text-xs font-black uppercase tracking-wider text-[#5200FF] dark:text-[#CCFF00]">
                    BOARDGAMEGEEK
                </p>
                <h2 id="bgg-link-title" class="mt-1 text-xl font-black">Datos de BoardGameGeek</h2>

                <div v-if="game.bgg_id" class="mt-5 rounded-xl bg-green-50 p-4 dark:bg-green-950/20">
                    <p class="font-bold text-green-700 dark:text-green-300">✓ Juego vinculado con BGG</p>
                    <p class="mt-1 text-sm text-zinc-500">BGG #{{ game.bgg_id }}</p>
                    <a
                        v-if="game.bgg_url"
                        :href="game.bgg_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="mt-3 inline-block text-sm font-bold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
                    >
                        Ver en BoardGameGeek <ChainIcon class="ml-1 inline-block h-4 w-4 shrink-0 align-[-0.15em]" aria-hidden="true" />
                    </a>
                </div>

                <template v-else>
                    <p class="mt-3 text-sm text-zinc-500">
                        Este juego fue creado manualmente. Puedes buscarlo por nombre,
                        pegar una URL de BGG o escribir directamente su ID.
                    </p>

                    <form class="mt-5" @submit.prevent="searchBgg">
                        <label for="bgg-link-query" class="sr-only">Nombre, URL o ID de BGG</label>
                        <div class="flex flex-col gap-3 sm:flex-row">
                            <input
                                id="bgg-link-query"
                                v-model="bggQuery"
                                type="text"
                                maxlength="255"
                                placeholder="Sky Team, URL o ID de BGG"
                                class="min-w-0 flex-1 rounded-xl border border-zinc-300 bg-white px-4 py-3 transition focus-visible:border-[#5200FF] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[#5200FF]/20 dark:border-zinc-700 dark:bg-zinc-900"
                            />
                            <button
                                type="submit"
                                :disabled="bggSearching || bggLinkingId !== null || !bggQuery.trim()"
                                class="rounded-xl bg-[#5200FF] px-5 py-3 font-bold text-white disabled:cursor-not-allowed disabled:opacity-40"
                            >
                                {{ bggSearching ? 'Buscando...' : 'Buscar en BGG' }}
                            </button>
                        </div>
                    </form>

                    <p class="mt-3 text-xs text-zinc-500">
                        Al vincularlo se conserva tu juego actual.
                        Solo se rellenarán los datos que estén vacíos.
                    </p>
                    <div v-if="bggError" role="alert" class="mt-4 rounded-xl bg-red-50 p-4 text-sm font-semibold text-red-600 dark:bg-red-950/20 dark:text-red-300">
                        {{ bggError }}
                    </div>
                    <p v-if="bggSearchCompleted && !bggResults.length && !bggError" role="status" class="mt-4 text-sm text-zinc-600 dark:text-zinc-400">
                        No se han encontrado juegos en BoardGameGeek.
                    </p>

                    <div v-if="bggResults.length" class="mt-5 space-y-3">
                        <article v-for="result in bggResults" :key="result.id" class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-4 transition-all duration-200 hover:-translate-y-0.5 hover:border-[#5200FF] hover:shadow-md focus-within:border-[#5200FF] focus-within:ring-4 focus-within:ring-[#5200FF]/20 motion-reduce:transform-none sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800">
                            <div class="min-w-0">
                                <h3 class="font-black">{{ result.name }}</h3>
                                <p class="mt-1 text-sm text-zinc-500">
                                    BGG #{{ result.id }}<span v-if="result.year_published"> · {{ result.year_published }}</span>
                                </p>
                            </div>
                            <button
                                type="button"
                                :disabled="bggLinkingId !== null || form.processing || deleting || removingCollectionId !== null"
                                class="shrink-0 rounded-xl bg-[#CCFF00] px-4 py-2.5 font-black text-black disabled:cursor-not-allowed disabled:opacity-40"
                                @click="linkWithBgg(result)"
                            >
                                {{ bggLinkingId === result.id ? 'Vinculando...' : 'Vincular y completar' }}
                            </button>
                        </article>
                    </div>
                </template>
            </section>

            <form
                class="space-y-6 rounded-2xl border p-6"
                @submit.prevent="submit"
            >
                <div>
                    <label class="mb-2 block font-semibold">
                        Nombre
                    </label>

                    <input
                        v-model="form.name"
                        type="text"
                        class="w-full rounded-xl border px-4 py-3"
                    />

                    <InputError :message="form.errors.name" />
                </div>

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
                                class="flex h-11 w-11 items-center justify-center rounded-full border font-semibold peer-checked:border-[#CCFF00] peer-checked:bg-[#CCFF00] peer-checked:text-black"
                            >
                                {{ number }}
                            </span>
                        </label>
                    </div>
                </div>

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
                    />
                </div>

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

                    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                        Puedes seleccionar varias modalidades o dejar
                        el juego sin clasificar.
                    </p>

                    <InputError :message="form.errors.game_modes" />
                </fieldset>

                <div>
                    <label class="mb-2 block font-semibold">
                        Descripción corta
                    </label>

                    <textarea
                        v-model="form.short_description"
                        rows="4"
                        class="w-full rounded-xl border px-4 py-3"
                    />
                </div>

                <div>
                    <label class="mb-2 block font-semibold">
                        Información adicional
                    </label>

                    <textarea
                        v-model="form.additional_information"
                        rows="3"
                        class="w-full rounded-xl border px-4 py-3"
                    />
                </div>

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
                        placeholder="BoardGameGeek"
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

                <button
                    type="submit"
                    :disabled="form.processing || deleting || removingCollectionId !== null || bggLinkingId !== null"
                    class="w-full rounded-xl bg-[#5200FF] px-6 py-3 font-semibold text-white transition hover:opacity-90 disabled:opacity-50"
                >
                    {{
                        form.processing
                            ? 'Guardando...'
                            : 'Guardar cambios'
                    }}
                </button>
            </form>

            <BggExpansionsImporter
                class="mt-10"
                :game-id="game.id"
                :bgg-id="game.bgg_id"
                :disabled="bggLinkingId !== null"
            />

            <section
                class="mt-8 rounded-2xl border border-zinc-200 bg-white p-5 sm:p-6 dark:border-zinc-800 dark:bg-zinc-950"
            >
                <div>
                    <p
                        class="text-xs font-black uppercase tracking-wide text-[#5200FF] dark:text-[#CCFF00]"
                    >
                        MI JUEGO
                    </p>

                    <h2
                        class="mt-1 text-xl font-black"
                    >
                        Expansiones guardadas
                    </h2>

                    <p
                        class="mt-2 text-sm text-zinc-500"
                    >
                        Expansiones disponibles para
                        votaciones y partidas.
                    </p>
                </div>

                <div
                    v-if="!game.expansions.length"
                    class="mt-5 rounded-xl border border-dashed border-zinc-300 p-6 text-center text-sm text-zinc-500 dark:border-zinc-700"
                >
                    Este juego todavía no tiene
                    expansiones guardadas.
                </div>

                <div
                    v-else
                    class="mt-6 space-y-4"
                >
                    <article
                        v-for="expansion in game.expansions"
                        :key="expansion.id"
                        class="rounded-2xl border border-zinc-200 p-4 dark:border-zinc-800"
                    >
                        <!-- VISTA -->

                        <div
                            v-if="
                                editingExpansionId
                                !== expansion.id
                            "
                            class="flex gap-4"
                        >
                            <div
                                class="h-28 w-20 shrink-0 overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-900"
                            >
                                <img
                                    v-if="
                                        expansion.image_url
                                    "
                                    :src="
                                        expansion.image_url
                                    "
                                    :alt="
                                        expansion.name
                                    "
                                    class="h-full w-full object-contain p-1"
                                />

                                <div
                                    v-else
                                    class="flex h-full items-center justify-center text-2xl"
                                >
                                    <BuildingComplexPlus class="inline-block size-6 shrink-0 align-[-0.15em] text-[#6D28D9] dark:text-[#CCFF00]" :stroke-width="2" aria-hidden="true" />
                                </div>
                            </div>

                            <div
                                class="min-w-0 flex-1"
                            >
                                <div
                                    class="flex flex-wrap items-center gap-2"
                                >
                                    <p
                                        class="font-black"
                                    >
                                        {{
                                            expansion.name
                                        }}
                                    </p>

                                    <span
                                        v-if="
                                            expansion.bgg_id
                                        "
                                        class="rounded-full bg-[#CCFF00] px-2 py-1 text-xs font-black text-black"
                                    >
                                        BGG
                                    </span>

                                    <span
                                        v-if="
                                            !expansion.is_active
                                        "
                                        class="rounded-full bg-zinc-200 px-2 py-1 text-xs font-bold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300"
                                    >
                                        Inactiva
                                    </span>
                                </div>

                                <p
                                    v-if="
                                        expansion.notes
                                    "
                                    class="mt-2 text-sm text-zinc-500"
                                >
                                    {{
                                        expansion.notes
                                    }}
                                </p>

                                <div
                                    class="mt-4 flex flex-wrap gap-2"
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
                                        class="rounded-lg border border-zinc-300 px-3 py-2 text-sm font-bold dark:border-zinc-700"
                                    >
                                        Ver en BGG <ChainIcon class="ml-1 inline-block h-4 w-4 shrink-0 align-[-0.15em]" aria-hidden="true" />
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
                                        class="rounded-lg border border-zinc-300 px-3 py-2 text-sm font-bold dark:border-zinc-700"
                                    >
                                        Tutorial <ChainIcon class="ml-1 inline-block h-4 w-4 shrink-0 align-[-0.15em]" aria-hidden="true" />
                                    </a>

                                    <button
                                        :disabled="expansionForm.processing"
                                        type="button"
                                        class="rounded-lg bg-[#5200FF] px-3 py-2 text-sm font-bold text-white"
                                        @click="
                                            editExpansion(
                                                expansion
                                            )
                                        "
                                    >
                                        <span class="inline-flex items-center justify-center gap-1.5"><PencilSparklesIcon class="h-4 w-4 shrink-0 text-violet-500 dark:text-[#CCFF00]" />Editar</span>
                                    </button>

                                    <button
                                        :disabled="expansionForm.processing"
                                        type="button"
                                        class="rounded-lg border border-red-500 px-3 py-2 text-sm font-bold text-red-500"
                                        @click="
                                            deleteExpansion(
                                                expansion
                                            )
                                        "
                                    >
                                        Eliminar
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- EDICIÓN -->

                        <form
                            v-else
                            class="space-y-4"
                            @submit.prevent="
                                saveExpansion(
                                    expansion
                                )
                            "
                        >
                            <div>
                                <label
                                    class="text-sm font-bold"
                                >
                                    Nombre
                                </label>

                                <input
                                    v-model="
                                        expansionForm.name
                                    "
                                    type="text"
                                    class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900"
                                />
                            </div>

                            <div>
                                <label
                                    class="text-sm font-bold"
                                >
                                    URL imagen
                                </label>

                                <input
                                    v-model="
                                        expansionForm.image_url
                                    "
                                    type="url"
                                    class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900"
                                />
                            </div>

                            <div>
                                <label
                                    class="text-sm font-bold"
                                >
                                    BoardGameGeek
                                </label>

                                <input
                                    v-model="
                                        expansionForm.bgg_url
                                    "
                                    type="url"
                                    class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900"
                                />
                            </div>

                            <div>
                                <label
                                    class="text-sm font-bold"
                                >
                                    Tutorial YouTube
                                </label>

                                <input
                                    v-model="
                                        expansionForm.youtube_url
                                    "
                                    type="url"
                                    class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900"
                                />
                            </div>

                            <div>
                                <label
                                    class="text-sm font-bold"
                                >
                                    Notas
                                </label>

                                <textarea
                                    v-model="
                                        expansionForm.notes
                                    "
                                    rows="3"
                                    class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900"
                                ></textarea>
                            </div>

                            <label
                                class="flex items-center gap-3"
                            >
                                <input
                                    v-model="
                                        expansionForm.is_active
                                    "
                                    type="checkbox"
                                />

                                <span
                                    class="text-sm font-bold"
                                >
                                    Expansión activa
                                </span>
                            </label>

                            <div
                                v-if="Object.keys(expansionForm.errors).length"
                                role="alert"
                                class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/20 dark:text-red-300"
                            >
                                <p class="font-bold">
                                    No se ha podido guardar la expansión.
                                </p>
                                <p
                                    v-for="(error, field) in expansionForm.errors"
                                    :key="field"
                                    class="mt-1"
                                >
                                    {{ error }}
                                </p>
                            </div>

                            <div
                                class="flex gap-2"
                            >
                                <button
                                    type="submit"
                                    :disabled="
                                        expansionForm.processing
                                    "
                                    class="rounded-xl bg-[#5200FF] px-5 py-3 font-bold text-white disabled:opacity-50"
                                >
                                    Guardar expansión
                                </button>

                                <button
                                    :disabled="expansionForm.processing"
                                    type="button"
                                    class="rounded-xl border border-zinc-300 px-5 py-3 font-bold dark:border-zinc-700"
                                    @click="
                                        cancelExpansionEdit
                                    "
                                >
                                    Cancelar
                                </button>
                            </div>
                        </form>
                    </article>
                </div>
            </section>

            <section
                aria-labelledby="current-collections-title"
                class="mt-10 rounded-2xl border border-zinc-200 bg-white p-5 sm:p-6 dark:border-zinc-800 dark:bg-zinc-950"
            >
                <h2 id="current-collections-title" class="font-black text-[#5200FF] dark:text-violet-300">
                    COLECCIONES
                </h2>

                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                    Quitar de una colección conserva el juego, sus expansiones,
                    partidas y participación en votaciones.
                </p>

                <ul class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-800">
                    <li
                        v-for="collection in currentCollections"
                        :key="collection.id"
                        class="flex items-center justify-between gap-4 py-3"
                    >
                        <span class="font-semibold">{{ collection.name }}</span>
                        <button
                            type="button"
                            :aria-label="`Quitar ${game.name} de ${collection.name}`"
                            :disabled="deleting || form.processing || removingCollectionId !== null || bggLinkingId !== null"
                            class="shrink-0 rounded-xl border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 transition hover:border-violet-500 hover:text-violet-700 disabled:cursor-not-allowed disabled:opacity-40 dark:border-zinc-700 dark:text-zinc-200 dark:hover:text-violet-300"
                            @click="detachCollection(collection)"
                        >
                            {{ removingCollectionId === collection.id ? 'Quitando...' : 'Quitar' }}
                        </button>
                    </li>
                </ul>
            </section>

            <hr class="my-10 border-zinc-200 dark:border-zinc-800" />

            <section
                aria-labelledby="danger-zone-title"
                class="rounded-2xl border border-red-200 bg-red-50/50 p-5 sm:p-6 dark:border-red-900 dark:bg-red-950/10"
            >
                <p id="danger-zone-title" class="text-xs font-black tracking-wide text-red-600 dark:text-red-400">
                    ZONA DE PELIGRO
                </p>

                <h2 class="mt-2 text-xl font-bold text-red-700 dark:text-red-300">
                    Eliminar juego definitivamente
                </h2>

                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                    Se eliminarán el juego, sus expansiones, sus partidas registradas
                    y su participación en votaciones. Esta acción no se puede deshacer.
                </p>

                <p
                    v-if="deleteError"
                    role="alert"
                    class="mt-3 text-sm font-semibold text-red-500"
                >
                    {{ deleteError }}
                </p>

                <button
                    type="button"
                    :disabled="deleting || form.processing || removingCollectionId !== null || bggLinkingId !== null"
                    class="mt-4 rounded-xl bg-red-600 px-5 py-3 font-bold text-white transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-40"
                    @click="deleteGame"
                >
                    {{ deleting ? 'Eliminando...' : 'Eliminar definitivamente' }}
                </button>
            </section>
        </div>
        <Dialog v-model:open="deleteDialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <div
                        class="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-red-100 text-2xl dark:bg-red-950"
                        aria-hidden="true"
                    >
                        🗑️
                    </div>

                    <DialogTitle class="text-xl font-black">
                        ¿Eliminar este juego?
                    </DialogTitle>

                    <DialogDescription class="pt-2 text-sm leading-6">
                        Estás a punto de eliminar definitivamente
                        <strong class="font-bold text-foreground">
                            {{ game.name }}
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
                        También se eliminarán las expansiones,
                        las partidas registradas y los datos
                        asociados a votaciones de este juego.
                    </p>
                </div>

                <DialogFooter class="mt-3 gap-2 sm:gap-3">
                    <button
                        type="button"
                        class="rounded-xl border border-zinc-300 px-5 py-3 font-semibold transition hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800"
                        @click="deleteDialogOpen = false"
                    >
                        Cancelar
                    </button>

                    <button
                        type="button"
                        :disabled="deleting"
                        class="rounded-xl bg-red-600 px-5 py-3 font-bold text-white transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
                        @click="confirmDeleteGame"
                    >
                        {{ deleting ? 'Eliminando...' : 'Sí, eliminar juego' }}
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
        <Dialog v-model:open="expansionDeleteDialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <div
                        class="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-red-100 text-2xl dark:bg-red-950"
                        aria-hidden="true"
                    >
                        <BuildingComplexPlus class="inline-block size-6 shrink-0 align-[-0.15em] text-[#6D28D9] dark:text-[#CCFF00]" :stroke-width="2" aria-hidden="true" />
                    </div>

                    <DialogTitle class="text-xl font-black">
                        ¿Eliminar esta expansión?
                    </DialogTitle>

                    <DialogDescription class="pt-2 text-sm leading-6">
                        Vas a eliminar
                        <strong class="font-bold text-foreground">
                            {{ expansionToDelete?.name }}
                        </strong>
                        del catálogo del juego en todas tus colecciones.
                    </DialogDescription>
                </DialogHeader>

                <div
                    class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
                >
                    <p class="font-bold">
                        ⚠️ Esta acción no se puede deshacer.
                    </p>

                    <p class="mt-2">
                        Las partidas se conservarán, pero dejarán de indicar esta expansión.
                        Si otra cuenta la utiliza, el borrado se bloqueará.
                    </p>
                </div>

                <DialogFooter class="mt-3 gap-2 sm:gap-3">
                    <button
                        type="button"
                        :disabled="deletingExpansion"
                        class="rounded-xl border border-zinc-300 px-5 py-3 font-semibold transition hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800 disabled:opacity-50"
                        @click="expansionDeleteDialogOpen = false"
                    >
                        Cancelar
                    </button>

                    <button
                        type="button"
                        :disabled="deletingExpansion"
                        class="rounded-xl bg-red-600 px-5 py-3 font-bold text-white transition hover:bg-red-700 disabled:opacity-50"
                        @click="confirmDeleteExpansion"
                    >
                        {{ deletingExpansion ? 'Eliminando...' : 'Sí, eliminar expansión' }}
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
        <ScrollToTopProgress :remember-position="false" />
    </AppLayout>
</template>
