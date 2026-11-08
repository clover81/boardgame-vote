<script setup lang="ts">
import { BuildingComplexPlus } from '@lucide/vue';
import PlayingCardsFanIcon from '@/components/icons/PlayingCardsFanIcon.vue';
import { UsersRound } from '@lucide/vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';

interface Summary {
    open_polls_count: number;
    pending_plays_count: number;
}

interface OpenPoll {
    id: number;
    name: string;

    participants_count: number;
    candidates_count: number;
}

interface PendingPlay {
    id: number;
    name: string;
}

interface Player {
    id: number;
    name: string;
}

interface RecentPlay {
    id: number;
    played_at: string;

    game: {
        id: number;
        name: string;
        image_url: string | null;
    };

    expansion: {
        id: number;
        name: string;
    } | null;

    players: Player[];
}

defineProps<{
    summary: Summary;

    openPolls: OpenPoll[];

    pendingPlays: PendingPlay[];

    recentPlays: RecentPlay[];
}>();

const formatDate = (
    date: string
): string => {
    return new Intl.DateTimeFormat(
        'es-ES',
        {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        }
    ).format(
        new Date(
            `${date}T12:00:00`
        )
    );
};
</script>

<template>
    <Head title="Inicio" />

    <AppLayout>
        <div
            class="mx-auto w-full max-w-6xl px-4 py-6 sm:px-6"
        >
            <!-- CABECERA -->

            <div>
                <p
                    class="font-semibold text-[#5200FF] dark:text-[#CCFF00]"
                >
                    Juegos de mesa
                </p>

                <h1
                    class="mt-1 text-3xl font-black"
                >
                    ¿A qué jugamos?
                </h1>

                <p
                    class="mt-2 text-zinc-500 dark:text-zinc-400"
                >
                    Gestiona tu colección,
                    organiza una votación y
                    registra lo que finalmente
                    habéis jugado.
                </p>
            </div>

            <!-- ACCIONES PRINCIPALES -->

            <div
                class="mt-8 grid gap-4 sm:grid-cols-3"
            >
                <Link
                    href="/polls/create"
                    class="rounded-2xl bg-[#5200FF] p-6 text-white transition hover:opacity-90"
                >
                    <p
                        class="text-3xl"
                    >
                        🗳️
                    </p>

                    <p
                        class="mt-3 text-lg font-black"
                    >
                        Nueva votación
                    </p>

                    <p
                        class="mt-1 text-sm text-violet-100"
                    >
                        Elige jugadores y
                        candidatos.
                    </p>
                </Link>

                <Link
                    href="/games"
                    class="rounded-2xl border border-zinc-200 bg-white p-6 transition hover:border-[#5200FF] dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <p
                        class="text-3xl"
                    >
                        <PlayingCardsFanIcon class="mr-1 inline-block size-8 shrink-0 align-[-0.14em] text-violet-600 dark:text-violet-300" aria-hidden="true" />
                    </p>

                    <p
                        class="mt-3 text-lg font-black"
                    >
                        Mi colección
                    </p>

                    <p
                        class="mt-1 text-sm text-zinc-500"
                    >
                        Consulta y gestiona
                        tus juegos.
                    </p>
                </Link>

                <Link
                    href="/plays/create"
                    class="rounded-2xl border border-zinc-200 bg-white p-6 transition hover:border-[#5200FF] dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <p
                        class="text-3xl"
                    >
                        <PlayingCardsFanIcon class="inline-block size-8 shrink-0 align-[-0.15em] text-violet-600 dark:text-violet-300" aria-hidden="true" />
                    </p>

                    <p
                        class="mt-3 text-lg font-black"
                    >
                        Registrar partida
                    </p>

                    <p
                        class="mt-1 text-sm text-zinc-500"
                    >
                        Anota lo que habéis
                        jugado.
                    </p>
                </Link>
            </div>

            <!-- RESUMEN -->

            <div class="mt-8 grid grid-cols-2 gap-3">
                <Link
                    href="/polls"
                    class="rounded-2xl bg-zinc-100 p-5 transition hover:ring-2 hover:ring-[#5200FF]/20 dark:bg-zinc-900"
                >
                    <p class="text-3xl font-black text-[#5200FF] dark:text-[#CCFF00]">
                        {{ summary.open_polls_count }}
                    </p>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                        Votaciones abiertas
                    </p>
                </Link>

                <Link
                    href="/polls"
                    class="rounded-2xl bg-zinc-100 p-5 transition hover:ring-2 hover:ring-[#5200FF]/20 dark:bg-zinc-900"
                >
                    <p class="text-3xl font-black text-[#FF6B00]">
                        {{ summary.pending_plays_count }}
                    </p>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                        Pendientes de registrar
                    </p>
                </Link>
            </div>

            <!-- PENDIENTES -->

            <section
                v-if="
                    pendingPlays.length
                "
                class="mt-10"
            >
                <div
                    class="flex items-center justify-between gap-3"
                >
                    <div>
                        <h2
                            class="text-xl font-black"
                        >
                            Pendientes
                        </h2>

                        <p
                            class="mt-1 text-sm text-zinc-500"
                        >
                            Votaciones cerradas
                            sin partida registrada.
                        </p>
                    </div>

                    <Link
                        href="/polls"
                        class="text-sm font-bold text-[#5200FF] dark:text-[#CCFF00]"
                    >
                        Ver todas →
                    </Link>
                </div>

                <div
                    class="mt-4 space-y-3"
                >
                    <article
                        v-for="
                            poll in
                            pendingPlays
                        "
                        :key="poll.id"
                        class="flex flex-col gap-3 rounded-2xl border border-orange-200 bg-orange-50 p-5 sm:flex-row sm:items-center sm:justify-between dark:border-orange-900 dark:bg-orange-950/20"
                    >
                        <div>
                            <p
                                class="font-black"
                            >
                                {{
                                    poll.name
                                }}
                            </p>

                            <p
                                class="mt-1 text-sm text-zinc-500"
                            >
                                La votación ha
                                terminado.
                            </p>
                        </div>

                        <Link
                            :href="
                                `/polls/${poll.id}/register-play`
                            "
                            class="rounded-xl bg-[#CCFF00] px-4 py-2.5 text-center text-sm font-black text-black"
                        >
                            <PlayingCardsFanIcon class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-violet-600 dark:text-violet-300" aria-hidden="true" /> Registrar partida
                        </Link>
                    </article>
                </div>
            </section>

            <!-- DOS COLUMNAS -->

            <div
                class="mt-10 grid gap-8 lg:grid-cols-2"
            >
                <!-- ABIERTAS -->

                <section>
                    <div
                        class="flex items-center justify-between"
                    >
                        <h2
                            class="text-xl font-black"
                        >
                            Votaciones abiertas
                        </h2>

                        <Link
                            href="/polls"
                            class="text-sm font-bold text-[#5200FF] dark:text-[#CCFF00]"
                        >
                            Ver todas →
                        </Link>
                    </div>

                    <div
                        v-if="
                            openPolls.length
                        "
                        class="mt-4 space-y-3"
                    >
                        <article
                            v-for="
                                poll in
                                openPolls
                            "
                            :key="poll.id"
                            class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-950"
                        >
                            <div
                                class="flex items-start justify-between gap-3"
                            >
                                <div>
                                    <p
                                        class="font-black"
                                    >
                                        {{
                                            poll.name
                                        }}
                                    </p>

                                    <p
                                        class="mt-2 text-sm text-zinc-500"
                                    >
                                        <UsersRound class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-[#6D28D9] dark:text-[#C4B5FD]" :stroke-width="2.2" role="img" aria-label="Jugadores" />
                                        {{
                                            poll.participants_count
                                        }}

                                        · <PlayingCardsFanIcon class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-violet-600 dark:text-violet-300" aria-hidden="true" />
                                        {{
                                            poll.candidates_count
                                        }}
                                    </p>
                                </div>

                                <span
                                    class="rounded-full bg-[#CCFF00] px-2.5 py-1 text-xs font-black text-black"
                                >
                                    Abierta
                                </span>
                            </div>

                            <div
                                class="mt-4 flex flex-wrap gap-2"
                            >
                                <Link
                                    :href="
                                        `/polls/${poll.id}`
                                    "
                                    class="rounded-lg bg-[#5200FF] px-3 py-2 text-sm font-bold text-white"
                                >
                                    Gestionar
                                </Link>
                            </div>
                        </article>
                    </div>

                    <div
                        v-else
                        class="mt-4 rounded-2xl border border-dashed border-zinc-300 p-6 text-center text-sm text-zinc-500 dark:border-zinc-700"
                    >
                        No tienes votaciones
                        abiertas.
                    </div>
                </section>

                <!-- PARTIDAS RECIENTES -->

                <section>
                    <div
                        class="flex items-center justify-between"
                    >
                        <h2
                            class="text-xl font-black"
                        >
                            Últimas partidas
                        </h2>

                        <Link
                            href="/plays"
                            class="text-sm font-bold text-[#5200FF] dark:text-[#CCFF00]"
                        >
                            Ver historial →
                        </Link>
                    </div>

                    <div
                        v-if="
                            recentPlays.length
                        "
                        class="mt-4 space-y-3"
                    >
                        <article
                            v-for="
                                play in
                                recentPlays
                            "
                            :key="play.id"
                            class="flex gap-4 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950"
                        >
                            <div
                                class="h-20 w-16 shrink-0 overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-900"
                            >
                                <img
                                    v-if="
                                        play.game
                                            .image_url
                                    "
                                    :src="
                                        play.game
                                            .image_url
                                    "
                                    :alt="
                                        play.game
                                            .name
                                    "
                                    class="h-full w-full object-contain"
                                />

                                <div
                                    v-else
                                    class="flex h-full items-center justify-center"
                                >
                                    <PlayingCardsFanIcon class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-violet-600 dark:text-violet-300" aria-hidden="true" />
                                </div>
                            </div>

                            <div
                                class="min-w-0"
                            >
                                <Link
                                    :href="
                                        `/games/${play.game.id}`
                                    "
                                    class="font-black hover:text-[#5200FF]"
                                >
                                    {{
                                        play.game
                                            .name
                                    }}
                                </Link>

                                <p
                                    v-if="
                                        play.expansion
                                    "
                                    class="mt-1 text-sm font-semibold text-[#5200FF] dark:text-[#CCFF00]"
                                >
                                    <BuildingComplexPlus class="mr-1 inline-block size-4 shrink-0 align-[-0.15em] text-[#6D28D9] dark:text-[#CCFF00]" :stroke-width="2" aria-hidden="true" />
                                    {{
                                        play
                                            .expansion
                                            .name
                                    }}
                                </p>

                                <p
                                    class="mt-1 text-sm text-zinc-500"
                                >
                                    {{
                                        formatDate(
                                            play.played_at
                                        )
                                    }}
                                </p>

                                <p
                                    class="mt-1 truncate text-xs text-zinc-400"
                                >
                                    {{
                                        play.players
                                            .map(
                                                player =>
                                                    player.name
                                            )
                                            .join(
                                                ' · '
                                            )
                                    }}
                                </p>
                            </div>
                        </article>
                    </div>

                    <div
                        v-else
                        class="mt-4 rounded-2xl border border-dashed border-zinc-300 p-6 text-center text-sm text-zinc-500 dark:border-zinc-700"
                    >
                        Todavía no has
                        registrado partidas.
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
