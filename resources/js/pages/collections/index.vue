<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

import {
    Head,
    Link,
    router,
    useForm,
    usePage,
} from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import InputError from '@/components/InputError.vue';
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

interface Collection {
    id: number;
    name: string;
    bgg_username: string | null;
    bgg_sync_status: 'idle' | 'pending' | 'running' | 'completed' | 'failed';
    bgg_sync_started_at: string | null;
    bgg_last_synced_at: string | null;
    bgg_sync_error: string | null;
    bgg_sync_games_added: number;
    bgg_sync_expansions_added: number;

    is_default: boolean;
    is_public: boolean;

    games_count: number;
}

const props = defineProps<{
    collections: Collection[];
    publicCatalogPath: string | null;
}>();

/*
|--------------------------------------------------------------------------
| Nueva colección
|--------------------------------------------------------------------------
*/

const showCreateForm =
    ref(false);

const form = useForm({
    name: '',
});

const submit = () => {
    form.post(
        '/collections',
        {
            preserveScroll: true,

            onSuccess: () => {
                form.reset();

                showCreateForm.value =
                    false;
            },
        }
    );
};

/*
|--------------------------------------------------------------------------
| Predeterminada
|--------------------------------------------------------------------------
*/

const makeDefault = (
    collection: Collection
) => {
    if (
        collection.is_default
    ) {
        return;
    }

    router.patch(
        `/collections/${collection.id}/default`,
        {},
        {
            preserveScroll: true,
        }
    );
};

const togglePublic = (collection: Collection) => {
    router.patch(
        `/collections/${collection.id}/public`,
        {},
        {
            preserveScroll: true,
        }
    );
};

/*
|--------------------------------------------------------------------------
| Eliminar
|--------------------------------------------------------------------------
*/

const collectionToDelete = ref<Collection | null>(null);
const deleteCollectionDialogOpen = ref(false);
const deletingCollection = ref(false);

const deleteCollection = (collection: Collection) => {
    if (collection.is_default || deletingCollection.value) {
        return;
    }

    collectionToDelete.value = collection;
    deleteCollectionDialogOpen.value = true;
};

const confirmDeleteCollection = () => {
    const collection = collectionToDelete.value;

    if (
        !collection ||
        collection.is_default ||
        deletingCollection.value
    ) {
        return;
    }

    router.delete(`/collections/${collection.id}`, {
        preserveScroll: true,

        onStart: () => {
            deletingCollection.value = true;
        },

        onSuccess: () => {
            deleteCollectionDialogOpen.value = false;
            collectionToDelete.value = null;
        },

        onFinish: () => {
            deletingCollection.value = false;
        },
    });
};
const copiedCatalogLink = ref(false);

const copyLinkDialogOpen = ref(false);
const fallbackCatalogUrl = ref('');

const selectCatalogLink = (event: FocusEvent) => {
    const input = event.target;

    if (input instanceof HTMLInputElement) {
        input.select();
    }
};

const copyCatalogLink = async () => {
    if (!props.publicCatalogPath) return;

    const url = new URL(
        props.publicCatalogPath,
        window.location.origin
    ).href;

    try {
        await navigator.clipboard.writeText(url);
        copiedCatalogLink.value = true;
    } catch {
        fallbackCatalogUrl.value = url;
        copyLinkDialogOpen.value = true;
    }
};

interface BggPreviewItem {
    bgg_id: number;
    name: string;
    status: 'new' | 'existing';
}

interface BggPreview {
    username: string;
    summary: {
        games: number;
        games_existing: number;
        games_new: number;
        expansions: number;
        expansions_existing: number;
        expansions_new: number;
    };
    games: BggPreviewItem[];
    expansions: BggPreviewItem[];
    removal_candidates: {
        id: number;
        bgg_id: number;
        name: string;
    }[];
    expansion_removal_candidates: {
        id: number;
        bgg_id: number;
        name: string;
        game_id: number;
    }[];
}

const bggCollectionId = ref<number | ''>('');
const bggPreview = ref<BggPreview | null>(null);
const bggLoading = ref(false);
const bggError = ref('');
const bggTab = ref<'games' | 'expansions'>('games');

const bggSyncing = ref(false);
const bggSyncMessage = ref('');
const bggSyncError = ref('');

const page = usePage();

const selectedBggCollection = computed(() => {
    const collectionsData =
        page.props.collections as Collection[] | undefined;

    return collectionsData?.find(
        collection =>
            Number(collection.id) === Number(bggCollectionId.value)
    );
});

const bggStatusLabels: Record<string, string> = {
    idle: 'Sin sincronizaciones registradas',
    pending: 'Pendiente',
    running: 'Sincronizando...',
    completed: 'Sincronización completada',
    failed: 'Error de sincronización',
};

let bggPollTimer: ReturnType<typeof setInterval> | undefined;
let bggPollInFlight = false;

