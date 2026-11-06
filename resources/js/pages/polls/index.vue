<script setup lang="ts">
import {
    computed,
    ref,
    watch,
} from 'vue';

import {
    Head,
    Link,
    router,
} from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';

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

const applyFilters = () => {
    router.get(
        '/polls',
        {
            search:
                search.value ||
                undefined,

            collection_id:
                collectionId.value ||
                undefined,

            status:
                statusFilter.value === 'all'
                    ? undefined
                    : statusFilter.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        }
    );
};

/*
 * Colección: actualización inmediata.
 */
watch(
    collectionId,
    () => {
        applyFilters();
    }
);

/*
 * Buscador:
 * no enviamos una petición por cada tecla.
 */
let searchTimer:
    ReturnType<
        typeof setTimeout
    > | null = null;

watch(
    search,
    () => {
        if (searchTimer) {
            clearTimeout(
                searchTimer
            );
        }

        searchTimer =
            setTimeout(
                () => {
                    applyFilters();
                },
                350
            );
    }
);

/*
|--------------------------------------------------------------------------
| Limpiar
|--------------------------------------------------------------------------
*/

const clearFilters = () => {
    search.value = '';

    collectionId.value =
        null;

    statusFilter.value = 'all';

    router.get(
        '/polls',
        {},
        {
            preserveScroll: true,
            replace: true,
        }
    );
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

            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <p
                        class="font-semibold text-[#5200FF]"
                    >
                        SESIONES
                    </p>

                    <h1
                        class="text-2xl font-black text-zinc-900 dark:text-zinc-100 sm:text-3xl"
                    >
                        Mis votaciones
                    </h1>

                    <p
                        class="mt-2 text-sm text-zinc-500 dark:text-zinc-400"
                    >
                        Crea una sesión, comparte la
                        votación y registra después la
                        partida jugada.
                    </p>
                </div>

                <Link
                    href="/polls/create"
                    class="w-full rounded-xl bg-[#5200FF] px-5 py-3 text-center font-bold text-white sm:w-auto"
                >
                    + Nueva votación
                </Link>
            </div>

            <div
                class="-mx-4 mt-5 flex gap-2 overflow-x-auto px-4 pb-2 sm:mx-0 sm:mt-8 sm:flex-wrap sm:px-0 sm:pb-0"
            >
                <button
                    type="button"
                    :aria-pressed="statusFilter === 'all'"
                    class="shrink-0 whitespace-nowrap rounded-full px-4 py-2 text-sm font-bold"
                    :class="
                        statusFilter === 'all'
                            ? 'bg-[#5200FF] text-white'
                            : 'bg-zinc-100 dark:bg-zinc-900'
                    "
                    @click="
                        statusFilter = 'all'
                    "
                >
                    Todas
                    {{ polls.length }}
                </button>

                <button
                    type="button"
                    :aria-pressed="statusFilter === 'draft'"
                    class="shrink-0 whitespace-nowrap rounded-full px-4 py-2 text-sm font-bold"
                    :class="
                        statusFilter === 'draft'
                            ? 'bg-[#5200FF] text-white'
                            : 'bg-zinc-100 dark:bg-zinc-900'
                    "
                    @click="
                        statusFilter = 'draft'
                    "
                >
                    Borradores
                    {{ countByStatus('draft') }}
                </button>

                <button
                    type="button"
                    :aria-pressed="statusFilter === 'open'"
                    class="shrink-0 whitespace-nowrap rounded-full px-4 py-2 text-sm font-bold"
                    :class="
                        statusFilter === 'open'
                            ? 'bg-[#CCFF00] text-black'
                            : 'bg-zinc-100 dark:bg-zinc-900'
                    "
                    @click="
                        statusFilter = 'open'
                    "
                >
                    Abiertas
                    {{ countByStatus('open') }}
                </button>

                <button
                    type="button"
                    :aria-pressed="statusFilter === 'closed'"
                    class="shrink-0 whitespace-nowrap rounded-full px-4 py-2 text-sm font-bold"
                    :class="
                        statusFilter === 'closed'
                            ? 'bg-[#5200FF] text-white'
                            : 'bg-zinc-100 dark:bg-zinc-900'
                    "
                    @click="
                        statusFilter = 'closed'
                    "
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
                            class="text-sm font-bold text-zinc-800 dark:text-zinc-200"
                        >
                            Buscar
                        </label>

                        <input
                            v-model="search"
                            type="search"
                            class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 placeholder:text-zinc-400 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:placeholder:text-zinc-500"
                            placeholder="Nombre de la votación..."
                        />
                    </div>

                    <!-- COLECCIÓN -->

                    <div>
                        <label
                            class="text-sm font-bold text-zinc-800 dark:text-zinc-200"
                        >
                            Colección
                        </label>

                        <select
                            v-model="
                                collectionId
                            "
                            class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
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
                        v-if="
                            search ||
                            collectionId ||
                            statusFilter !== 'all'
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
            <!-- VOTACIONES                                         -->
            <!-- ================================================== -->

            <div
                v-if="filteredPolls.length"
                class="mt-6 space-y-4"
            >
                <article
                    v-for="poll in filteredPolls"
                    :key="poll.id"
                    class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <div
                        class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div>
                            <div
                                class="flex flex-wrap items-center gap-2"
                            >
                                <h2
                                    class="text-xl font-black"
                                >
                                    {{ poll.name }}
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
                                    👥
                                    {{
                                        poll.participants_count
                                    }}
                                    jugadores
                                </span>

                                <span>
                                    🎲
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

                                <span>
                                    📅
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
                                :href="
                                    `/polls/${poll.id}`
                                "
                                class="rounded-xl bg-[#5200FF] px-4 py-2.5 text-sm font-bold text-white"
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
                                :href="`/polls/${poll.id}`"
                                class="rounded-xl border border-zinc-300 px-4 py-2.5 text-sm font-bold dark:border-zinc-700"
                            >
                                Ver enlaces personales
                            </Link>

                            <Link
                                v-if="
                                    poll.status ===
                                        'closed' &&
                                    !poll.has_registered_play
                                "
                                :href="
                                    `/polls/${poll.id}/register-play`
                                "
                                class="rounded-xl bg-[#CCFF00] px-4 py-2.5 text-sm font-black text-black"
                            >
                                🎲 Registrar partida
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
                    No hay votaciones aquí
                </p>

                <p
                    class="mt-2 text-sm text-zinc-500"
                >
                    Cambia el filtro o crea una
                    nueva sesión.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
