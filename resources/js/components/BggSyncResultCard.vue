<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import { computed } from 'vue';

type Kind = 'games' | 'expansions' | 'pending';

interface BggItem {
    id?: number;
    bgg_id?: number;
    game_id?: number;
    name?: string;
    base_game?: string;
    reason?: string;
}

const props = defineProps<{
    kind: Kind;
    count: number;
    entries?: BggItem[];
    hasReport: boolean;
}>();

const titles: Record<Kind, string> = {
    games: 'Juegos añadidos',
    expansions: 'Expansiones añadidas',
    pending: 'Expansiones pendientes',
};

const title = computed(() => titles[props.kind]);
const items = computed(() => props.entries ?? []);

const advice = (reason: string): string => {
    if (
        reason.includes('El juego base no pertenece') ||
        reason.includes('No se encontró un juego base')
    ) {
        return 'Si todavía tienes la expansión, revisa que también posees su juego base en BGG. Si ya no tienes la expansión, retírala de BGG. Después vuelve a sincronizar.';
    }

    if (reason.includes('varios juegos base')) {
        return 'BGG relaciona esta expansión con varios juegos base. Revisa cuál corresponde antes de volver a importar.';
    }

    if (reason.includes('no devolvió detalles')) {
        return 'BGG no ha facilitado todos los datos. Puedes volver a sincronizar más adelante.';
    }

    return 'Revisa la expansión y su juego base antes de intentar otra sincronización.';
};
</script>

<template>
    <details
        class="group min-w-0 rounded-xl border border-zinc-200 bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-900"
    >
        <summary
            class="flex min-w-0 cursor-pointer list-none items-center justify-between gap-2 p-4 focus-visible:rounded-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF] dark:focus-visible:outline-[#CCFF00] [&::-webkit-details-marker]:hidden"
        >
            <span class="min-w-0">
                <span class="block text-xs font-medium leading-5 text-zinc-600 dark:text-zinc-400">
                    {{ title }}
                </span>
                <span class="mt-1 block text-2xl font-bold text-zinc-900 dark:text-white">
                    {{ count }}
                </span>
            </span>
            <ChevronDown
                class="size-5 shrink-0 text-zinc-500 transition-transform group-open:rotate-180"
                aria-hidden="true"
            />
        </summary>

        <div class="border-t border-zinc-200 px-4 pb-4 pt-3 dark:border-zinc-700">
            <p
                v-if="!hasReport && count > 0"
                class="text-sm leading-6 text-zinc-600 dark:text-zinc-300"
            >
                Esta sincronización es anterior al registro detallado.
                Se conservó la cantidad, pero no los nombres.
                El desglose estará disponible en la próxima sincronización.
            </p>

            <p
                v-else-if="!items.length"
                class="text-sm text-zinc-600 dark:text-zinc-300"
            >
                No hay elementos que mostrar.
            </p>

            <ul v-else class="max-h-80 space-y-3 overflow-y-auto pr-1">
                <li
                    v-for="(item, index) in items"
                    :key="`${item.bgg_id ?? 'item'}-${index}`"
                    class="min-w-0 border-b border-zinc-200 pb-3 last:border-0 last:pb-0 dark:border-zinc-700"
                >
                    <Link
                        v-if="kind === 'games' && item.id"
                        :href="`/games/${item.id}`"
                        class="font-semibold text-violet-700 underline underline-offset-2 dark:text-violet-300"
                    >
                        {{ item.name || 'Juego sin nombre' }}
                    </Link>

                    <Link
                        v-else-if="kind === 'expansions' && item.game_id"
                        :href="`/games/${item.game_id}`"
                        class="font-semibold text-violet-700 underline underline-offset-2 dark:text-violet-300"
                    >
                        {{ item.name || 'Expansión sin nombre' }}
                    </Link>

                    <p v-else class="wrap-break-word font-semibold">
                        {{ item.name || 'Elemento sin nombre' }}
                    </p>

                    <p v-if="kind === 'expansions' && item.base_game" class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        Juego base: {{ item.base_game }}
                    </p>

                    <template v-if="kind === 'pending'">
                        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                            Motivo: {{ item.reason || 'No especificado' }}
                        </p>
                        <p class="mt-2 text-sm leading-6 text-amber-800 dark:text-amber-200">
                            {{ advice(item.reason ?? '') }}
                        </p>
                    </template>
                </li>
            </ul>

            <p
                v-if="kind === 'pending' && count > 0"
                class="mt-3 text-xs leading-5 text-zinc-500 dark:text-zinc-400"
            >
                «Consultar BGG» solo muestra una vista previa.
                Para actualizar este resultado, utiliza
                «Importar novedades de BGG».
            </p>
        </div>
    </details>
</template>