onMounted(() => {
    bggPollTimer = setInterval(() => {
        const status = selectedBggCollection.value?.bgg_sync_status;

        if (
            !bggPollInFlight &&
            (status === 'pending' || status === 'running')
        ) {
            bggPollInFlight = true;

            router.reload({
                only: ['collections'],
                onFinish: () => {
                    bggPollInFlight = false;
                },
            });
        }
    }, 5000);
});

onUnmounted(() => {
    if (bggPollTimer !== undefined) {
        clearInterval(bggPollTimer);
    }
});

const syncBggCollection = () => {
    if (bggCollectionId.value === '' || bggSyncing.value) {
        return;
    }

    bggSyncing.value = true;
    bggSyncMessage.value = '';
    bggSyncError.value = '';

    router.post(
        `/collections/${bggCollectionId.value}/bgg/sync`,
        {},
        {
            preserveScroll: true,

            onSuccess: () => {
                bggSyncMessage.value =
                    'Sincronización enviada a la cola. ' +
                    'Los juegos y expansiones nuevos se incorporarán ' +
                    'cuando termine el proceso.';
            },

            onError: (errors) => {
                bggSyncError.value =
                    errors.bgg ||
                    Object.values(errors)[0] ||
                    'No se pudo iniciar la sincronización.';
            },

            onFinish: () => {
                bggSyncing.value = false;
            },
        }
    );
};

const bggRemovingId = ref<number | null>(null);

const bggGameToRemove = ref<{
    id: number;
    bgg_id: number;
    name: string;
} | null>(null);

const bggRemoveDialogOpen = ref(false);

const confirmBggRemoval = (game: {
    id: number;
    bgg_id: number;
    name: string;
}) => {
    if (
        bggCollectionId.value === '' ||
        bggRemovingId.value !== null ||
        ['pending', 'running'].includes(
            selectedBggCollection.value?.bgg_sync_status ?? ''
        )
    ) {
        return;
    }

    bggGameToRemove.value = game;
    bggRemoveDialogOpen.value = true;
};

const removeSelectedBggGame = () => {
    if (!bggGameToRemove.value) return;

    const game = bggGameToRemove.value;

    bggRemoveDialogOpen.value = false;
    bggGameToRemove.value = null;

    executeBggRemoval(game);
};

const executeBggRemoval = (game: {
    id: number;
    bgg_id: number;
    name: string;
}) => {
    if (
        bggCollectionId.value === '' ||
        bggRemovingId.value !== null ||
        ['pending', 'running'].includes(
            selectedBggCollection.value?.bgg_sync_status ?? ''
        )
    ) {
        return;
    }

    bggRemovingId.value = game.id;
    bggSyncError.value = '';
    bggSyncMessage.value = '';

    router.post(
        `/collections/${bggCollectionId.value}/bgg/remove/${game.id}`,
        {},
        {
            preserveScroll: true,

            onSuccess: () => {
                bggSyncMessage.value =
                    `"${game.name}" se ha retirado de la colección BGG.`;

                // Actualizar la lista visible.
                if (bggPreview.value) {
                    bggPreview.value.removal_candidates =
                        bggPreview.value.removal_candidates.filter(
                            item => item.id !== game.id
                        );
                }
            },

            onError: (errors) => {
                bggSyncError.value =
                    errors.bgg ||
                    Object.values(errors)[0] ||
                    'No se pudo confirmar la baja.';
            },

            onFinish: () => {
                bggRemovingId.value = null;
            },
        }
    );
};

const bggRemovingExpansionId = ref<number | null>(null);

const bggExpansionToRemove = ref<{
    id: number;
    bgg_id: number;
    name: string;
    game_id: number;
} | null>(null);

const bggExpansionRemoveDialogOpen = ref(false);

const confirmBggExpansionRemoval = (expansion: {
    id: number;
    bgg_id: number;
    name: string;
    game_id: number;
}) => {
    if (
        bggCollectionId.value === '' ||
        bggRemovingExpansionId.value !== null ||
        bggRemovingId.value !== null ||
        ['pending', 'running'].includes(
            selectedBggCollection.value?.bgg_sync_status ?? ''
        )
    ) {
        return;
    }

    bggExpansionToRemove.value = expansion;
    bggExpansionRemoveDialogOpen.value = true;
};

const removeSelectedBggExpansion = () => {
    if (!bggExpansionToRemove.value) return;

    const expansion = bggExpansionToRemove.value;

    bggExpansionRemoveDialogOpen.value = false;
    bggExpansionToRemove.value = null;

    executeBggExpansionRemoval(expansion);
};

