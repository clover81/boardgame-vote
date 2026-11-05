
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';

interface Player {
    id: number;
    name: string;
    is_active: boolean;
}

defineProps<{
    players: Player[];
}>();

const togglePlayer = (player: Player) => {
    router.patch(
        `/players/${player.id}/toggle`,
        {},
        { preserveScroll: true }
    );
};
</script>

<template>
    <Head title="Jugadores" />

    <AppLayout>
        <div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">

            <!-- Cabecera -->
            <div
                class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <p class="font-semibold text-[#5200FF]">
                        JUGADORES
                    </p>

                    <h1 class="text-3xl font-bold">
                        Jugadores habituales
                    </h1>

                    <p class="mt-2 text-zinc-500">
                        Personas que suelen participar en tus partidas.
                    </p>
                </div>

                <Link
                    href="/players/create"
                    class="inline-flex items-center justify-center rounded-xl bg-[#5200FF] px-5 py-3 font-semibold text-white transition hover:opacity-90"
                >
                    + Añadir jugador
                </Link>
            </div>

            <!-- Listado de jugadores -->
            <div
                v-if="players.length > 0"
                class="space-y-3"
            >
                <div
                    v-for="player in players"
                    :key="player.id"
                    class="flex items-center justify-between gap-4 rounded-2xl border bg-white p-5 dark:bg-zinc-950"
                >
                    <div>
                        <h2 class="text-lg font-bold">
                            {{ player.name }}
                        </h2>

                        <p
                            class="mt-1 text-sm"
                            :class="
                                player.is_active
                                    ? 'text-green-600'
                                    : 'text-zinc-400'
                            "
                        >
                            {{
                                player.is_active
                                    ? '● Activo'
                                    : '○ Inactivo'
                            }}
                        </p>

                        <Link
                            :href="`/players/${player.id}`"
                            class="mt-3 inline-block font-bold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
                        >
                            Ver estadísticas →
                        </Link>
                    </div>

                    <button
                        type="button"
                        class="rounded-xl border px-4 py-2 text-sm font-semibold transition hover:bg-zinc-100 dark:hover:bg-zinc-800"
                        @click="togglePlayer(player)"
                    >
                        {{
                            player.is_active
                                ? 'Desactivar'
                                : 'Activar'
                        }}
                    </button>
                </div>
            </div>

            <!-- Sin jugadores -->
            <div
                v-else
                class="rounded-2xl border border-dashed p-10 text-center text-zinc-500"
            >
                Todavía no hay jugadores registrados.
            </div>

        </div>
    </AppLayout>
</template>
