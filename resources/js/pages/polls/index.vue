<script setup lang="ts">
import PlayingCardsFanIcon from '@/components/icons/PlayingCardsFanIcon.vue';
import { CalendarHeart } from '@lucide/vue';
import { UsersRound } from '@lucide/vue';
import {
    computed,
    nextTick,
    ref,
    onBeforeUnmount,
} from 'vue';

import {
    Head,
    Link,
    router,
} from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import ScrollToTopProgress from '@/components/ScrollToTopProgress.vue';
import RollingDicesIcon from '@/components/icons/RollingDicesIcon.vue';

/*
|--------------------------------------------------------------------------
| Tipos
|--------------------------------------------------------------------------
*/

interface Collection {
    id: number;
    name: string;

    is_default?: boolean;
}

type PollStatus = 'draft' | 'open' | 'closed';

interface Poll {
    id: number;
    name: string;
    token: string;

    status: PollStatus;

    participants_count: number;
    candidates_count: number;
    has_registered_play: boolean;

    collections?: Collection[];

    created_at: string | null;
}

interface Filters {
    collection_id:
        number | null;

    status: PollStatus | '';

    search: string;
}

const props = withDefaults(defineProps<{
    polls: Poll[];

    collections?: Collection[];

    filters?: Filters;
}>(), {
    collections: () => [],
    filters: () => ({ collection_id: null, status: '', search: '' }),
});

type StatusFilter = 'all' | PollStatus;

const statusFilter = ref<StatusFilter>(props.filters.status || 'all');

const filteredPolls = computed(() => {
    if (statusFilter.value === 'all') {
        return props.polls;
    }

    return props.polls.filter(poll => poll.status === statusFilter.value);
});

const countByStatus = (status: PollStatus): number => {
    return props.polls.filter(poll => poll.status === status).length;
};

/*
|--------------------------------------------------------------------------
| Filtros
|--------------------------------------------------------------------------
*/

const search =
    ref(
        props.filters.search ?? ''
    );

const collectionId =
    ref<number | null>(
        props.filters
            .collection_id
    );

/*
|--------------------------------------------------------------------------
| Aplicar filtros
|--------------------------------------------------------------------------
*/

const hasActiveFilters = computed(() =>
    Boolean(search.value.trim() || collectionId.value || statusFilter.value !== 'all'),
);

const currentFilters = computed(() => ({
    search: search.value || undefined,
    collection_id: collectionId.value || undefined,
    status: statusFilter.value === 'all' ? undefined : statusFilter.value,
}));

const withPollListReturn = (path: string) => {
    const filters = new URLSearchParams();

    for (const [key, value] of Object.entries(currentFilters.value)) {
        if (value !== undefined) filters.set(key, String(value));
    }

    if (!filters.size) return path;

    return `${path}?back=${encodeURIComponent(`/polls?${filters}`)}`;
};

let searchTimer: ReturnType<typeof setTimeout> | null = null;

const cancelSearch = () => {
    if (searchTimer !== null) {
        clearTimeout(searchTimer);
        searchTimer = null;
    }
};

onBeforeUnmount(cancelSearch);