const executeBggExpansionRemoval = (expansion: {
    id: number;
    bgg_id: number;
    name: string;
    game_id: number;
}) => {
    if (
        bggCollectionId.value === '' ||
        bggRemovingExpansionId.value !== null ||
        bggRemovingId.value !== null ||
        ['pending', 'running'].includes(
            selectedBggCollection.value?.bgg_sync_status ?? ''
        )
    ) {
        return;
    }

    bggRemovingExpansionId.value = expansion.id;
    bggSyncError.value = '';
    bggSyncMessage.value = '';

    router.post(
        `/collections/${bggCollectionId.value}/bgg/remove-expansion/${expansion.id}`,
        {},
        {
            preserveScroll: true,

            onSuccess: () => {
                bggSyncMessage.value =
                    `"${expansion.name}" se ha retirado de la colección BGG.`;

                if (bggPreview.value) {
                    bggPreview.value.expansion_removal_candidates =
                        bggPreview.value.expansion_removal_candidates.filter(
                            item => item.id !== expansion.id
                        );
                }
            },

            onError: (errors) => {
                bggSyncError.value =
                    errors.bgg ||
                    Object.values(errors)[0] ||
                    'No se pudo confirmar la baja de la expansión.';
            },

            onFinish: () => {
                bggRemovingExpansionId.value = null;
            },
        }
    );
};

const loadBggPreview = async () => {
    if (bggCollectionId.value === '') return;

    bggLoading.value = true;
    bggError.value = '';
    bggPreview.value = null;

    try {
        const response = await fetch(
            `/collections/${bggCollectionId.value}/bgg/preview`,
            {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                },
            }
        );

        const result = await response.json();

        if (!response.ok) {
            throw new Error(
                result.message ?? 'No se pudo consultar BGG.'
            );
        }

        bggPreview.value = result as BggPreview;
    } catch (error) {
        bggError.value = error instanceof Error
            ? error.message
            : 'Error al conectar con BGG.';
    } finally {
        bggLoading.value = false;
    }
};
watch(
    () => selectedBggCollection.value?.bgg_sync_status,
    (currentStatus, previousStatus) => {
        const wasSyncing =
            previousStatus === 'pending' ||
            previousStatus === 'running';

        // Cuando la sincronización termina correctamente,
        // actualizamos automáticamente la vista previa.
        if (wasSyncing && currentStatus === 'completed') {
            bggSyncMessage.value =
                'Sincronización completada correctamente.';

            void loadBggPreview();
        }
    }
);
</script>

