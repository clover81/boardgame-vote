<script setup lang="ts">
import { Link as ChainIcon } from '@lucide/vue';
import { ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';

interface BggExpansion {
    bgg_id: number;
    name: string;
    year_published: number | null;
    image_url: string | null;
    thumbnail_url: string | null;
    bgg_url: string;
    already_imported: boolean;
}

const props = withDefaults(defineProps<{
    gameId: number;
    bggId: number | null;
    disabled?: boolean;
}>(), { disabled: false });

const expansions = ref<BggExpansion[]>([]);
const loading = ref(false);
const loaded = ref(false);
const error = ref('');
const importingId = ref<number | null>(null);
const brokenImages = ref<number[]>([]);

watch([() => props.gameId, () => props.bggId], () => {
    expansions.value = [];
    loaded.value = false;
    error.value = '';
    brokenImages.value = [];
});

const search = async () => {
    if (!props.bggId || loading.value || importingId.value !== null || props.disabled) return;

    loading.value = true;
    error.value = '';
    try {
        const response = await fetch(`/games/${props.gameId}/bgg-expansions`, {
            headers: { Accept: 'application/json' },
        });
        const data = await response.json();
        if (!response.ok) {
            expansions.value = [];
            loaded.value = false;
            error.value = data?.message ?? 'No se han podido consultar las expansiones.';
            return;
        }
        expansions.value = data.expansions ?? [];
        brokenImages.value = [];
        loaded.value = true;
    } catch {
        expansions.value = [];
        loaded.value = false;
        error.value = 'No se ha podido conectar con BoardGameGeek.';
    } finally {
        loading.value = false;
    }
};

const importExpansion = (expansion: BggExpansion) => {
    if (expansion.already_imported || loading.value || importingId.value !== null || props.disabled) return;

    importingId.value = expansion.bgg_id;
    error.value = '';
    router.post(`/games/${props.gameId}/bgg-expansions/${expansion.bgg_id}`, {}, {
        preserveScroll: true,
        onSuccess: () => {
            expansion.already_imported = true;
        },
        onError: (errors) => {
            error.value = String(errors.bgg_expansion ?? 'No se ha podido importar la expansión.');
        },
        onFinish: () => {
            importingId.value = null;
        },
    });
};

const markImageBroken = (id: number) => {
    if (!brokenImages.value.includes(id)) brokenImages.value.push(id);
};
</script>

<template>
    <section
        aria-labelledby="bgg-expansions-title"
        class="rounded-2xl border border-zinc-200 bg-white p-5 sm:p-6 dark:border-zinc-800 dark:bg-zinc-950"
    >
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-xs font-black uppercase tracking-wider text-[#5200FF] dark:text-[#CCFF00]">
                    BOARDGAMEGEEK
                </p>
                <h3 id="bgg-expansions-title" class="mt-1 text-xl font-black">Buscar expansiones</h3>
                <p class="mt-2 max-w-2xl text-sm text-zinc-500 dark:text-zinc-400">
                    Consulta las expansiones relacionadas con este juego en BGG e importa solo las que quieras.
                </p>
            </div>
            <button
                v-if="bggId"
                type="button"
                :disabled="loading || importingId !== null || disabled"
                class="w-full shrink-0 rounded-xl bg-[#5200FF] px-5 py-3 font-bold text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
                @click="search"
            >
                {{ loading ? 'Buscando…' : loaded ? 'Actualizar resultados' : 'Buscar expansiones en BGG' }}
            </button>
        </div>

        <div v-if="!bggId" class="mt-5 rounded-xl bg-zinc-100 p-4 text-sm text-zinc-600 dark:bg-zinc-900 dark:text-zinc-300">
            Este juego todavía no está vinculado con BoardGameGeek.
            <Link :href="`/games/${gameId}/edit#bgg-link-title`" class="ml-1 font-bold text-[#5200FF] underline dark:text-[#CCFF00]">
                Vincular juego con BGG
            </Link>
        </div>

        <p v-if="error" role="alert" class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-700 dark:border-red-900 dark:bg-red-950/20 dark:text-red-300">
            {{ error }}
        </p>

        <p
            v-if="loaded && !loading && !expansions.length && !error"
            role="status"
            class="mt-5 rounded-xl border border-dashed border-zinc-300 p-6 text-center text-sm text-zinc-500 dark:border-zinc-700"
        >
            BGG no muestra expansiones vinculadas a este juego.
        </p>

        <div v-if="expansions.length" class="mt-6 grid gap-4 md:grid-cols-2">
            <article
                v-for="expansion in expansions"
                :key="expansion.bgg_id"
                class="flex min-w-0 gap-3 rounded-2xl border border-zinc-200 p-3 sm:gap-4 sm:p-4 dark:border-zinc-800"
            >
                <div class="h-28 w-20 shrink-0 overflow-hidden rounded-xl bg-zinc-100 sm:h-32 sm:w-24 dark:bg-zinc-900">
                    <img
                        v-if="expansion.image_url && !brokenImages.includes(expansion.bgg_id)"
                        :src="expansion.image_url"
                        :alt="expansion.name"
                        class="h-full w-full object-contain p-1"
                        @error="markImageBroken(expansion.bgg_id)"
                    />
                    <div v-else class="flex h-full items-center justify-center text-3xl" aria-label="Sin portada">🧩</div>
                </div>
                <div class="min-w-0 flex-1">
                    <h4 class="break-words font-black leading-tight">{{ expansion.name }}</h4>
                    <p class="mt-1 text-xs text-zinc-500">
                        BGG #{{ expansion.bgg_id }}
                        <template v-if="expansion.year_published"> · {{ expansion.year_published }}</template>
                    </p>
                    <a
                        :href="expansion.bgg_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="mt-2 inline-block text-xs font-bold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
                    >
                        Ver en BGG <ChainIcon class="ml-1 inline-block h-4 w-4 shrink-0 align-[-0.15em]" aria-hidden="true" />
                    </a>
                    <div class="mt-4">
                        <span
                            v-if="expansion.already_imported"
                            class="inline-flex rounded-lg bg-green-100 px-3 py-2 text-sm font-bold text-green-700 dark:bg-green-950/30 dark:text-green-300"
                        >
                            ✓ Ya importada
                        </span>
                        <button
                            v-else
                            type="button"
                            :disabled="loading || importingId !== null || disabled"
                            class="rounded-lg bg-[#CCFF00] px-3 py-2 text-sm font-black text-black transition hover:opacity-80 disabled:cursor-not-allowed disabled:opacity-40 sm:px-4"
                            @click="importExpansion(expansion)"
                        >
                            {{ importingId === expansion.bgg_id ? 'Importando…' : 'Importar expansión' }}
                        </button>
                    </div>
                </div>
            </article>
        </div>
    </section>
</template>