const applyFilters = () => {
    cancelSearch();

    router.get('/polls', currentFilters.value, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const selectStatus = (status: StatusFilter) => {
    if (statusFilter.value === status) return;

    statusFilter.value = status;
    applyFilters();
};

const scheduleSearch = () => {
    cancelSearch();
    searchTimer = setTimeout(applyFilters, 350);
};

const pollListHeading = ref<HTMLHeadingElement | null>(null);

const clearFilters = async () => {
    search.value = '';
    collectionId.value = null;
    statusFilter.value = 'all';
    applyFilters();

    await nextTick();
    pollListHeading.value?.focus({ preventScroll: true });
};

/*
|--------------------------------------------------------------------------
| Estado
|--------------------------------------------------------------------------
*/

const statusLabel = (
    status: PollStatus
): string => {
    if (status === 'draft') {
        return 'Borrador';
    }

    if (status === 'open') {
        return 'Abierta';
    }

    return 'Cerrada';
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
        return 'Sin fecha';
    }

    return new Intl.DateTimeFormat(
        'es-ES',
        {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        }
    ).format(
        new Date(date)
    );
};
</script>

<template>
    <Head title="Votaciones" />

    <AppLayout>
        <div
            class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6"
        >
            <!-- ================================================== -->
            <!-- CABECERA                                           -->
            <!-- ================================================== -->

            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <h1 ref="pollListHeading" tabindex="-1" class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 sm:text-3xl">
                    Mis votaciones
                </h1>

                <Link
                    :href="withPollListReturn('/polls/create')"
                    class="inline-flex min-h-11 w-full items-center justify-center gap-2.5 rounded-xl bg-[#5200FF] px-4 py-2.5 text-center font-bold text-white shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:bg-[#4000cc] hover:shadow-[0_8px_22px_rgba(82,0,255,0.22)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF] motion-reduce:transform-none sm:w-auto"
                >
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#3C00BB]" aria-hidden="true">
                        <RollingDicesIcon class="size-6 text-[#CCFF00]" />
                    </span>
                    <span>Nueva votación</span>
                </Link>
            </div>

            <div
                class="-mx-4 mt-5 flex gap-2 overflow-x-auto px-4 pt-1 pb-2 sm:-mx-1 sm:mt-8 sm:flex-wrap sm:px-1 sm:pb-1"
            >
                <button
                    type="button"
                    :aria-pressed="statusFilter === 'all'"
                    class="shrink-0 whitespace-nowrap rounded-full px-4 py-2 text-sm font-bold transition-colors duration-200 hover:ring-2 hover:ring-violet-300/70 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF] dark:hover:ring-violet-500/60"
                    :class="
                        statusFilter === 'all'
                            ? 'bg-[#5200FF] text-white'
                            : 'bg-zinc-100 dark:bg-zinc-900'
                    "
                    @click="selectStatus('all')"
                >
                    Todas
                    {{ polls.length }}
                </button>

                <button
                    type="button"
                    :aria-pressed="statusFilter === 'draft'"
                    class="shrink-0 whitespace-nowrap rounded-full px-4 py-2 text-sm font-bold transition-colors duration-200 hover:ring-2 hover:ring-violet-300/70 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF] dark:hover:ring-violet-500/60"
                    :class="
                        statusFilter === 'draft'
                            ? 'bg-[#5200FF] text-white'
                            : 'bg-zinc-100 dark:bg-zinc-900'
                    "
                    @click="selectStatus('draft')"
                >
                    Borradores
                    {{ countByStatus('draft') }}
                </button>

                <button
                    type="button"
                    :aria-pressed="statusFilter === 'open'"
                    class="shrink-0 whitespace-nowrap rounded-full px-4 py-2 text-sm font-bold transition-colors duration-200 hover:ring-2 hover:ring-violet-300/70 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF] dark:hover:ring-violet-500/60"
                    :class="
                        statusFilter === 'open'
                            ? 'bg-[#CCFF00] text-black'
                            : 'bg-zinc-100 dark:bg-zinc-900'
                    "
                    @click="selectStatus('open')"
                >
                    Abiertas
                    {{ countByStatus('open') }}
                </button>

                <button
                    type="button"
                    :aria-pressed="statusFilter === 'closed'"
                    class="shrink-0 whitespace-nowrap rounded-full px-4 py-2 text-sm font-bold transition-colors duration-200 hover:ring-2 hover:ring-violet-300/70 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF] dark:hover:ring-violet-500/60"
                    :class="
                        statusFilter === 'closed'
                            ? 'bg-[#5200FF] text-white'
                            : 'bg-zinc-100 dark:bg-zinc-900'
                    "
                    @click="selectStatus('closed')"
                >
                    Cerradas
                    {{ countByStatus('closed') }}
                </button>
            </div>

            <!-- ================================================== -->
            <!-- FILTROS                                            -->
            <!-- ================================================== -->

            <section
                class="mt-4 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950 sm:mt-6 sm:p-5"
            >
                <div
                    class="grid gap-4 md:grid-cols-2"
                >
                    <!-- BUSCAR -->

                    <div>
                        <label
                            for="poll-search"
                            class="text-sm font-bold text-zinc-800 dark:text-zinc-200"
                        >
                            Buscar
                        </label>

                        <input
                            id="poll-search"
                            v-model="search"
                            type="search"
                            class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 outline-none transition-colors placeholder:text-zinc-400 focus:border-[#5200FF] focus:ring-2 focus:ring-violet-500/15 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:placeholder:text-zinc-500"
                            placeholder="Nombre de la votación..."
                            @input="scheduleSearch"
                        />
                    </div>

                    <!-- COLECCIÓN -->

                    <div>
                        <label
                            for="poll-collection"
                            class="text-sm font-bold text-zinc-800 dark:text-zinc-200"
                        >
                            Colección
                        </label>

                        <select
                            id="poll-collection"
                            @change="applyFilters"
                            v-model="
                                collectionId
                            "
                            class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 outline-none transition-colors focus:border-[#5200FF] focus:ring-2 focus:ring-violet-500/15 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                        >
                            <option
                                :value="null"
                            >
                                Todas las colecciones
                            </option>

                            <option
                                v-for="collection in collections"
                                :key="
                                    collection.id
                                "
                                :value="
                                    collection.id
                                "
                            >
                                {{
                                    collection.name
                                }}
                            </option>
                        </select>
                    </div>
                </div>

                <div
                    class="mt-4 flex items-center justify-between border-t border-zinc-200 pt-4 dark:border-zinc-800"
                >
                    <p
                        role="status"
                        class="text-sm text-zinc-500 dark:text-zinc-400"
                    >
                        {{
                            filteredPolls.length
                        }}

                        {{
                            filteredPolls.length ===
                            1
                                ? 'votación'
                                : 'votaciones'
                        }}
                    </p>

                    <button
                        v-if="hasActiveFilters"
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
            <!-- VOTACIONES                                         -->
            <!-- ================================================== -->

            <div
                v-if="filteredPolls.length"
                class="mt-6 space-y-4"
            >
                <article
                    v-for="poll in filteredPolls"
                    :key="poll.id"
                    class="group rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm transition-all duration-200 motion-safe:hover:-translate-y-0.5 hover:border-violet-400 hover:shadow-lg hover:shadow-violet-500/10 focus-within:ring-2 focus-within:ring-violet-500/30 dark:border-zinc-800 dark:bg-zinc-950 dark:hover:border-violet-500"
                >
                    <div
                        class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div>
                            <div
                                class="flex flex-wrap items-center gap-2"
                            >
                                <h2 class="min-w-0 text-xl font-black">
                                    <Link
                                        :href="withPollListReturn(`/polls/${poll.id}`)"
                                        class="inline-block max-w-full break-words rounded text-zinc-900 transition-colors duration-200 hover:text-[#5200FF] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF] dark:text-zinc-100 dark:hover:text-violet-300"
                                    >
                                        {{ poll.name }}
                                    </Link>
                                </h2>

                                <span
                                    class="rounded-full px-3 py-1 text-xs font-black"
                                    :class="{
                                        'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300':
                                            poll.status ===
                                            'draft',

                                        'bg-[#CCFF00] text-black':
                                            poll.status ===
                                            'open',

                                        'bg-purple-100 text-purple-700 dark:bg-purple-950/40 dark:text-purple-300':
                                            poll.status ===
                                            'closed',
                                    }"
                                >
                                    {{
                                        statusLabel(
                                            poll.status
                                        )
                                    }}
                                </span>

                                <span
                                    v-if="
                                        poll.has_registered_play
                                    "
                                    class="rounded-full bg-green-100 px-3 py-1 text-xs font-bold text-green-700 dark:bg-green-950/40 dark:text-green-300"
                                >
                                    ✓ Partida registrada
                                </span>
                            </div>

                            <div
                                class="mt-3 flex flex-wrap gap-4 text-sm text-zinc-500"
                            >
                                <span>
                                    <UsersRound class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-[#6D28D9] dark:text-[#C4B5FD]" :stroke-width="2.2" role="img" aria-label="Jugadores" />
                                    {{
                                        poll.participants_count
                                    }}
                                    jugadores
                                </span>

                                <span class="inline-flex items-center gap-1.5">
                                    <PlayingCardsFanIcon class="size-4 shrink-0 text-violet-600 dark:text-[#BBA6FF]" aria-hidden="true" />
                                    {{
                                        poll.candidates_count
                                    }}
                                    {{
                                        poll.candidates_count ===
                                        1
                                            ? 'juego'
                                            : 'juegos'
                                    }}
                                </span>

                                <span class="inline-flex items-center gap-1.5">
                                    <CalendarHeart class="size-4 shrink-0 text-lime-700 dark:text-[#CCFF00]" :stroke-width="2.2" aria-hidden="true" />
                                    {{
                                        formatDate(
                                            poll.created_at
                                        )
                                    }}
                                </span>
                            </div>
                        </div>

                        <!-- ACCIONES -->

                        <div
                            class="flex flex-wrap gap-2"
                        >
                            <Link
                                :href="withPollListReturn(`/polls/${poll.id}`)"
                                class="inline-flex min-h-11 items-center justify-center rounded-xl bg-[#5200FF] px-4 py-2.5 text-center text-sm font-bold text-white shadow-sm transition-all duration-200 hover:bg-[#4000cc] hover:shadow-md hover:shadow-violet-500/20 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF]"
                            >
                                {{
                                    poll.status ===
                                    'closed'
                                        ? 'Ver resultados'
                                        : 'Ver votación'
                                }}
                            </Link>

                            <Link
                                v-if="poll.status === 'open'"
                                :href="withPollListReturn(`/polls/${poll.id}`)"
                                class="inline-flex min-h-11 items-center justify-center rounded-xl border border-zinc-300 px-4 py-2.5 text-center text-sm font-bold transition-colors hover:border-violet-400 hover:bg-violet-50 hover:text-[#5200FF] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF] dark:border-zinc-700 dark:hover:bg-violet-950/30 dark:hover:text-violet-300"
                            >
                                Ver enlaces personales
                            </Link>

                            <Link
                                v-if="
                                    poll.status ===
                                        'closed' &&
                                    !poll.has_registered_play
                                "
                                :href="withPollListReturn(`/polls/${poll.id}/register-play`)"
                                class="inline-flex min-h-11 items-center justify-center rounded-xl bg-[#CCFF00] px-4 py-2.5 text-center text-sm font-black text-black transition-all duration-200 hover:bg-lime-300 hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF]"
                            >
                                <PlayingCardsFanIcon class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-violet-600 dark:text-violet-300" aria-hidden="true" /> Registrar partida
                            </Link>
                        </div>
                    </div>
                </article>
            </div>

            <!-- SIN RESULTADOS -->

            <div
                v-if="filteredPolls.length === 0"
                class="mt-8 rounded-2xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700"
            >
                <p
                    class="text-lg font-black"
                >
                    {{ hasActiveFilters
                        ? 'No hay votaciones que coincidan'
                        : 'Todavía no hay votaciones' }}
                </p>

                <p
                    class="mt-2 text-sm text-zinc-500"
                >
                    {{ hasActiveFilters
                        ? 'Prueba con otras palabras o elimina alguno de los filtros aplicados.'
                        : 'Crea tu primera votación para elegir a qué jugar.' }}
                </p>
            </div>
            <ScrollToTopProgress />
        </div>
    </AppLayout>
</template>