<template>
    <Head title="Colecciones" />

    <AppLayout>
        <div
            class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6"
        >
            <!-- ================================================== -->
            <!-- CABECERA                                           -->
            <!-- ================================================== -->

            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
            >
                <div>
                    <p
                        class="font-semibold text-[#5200FF]"
                    >
                        COLECCIONES
                    </p>

                    <h1
                        class="mt-1 text-3xl font-bold text-zinc-900 dark:text-zinc-100"
                    >
                        Mis colecciones
                    </h1>

                    <p
                        class="mt-2 text-zinc-500 dark:text-zinc-400"
                    >
                        Organiza los juegos según
                        quién los tenga o el grupo
                        al que pertenezcan.
                    </p>
                </div>

                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-xl bg-[#5200FF] px-5 py-3 font-bold text-white transition hover:opacity-90"
                    @click="
                        showCreateForm =
                            !showCreateForm
                    "
                >
                    {{
                        showCreateForm
                            ? 'Cancelar'
                            : '+ Nueva colección'
                    }}
                </button>
            </div>

            <!-- ================================================== -->
            <!-- NUEVA COLECCIÓN                                    -->
            <!-- ================================================== -->

            <form
                v-if="showCreateForm"
                class="mt-8 rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                @submit.prevent="submit"
            >
                <h2
                    class="text-lg font-bold text-zinc-900 dark:text-zinc-100"
                >
                    Crear colección
                </h2>

                <p
                    class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"
                >
                    Por ejemplo:
                    "Colección de Ana" o
                    "Juegos del grupo".
                </p>

                <div
                    class="mt-5 flex flex-col gap-3 sm:flex-row"
                >
                    <div class="flex-1">
                        <input
                            v-model="form.name"
                            type="text"
                            class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 placeholder:text-zinc-400 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:placeholder:text-zinc-500"
                            placeholder="Nombre de la colección"
                        />

                        <InputError :message="form.errors.name" />
                    </div>

                    <button
                        type="submit"
                        :disabled="
                            form.processing
                        "
                        class="rounded-xl bg-[#CCFF00] px-5 py-3 font-bold text-black transition hover:opacity-90 disabled:opacity-50"
                    >
                        {{
                            form.processing
                                ? 'Creando...'
                                : 'Crear'
                        }}
                    </button>
                </div>
            </form>

            <!-- ================================================== -->
            <!-- ERROR GENERAL                                      -->
            <!-- ================================================== -->

            <div
                v-if="
                    $page.props.errors
                        .collection
                "
                class="mt-6 rounded-xl bg-red-50 p-4 text-sm font-semibold text-red-700 dark:bg-red-950/30 dark:text-red-300"
            >
                {{
                    $page.props.errors
                        .collection
                }}
            </div>

            <!-- COMPARTIR CATÁLOGO -->
            <section
                v-if="publicCatalogPath"
                class="mt-8 rounded-2xl border border-violet-200 bg-violet-50 p-6 dark:border-violet-900 dark:bg-violet-950/30"
            >
                <h2 class="text-xl font-bold text-zinc-900 dark:text-white">
                    🌐 Tu catálogo compartido
                </h2>

                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                    Comparte tus colecciones públicas con tus amigos.
                    Podrán consultar los juegos sin iniciar sesión.
                </p>

                <div class="mt-5 flex flex-wrap gap-3">
                    <a
                        :href="publicCatalogPath"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="rounded-xl bg-[#5200FF] px-5 py-3 text-sm font-bold text-white transition hover:opacity-90"
                    >
                        Ver mi catálogo ↗
                    </a>

                    <button
                        type="button"
                        class="rounded-xl border border-violet-300 bg-white px-5 py-3 text-sm font-bold text-[#5200FF] transition hover:bg-violet-100"
                        @click="copyCatalogLink"
                    >
                        {{ copiedCatalogLink
                            ? '✓ Enlace copiado'
                            : 'Copiar enlace'
                        }}
                    </button>
                </div>
            </section>

            <!-- SINCRONIZACIÓN BGG -->
            <section
                class="mt-8 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-950"
            >
                <h2 class="text-xl font-bold">
                    🎲 Sincronización con BoardGameGeek
                </h2>

                <p class="mt-2 text-sm text-zinc-500">
                    Consulta los juegos y expansiones de tu cuenta BGG.
                    Esta vista previa no modifica tu colección.
                </p>

                <div class="mt-5 flex flex-wrap gap-3">
                    <select
                        v-model.number="bggCollectionId"
                        class="min-w-48 flex-1 rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                        @change="bggPreview = null"
                    >
                        <option value="">Selecciona una colección</option>
                        <option
                            v-for="collection in collections.filter(
                                collection => Boolean(collection.bgg_username)
                            )"
                            :key="collection.id"
                            :value="collection.id"
                        >
                            {{ collection.name }} · {{ collection.bgg_username }}
                        </option>
                    </select>

                    <button
                        type="button"
                        :disabled="bggLoading || bggCollectionId === ''"
                        class="rounded-xl bg-[#5200FF] px-5 py-3 font-bold text-white disabled:opacity-50"
                        @click="loadBggPreview"
                    >
                        {{ bggLoading ? 'Consultando BGG...' : 'Consultar BGG' }}
                    </button>

                    <button
                        type="button"
                        :disabled="
                            bggSyncing ||
                            bggLoading ||
                            bggCollectionId === '' ||
                            ['pending', 'running'].includes(
                                selectedBggCollection?.bgg_sync_status ?? ''
                            )
                        "
                        class="rounded-xl bg-emerald-600 px-5 py-3 font-bold text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                        @click="syncBggCollection"
                    >
                        {{
                            bggSyncing
                                ? 'Enviando sincronización...'
                                : '🔄 Sincronizar con BGG'
                        }}
                    </button>
                </div>

                <!-- MENSAJE DE SINCRONIZACIÓN -->
                <div
                    v-if="bggSyncMessage"
                    role="status"
                    class="mt-4 flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-900 shadow-sm dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200"
                >
                    <span
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-xl dark:bg-emerald-900"
                        aria-hidden="true"
                    >
                        ✓
                    </span>

                    <div class="min-w-0">
                        <p class="font-bold">
                            Información de sincronización
                        </p>
                        <p class="mt-1 text-sm leading-6">
                            {{ bggSyncMessage }}
                        </p>
                    </div>
                </div>

                <!-- ERROR DE SINCRONIZACIÓN -->
                <div
                    v-if="bggSyncError"
                    role="alert"
                    class="mt-4 flex items-start gap-3 rounded-2xl border border-red-200 bg-red-50 p-4 text-red-900 shadow-sm dark:border-red-900 dark:bg-red-950/30 dark:text-red-200"
                >
                    <span
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-red-100 text-xl dark:bg-red-900"
                        aria-hidden="true"
                    >
                        !
                    </span>

                    <div class="min-w-0">
                        <p class="font-bold">
                            No se ha podido completar la operación
                        </p>
                        <p class="mt-1 text-sm leading-6">
                            {{ bggSyncError }}
                        </p>
                    </div>
                </div>

                <div
                    v-if="selectedBggCollection"
                    class="mt-5 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700"
                >
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h3 class="font-bold">
                            Estado de sincronización
                        </h3>

                        <span
                            class="rounded-full px-3 py-1 text-xs font-bold"
                            :class="{
                                'bg-amber-100 text-amber-800':
                                    selectedBggCollection.bgg_sync_status === 'pending',
                                'bg-blue-100 text-blue-800':
                                    selectedBggCollection.bgg_sync_status === 'running',
                                'bg-emerald-100 text-emerald-800':
                                    selectedBggCollection.bgg_sync_status === 'completed',
                                'bg-red-100 text-red-800':
                                    selectedBggCollection.bgg_sync_status === 'failed',
                                'bg-zinc-100 text-zinc-700':
                                    selectedBggCollection.bgg_sync_status === 'idle',
                            }"
                        >
                            {{
                                bggStatusLabels[
                                    selectedBggCollection.bgg_sync_status
                                ] ?? 'Estado desconocido'
                            }}
                        </span>
                    </div>

                    <p
                        v-if="['pending', 'running'].includes(
                            selectedBggCollection.bgg_sync_status
                        )"
                        class="mt-3 text-sm text-blue-700 dark:text-blue-300"
                    >
                        Estamos consultando BoardGameGeek.
                        El estado se actualiza automáticamente.
                    </p>

                    <template
                        v-if="selectedBggCollection.bgg_sync_status === 'completed'"
                    >
                        <div class="mt-4 grid grid-cols-2 gap-3">
                            <div class="rounded-lg bg-zinc-100 p-4 dark:bg-zinc-900">
                                <p class="text-xs text-zinc-500">Juegos añadidos</p>
                                <p class="text-2xl font-bold">
                                    {{ selectedBggCollection.bgg_sync_games_added }}
                                </p>
                            </div>

                            <div class="rounded-lg bg-zinc-100 p-4 dark:bg-zinc-900">
                                <p class="text-xs text-zinc-500">Expansiones añadidas</p>
                                <p class="text-2xl font-bold">
                                    {{ selectedBggCollection.bgg_sync_expansions_added }}
                                </p>
                            </div>
                        </div>

                        <p class="mt-3 text-xs text-zinc-500">
                            Última sincronización:
                            {{ selectedBggCollection.bgg_last_synced_at }}
                        </p>
                    </template>

                    <p
                        v-if="selectedBggCollection.bgg_sync_status === 'failed'"
                        class="mt-3 text-sm text-red-700 dark:text-red-400"
                    >
                        {{ selectedBggCollection.bgg_sync_error }}
                    </p>
                </div>

                <p
                    v-if="bggError"
                    class="mt-4 rounded-xl bg-red-50 p-4 text-sm text-red-700"
                >
                    {{ bggError }}
                </p>

                <div v-if="bggPreview" class="mt-7">
                    <p class="mb-4 text-sm text-zinc-500">
                        Cuenta: <strong>{{ bggPreview.username }}</strong>
                    </p>

                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div class="rounded-xl bg-zinc-100 p-4 dark:bg-zinc-900">
                            <p class="text-xs text-zinc-500">Juegos nuevos</p>
                            <p class="mt-1 text-2xl font-black">
                                {{ bggPreview.summary.games_new }}
                            </p>
                        </div>

                        <div class="rounded-xl bg-zinc-100 p-4 dark:bg-zinc-900">
                            <p class="text-xs text-zinc-500">Juegos existentes</p>
                            <p class="mt-1 text-2xl font-black">
                                {{ bggPreview.summary.games_existing }}
                            </p>
                        </div>

                        <div class="rounded-xl bg-zinc-100 p-4 dark:bg-zinc-900">
                            <p class="text-xs text-zinc-500">Expansiones nuevas</p>
                            <p class="mt-1 text-2xl font-black">
                                {{ bggPreview.summary.expansions_new }}
                            </p>
                        </div>

                        <div class="rounded-xl bg-zinc-100 p-4 dark:bg-zinc-900">
                            <p class="text-xs text-zinc-500">Expansiones existentes</p>
                            <p class="mt-1 text-2xl font-black">
                                {{ bggPreview.summary.expansions_existing }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-6 flex gap-3">
                        <button
                            type="button"
                            class="rounded-lg px-4 py-2 text-sm font-bold"
                            :class="bggTab === 'games'
                                ? 'bg-[#5200FF] text-white'
                                : 'bg-zinc-100 text-zinc-700'"
                            @click="bggTab = 'games'"
                        >
                            Juegos ({{ bggPreview.summary.games }})
                        </button>

                        <button
                            type="button"
                            class="rounded-lg px-4 py-2 text-sm font-bold"
                            :class="bggTab === 'expansions'
                                ? 'bg-[#5200FF] text-white'
                                : 'bg-zinc-100 text-zinc-700'"
                            @click="bggTab = 'expansions'"
                        >
                            Expansiones ({{ bggPreview.summary.expansions }})
                        </button>
                    </div>

                    <div
                        class="mt-4 max-h-96 overflow-y-auto rounded-xl border border-zinc-200 dark:border-zinc-700"
                    >
                        <div
                            v-for="item in (bggTab === 'games'
                                ? bggPreview.games
                                : bggPreview.expansions)"
                            :key="item.bgg_id"
                            class="flex items-center justify-between gap-3 border-b border-zinc-100 px-4 py-3 last:border-0 dark:border-zinc-800"
                        >
                            <div>
                                <p class="text-sm font-semibold">
                                    {{ item.name }}
                                </p>
                                <p class="text-xs text-zinc-500">
                                    BGG ID: {{ item.bgg_id }}
                                </p>
                            </div>

                            <span
                                class="shrink-0 rounded-full px-3 py-1 text-xs font-bold"
                                :class="item.status === 'existing'
                                    ? 'bg-emerald-100 text-emerald-800'
                                    : 'bg-violet-100 text-violet-800'"
                            >
                                {{ item.status === 'existing' ? 'Existente' : 'Nuevo' }}
                            </span>
                        </div>
                    </div>

                    <!-- JUEGOS RETIRADOS DE BGG -->
                    <div
                        v-if="bggPreview.removal_candidates?.length"
                        class="mt-6 rounded-xl border border-amber-500/40 bg-amber-500/5 p-5"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-lg font-bold">
                                Juegos retirados de BGG
                            </h3>

                            <span
                                class="rounded-full bg-amber-100 px-3 py-1 text-sm font-bold text-amber-800"
                            >
                                {{ bggPreview.removal_candidates.length }}
                                pendientes
                            </span>
                        </div>

                        <p class="mt-2 text-sm text-zinc-400">
                            Estos juegos siguen en tu colección de Laravel,
                            pero ya no aparecen como propios en BoardGameGeek.
                        </p>

                        <div class="mt-4 space-y-2">
                            <div
                                v-for="game in bggPreview.removal_candidates"
                                :key="game.id"
                                class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-zinc-700 bg-zinc-900 p-4"
                            >
                                <div>
                                    <p class="font-semibold text-white">
                                        {{ game.name }}
                                    </p>
                                    <p class="text-xs text-zinc-400">
                                        BGG ID: {{ game.bgg_id }}
                                    </p>
                                </div>

                                <button
                                    type="button"
                                    :disabled="
                                        bggRemovingId !== null ||
                                        ['pending', 'running'].includes(
                                            selectedBggCollection?.bgg_sync_status ?? ''
                                        )
                                    "
                                    class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-50"
                                    @click="confirmBggRemoval(game)"
                                >
                                    {{
                                        bggRemovingId === game.id
                                            ? 'Verificando con BGG...'
                                            : 'Confirmar baja'
                                    }}
                                </button>
                            </div>
                        </div>

                        <p class="mt-4 text-xs text-zinc-400">
                            Ningún juego se ha eliminado.
                            Las bajas deberán confirmarse antes de aplicarse.
                        </p>
                    </div>

                    <!-- EXPANSIONES RETIRADAS DE BGG -->
                    <div
                        class="mt-6 rounded-xl border border-amber-500/40 bg-amber-500/5 p-5"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <h3 class="text-lg font-bold">
                                Expansiones retiradas de BGG
                            </h3>

                            <span
                                class="rounded-full bg-amber-100 px-3 py-1 text-sm font-bold text-amber-800"
                            >
                                {{ bggPreview.expansion_removal_candidates?.length ?? 0 }}
                                pendientes
                            </span>
                        </div>

                        <p class="mt-2 text-sm text-zinc-500">
                            Expansiones que siguen asociadas a esta colección
                            de Laravel, pero que ya no aparecen como propias en BGG.
                        </p>

                        <p
                            v-if="!bggPreview.expansion_removal_candidates?.length"
                            class="mt-4 text-sm text-emerald-600 dark:text-emerald-400"
                        >
                            ✓ No hay bajas de expansiones pendientes.
                        </p>

                        <div v-else class="mt-4 space-y-2">
                            <div
                                v-for="expansion in bggPreview.expansion_removal_candidates"
                                :key="expansion.id"
                                class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700"
                            >
                                <div>
                                    <p class="font-semibold">
                                        {{ expansion.name }}
                                    </p>

                                    <p class="text-xs text-zinc-500">
                                        BGG ID: {{ expansion.bgg_id }}
                                    </p>
                                </div>

                                <button
                                    type="button"
                                    :disabled="
                                        bggRemovingExpansionId !== null ||
                                        bggRemovingId !== null ||
                                        ['pending', 'running'].includes(
                                            selectedBggCollection?.bgg_sync_status ?? ''
                                        )
                                    "
                                    class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-50"
                                    @click="confirmBggExpansionRemoval(expansion)"
                                >
                                    {{
                                        bggRemovingExpansionId === expansion.id
                                            ? 'Verificando con BGG...'
                                            : 'Confirmar baja'
                                    }}
                                </button>
                            </div>
                        </div>

                        <p class="mt-4 text-xs text-zinc-500">
                            Las bajas no se aplican automáticamente.
                            Las fichas de las expansiones se conservarán.
                        </p>
                    </div>

                    <p class="mt-4 text-xs text-zinc-500">
                        Vista previa únicamente. No se ha importado,
                        actualizado ni eliminado ningún juego.
                    </p>
                </div>
            </section>

            <!-- ================================================== -->
            <!-- LISTADO                                            -->
            <!-- ================================================== -->

            <div
                v-if="collections.length"
                class="mt-8 space-y-4"
            >
                <article
                    v-for="collection in collections"
                    :key="collection.id"
                    class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <div
                        class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <!-- INFO -->

                        <div>
                            <div
                                class="flex flex-wrap items-center gap-3"
                            >
                                <h2
                                    class="text-xl font-bold text-zinc-900 dark:text-zinc-100"
                                >
                                    {{
                                        collection.name
                                    }}
                                </h2>

                                <span
                                    v-if="
                                        collection.is_default
                                    "
                                    class="rounded-full bg-[#CCFF00] px-3 py-1 text-xs font-bold text-black"
                                >
                                    Predeterminada
                                </span>
                            </div>

                            <p
                                class="mt-2 text-sm text-zinc-500 dark:text-zinc-400"
                            >
                                🎲
                                {{
                                    collection.games_count
                                }}

                                {{
                                    collection.games_count ===
                                    1
                                        ? 'juego'
                                        : 'juegos'
                                }}
                            </p>
                        </div>

                        <!-- ACCIONES -->

                        <div
                            class="flex flex-wrap gap-3"
                        >
                            <!-- VER -->

                            <Link
                                :href="`/games?collection_id=${collection.id}`"
                                class="rounded-xl border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 transition hover:border-[#5200FF] hover:text-[#5200FF] dark:border-zinc-700 dark:text-zinc-200"
                            >
                                Ver juegos
                            </Link>

                            <!-- PREDETERMINADA -->

                            <button
                                v-if="
                                    !collection.is_default
                                "
                                type="button"
                                class="rounded-xl border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 transition hover:border-[#5200FF] hover:text-[#5200FF] dark:border-zinc-700 dark:text-zinc-200"
                                @click="
                                    makeDefault(
                                        collection
                                    )
                                "
                            >
                                Hacer predeterminada
                            </button>

                            <!-- VISIBILIDAD -->

                            <button
                                type="button"
                                class="rounded-xl border px-4 py-2 text-sm font-semibold transition"
                                :class="collection.is_public
                                    ? 'border-emerald-300 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                                    : 'border-zinc-300 text-zinc-600 hover:border-[#5200FF] hover:text-[#5200FF] dark:border-zinc-700 dark:text-zinc-300'"
                                @click="togglePublic(collection)"
                            >
                                {{ collection.is_public
                                    ? '🌐 Pública · Hacer privada'
                                    : '🔒 Privada · Compartir'
                                }}
                            </button>

                            <!-- ELIMINAR -->

                            <button
                                v-if="
                                    !collection.is_default
                                "
                                type="button"
                                class="rounded-xl border border-red-200 px-4 py-2 text-sm font-semibold text-red-600 transition hover:bg-red-50 dark:border-red-900 dark:text-red-400 dark:hover:bg-red-950/30"
                                @click="
                                    deleteCollection(
                                        collection
                                    )
                                "
                            >
                                🗑️ Eliminar
                            </button>
                        </div>
                    </div>
                </article>
            </div>

            <!-- SIN COLECCIONES -->

            <div
                v-else
                class="mt-8 rounded-2xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700"
            >
                <p
                    class="text-lg font-bold text-zinc-900 dark:text-zinc-100"
                >
                    No tienes colecciones
                </p>

                <p
                    class="mt-2 text-zinc-500 dark:text-zinc-400"
                >
                    Crea la primera para empezar a
                    organizar tus juegos.
                </p>
            </div>
        </div>
        <Dialog v-model:open="deleteCollectionDialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <div
                        class="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-red-100 text-2xl dark:bg-red-950"
                        aria-hidden="true"
                    >
                        🗂️
                    </div>

                    <DialogTitle class="text-xl font-black">
                        ¿Eliminar esta colección?
                    </DialogTitle>

                    <DialogDescription class="pt-2 text-sm leading-6">
                        Vas a eliminar la colección
                        <strong class="font-bold text-foreground">
                            {{ collectionToDelete?.name }}
                        </strong>.
                    </DialogDescription>
                </DialogHeader>

                <div
                    class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
                >
                    <p class="font-bold">
                        ⚠️ La colección desaparecerá.
                    </p>

                    <p class="mt-2">
                        Los juegos no se eliminarán y seguirán
                        disponibles en las demás colecciones
                        a las que pertenezcan.
                    </p>
                </div>

                <DialogFooter class="mt-3 gap-2 sm:gap-3">
                    <button
                        type="button"
                        :disabled="deletingCollection"
                        class="rounded-xl border border-zinc-300 px-5 py-3 font-semibold transition hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800 disabled:opacity-50"
                        @click="deleteCollectionDialogOpen = false"
                    >
                        Cancelar
                    </button>

                    <button
                        type="button"
                        :disabled="deletingCollection"
                        class="rounded-xl bg-red-600 px-5 py-3 font-bold text-white transition hover:bg-red-700 disabled:opacity-50"
                        @click="confirmDeleteCollection"
                    >
                        {{ deletingCollection ? 'Eliminando...' : 'Sí, eliminar colección' }}
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
        <Dialog v-model:open="bggRemoveDialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <div
                        class="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-purple-100 text-2xl dark:bg-purple-950"
                        aria-hidden="true"
                    >
                        🎲
                    </div>

                    <DialogTitle class="text-xl font-black">
                        ¿Retirar este juego de BGG?
                    </DialogTitle>

                    <DialogDescription class="pt-2 text-sm leading-6">
                        Vas a retirar
                        <strong class="font-bold text-foreground">
                            {{ bggGameToRemove?.name }}
                        </strong>
                        de la colección sincronizada con BGG.
                    </DialogDescription>
                </DialogHeader>

                <div
                    class="rounded-xl border border-purple-200 bg-purple-50 p-4 text-sm leading-6 text-purple-900 dark:border-purple-900 dark:bg-purple-950/40 dark:text-purple-200"
                >
                    <p class="font-bold">
                        ✓ No perderás los datos del juego.
                    </p>

                    <p class="mt-2">
                        Su ficha, expansiones, partidas registradas
                        y datos de votaciones se conservarán.
                    </p>
                </div>

                <DialogFooter class="mt-3 gap-2 sm:gap-3">
                    <button
                        type="button"
                        class="rounded-xl border border-zinc-300 px-5 py-3 font-semibold transition hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800"
                        @click="bggRemoveDialogOpen = false"
                    >
                        Cancelar
                    </button>

                    <button
                        type="button"
                        class="rounded-xl bg-[#5200FF] px-5 py-3 font-bold text-white transition hover:bg-[#4000cc]"
                        @click="removeSelectedBggGame"
                    >
                        Sí, retirar de BGG
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
        <Dialog v-model:open="bggExpansionRemoveDialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <div
                        class="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-purple-100 text-2xl dark:bg-purple-950"
                        aria-hidden="true"
                    >
                        🧩
                    </div>

                    <DialogTitle class="text-xl font-black">
                        ¿Retirar esta expansión de BGG?
                    </DialogTitle>

                    <DialogDescription class="pt-2 text-sm leading-6">
                        Vas a retirar
                        <strong class="font-bold text-foreground">
                            {{ bggExpansionToRemove?.name }}
                        </strong>
                        de la colección sincronizada con BGG.
                    </DialogDescription>
                </DialogHeader>

                <div
                    class="rounded-xl border border-purple-200 bg-purple-50 p-4 text-sm leading-6 text-purple-900 dark:border-purple-900 dark:bg-purple-950/40 dark:text-purple-200"
                >
                    <p class="font-bold">
                        ✓ No perderás los datos de la expansión.
                    </p>

                    <p class="mt-2">
                        Su ficha, el juego base y el historial
                        de partidas se conservarán.
                    </p>
                </div>

                <DialogFooter class="mt-3 gap-2 sm:gap-3">
                    <button
                        type="button"
                        class="rounded-xl border border-zinc-300 px-5 py-3 font-semibold transition hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800"
                        @click="bggExpansionRemoveDialogOpen = false"
                    >
                        Cancelar
                    </button>

                    <button
                        type="button"
                        class="rounded-xl bg-[#5200FF] px-5 py-3 font-bold text-white transition hover:bg-[#4000cc]"
                        @click="removeSelectedBggExpansion"
                    >
                        Sí, retirar expansión
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
        <Dialog v-model:open="copyLinkDialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <div
                        class="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-purple-100 text-2xl dark:bg-purple-950"
                        aria-hidden="true"
                    >
                        🔗
                    </div>

                    <DialogTitle class="text-xl font-black">
                        Comparte tu colección
                    </DialogTitle>

                    <DialogDescription class="pt-2 leading-6">
                        No hemos podido copiar el enlace automáticamente,
                        pero puedes copiarlo manualmente.
                    </DialogDescription>
                </DialogHeader>

                <div class="mt-3 space-y-3">
                    <label
                        for="catalog-share-url"
                        class="text-sm font-semibold"
                    >
                        Enlace de tu catálogo
                    </label>

                    <input
                        id="catalog-share-url"
                        type="text"
                        readonly
                        :value="fallbackCatalogUrl"
                        class="w-full rounded-xl border border-zinc-300 bg-zinc-50 px-4 py-3 text-sm text-zinc-800 outline-none focus:border-[#5200FF] dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                        @focus="selectCatalogLink"
                    />

                    <p class="text-xs text-muted-foreground">
                        Pulsa sobre el enlace para seleccionarlo
                        y utiliza Ctrl+C para copiarlo.
                    </p>
                </div>

                <DialogFooter class="mt-4">
                    <button
                        type="button"
                        class="rounded-xl bg-[#5200FF] px-6 py-3 font-bold text-white transition hover:bg-[#4000cc]"
                        @click="copyLinkDialogOpen = false"
                    >
                        Entendido
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
