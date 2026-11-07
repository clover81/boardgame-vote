<script setup lang="ts">
import {
    computed,
    watch,
} from 'vue';

import {
    Head,
    Link,
    useForm,
} from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import InputError from '@/components/InputError.vue';
import ScrollToTopProgress from '@/components/ScrollToTopProgress.vue';

interface Expansion {
    id: number;
    name: string;
}

interface Game {
    id: number;
    name: string;
    image_url: string | null;
    expansions: Expansion[];
}

interface Player {
    id: number;
    name: string;
}

interface Play {
    id: number;
    game_id: number;

    expansion_id:
        number | null;

    played_at: string;

    notes: string | null;

    player_ids: number[];
}

const props = defineProps<{
    play: Play;
    games: Game[];
    players: Player[];
}>();

/*
|--------------------------------------------------------------------------
| Formulario
|--------------------------------------------------------------------------
*/

const form = useForm({
    game_id:
        props.play.game_id,

    expansion_id:
        props.play.expansion_id,

    played_at:
        props.play.played_at,

    player_ids:
        [...props.play.player_ids],

    notes:
        props.play.notes ?? '',
});

/*
|--------------------------------------------------------------------------
| Juego seleccionado
|--------------------------------------------------------------------------
*/

const selectedGame = computed(
    () =>
        props.games.find(
            (game) =>
                game.id ===
                form.game_id
        ) ?? null
);

const availableExpansions =
    computed(() => {
        return (
            selectedGame.value
                ?.expansions ?? []
        );
    });

/*
|--------------------------------------------------------------------------
| Cambio de juego
|--------------------------------------------------------------------------
|
| Solo reseteamos la expansión cuando el usuario
| cambia realmente el juego.
|
*/

let initialGameId =
    form.game_id;

watch(
    () => form.game_id,
    (newGameId) => {
        if (
            newGameId !==
            initialGameId
        ) {
            form.expansion_id =
                null;
        }

        initialGameId =
            newGameId;
    }
);

/*
|--------------------------------------------------------------------------
| Guardar
|--------------------------------------------------------------------------
*/

const submit = () => {
    form.put(
        `/plays/${props.play.id}`
    );
};
</script>

<template>
    <Head title="Editar partida" />

    <AppLayout>
        <div
            class="mx-auto w-full max-w-4xl px-4 py-6 sm:px-6"
        >
            <Link
                href="/plays"
                class="text-sm font-semibold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
            >
                ← Volver al historial
            </Link>

            <!-- CABECERA -->

            <div class="mt-4">
                <p
                    class="font-semibold text-[#5200FF]"
                >
                    HISTORIAL
                </p>

                <h1
                    class="text-3xl font-bold text-zinc-900 dark:text-zinc-100"
                >
                    Editar partida
                </h1>

                <p
                    class="mt-2 text-zinc-500 dark:text-zinc-400"
                >
                    Corrige el juego, expansión,
                    fecha, participantes o notas.
                </p>
            </div>

            <form
                class="mt-8 space-y-8"
                @submit.prevent="submit"
            >
                <!-- ================================================== -->
                <!-- JUEGO                                              -->
                <!-- ================================================== -->

                <section
                    class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <h2
                        class="text-xl font-bold"
                    >
                        Juego
                    </h2>

                    <div
                        class="mt-5 grid gap-4 sm:grid-cols-2"
                    >
                        <label
                            v-for="game in games"
                            :key="game.id"
                            class="cursor-pointer"
                        >
                            <input
                                v-model="
                                    form.game_id
                                "
                                type="radio"
                                :value="game.id"
                                class="peer sr-only"
                            />

                            <div
                                class="overflow-hidden rounded-2xl border border-zinc-200 transition peer-checked:border-[#5200FF] peer-checked:ring-2 peer-checked:ring-[#5200FF] dark:border-zinc-800"
                            >
                                <div
                                    v-if="
                                        game.image_url
                                    "
                                    class="aspect-video bg-zinc-100 dark:bg-zinc-900"
                                >
                                    <img
                                        :src="
                                            game.image_url
                                        "
                                        :alt="
                                            game.name
                                        "
                                        class="h-full w-full object-cover"
                                    />
                                </div>

                                <div
                                    v-else
                                    class="flex aspect-video items-center justify-center bg-zinc-100 text-zinc-400 dark:bg-zinc-900"
                                >
                                    Sin portada
                                </div>

                                <div
                                    class="p-4 font-bold"
                                >
                                    {{ game.name }}
                                </div>
                            </div>
                        </label>
                    </div>

                    <InputError :message="form.errors.game_id" />
                </section>

                <!-- ================================================== -->
                <!-- EXPANSIÓN                                          -->
                <!-- ================================================== -->

                <section
                    v-if="selectedGame"
                    class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <h2
                        class="text-xl font-bold"
                    >
                        🧩 Expansión
                    </h2>

                    <p
                        class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"
                    >
                        Expansión utilizada en esta
                        partida.
                    </p>

                    <div
                        class="mt-5 grid gap-3 sm:grid-cols-2"
                    >
                        <label
                            class="cursor-pointer"
                        >
                            <input
                                v-model="
                                    form.expansion_id
                                "
                                type="radio"
                                :value="null"
                                class="peer sr-only"
                            />

                            <div
                                class="rounded-xl border border-zinc-200 p-4 font-semibold transition peer-checked:border-[#5200FF] peer-checked:bg-violet-50 dark:border-zinc-800 dark:peer-checked:bg-violet-950/30"
                            >
                                🎲 Sin expansión
                            </div>
                        </label>

                        <label
                            v-for="expansion in availableExpansions"
                            :key="
                                expansion.id
                            "
                            class="cursor-pointer"
                        >
                            <input
                                v-model="
                                    form.expansion_id
                                "
                                type="radio"
                                :value="
                                    expansion.id
                                "
                                class="peer sr-only"
                            />

                            <div
                                class="rounded-xl border border-zinc-200 p-4 font-semibold transition peer-checked:border-[#5200FF] peer-checked:bg-violet-50 dark:border-zinc-800 dark:peer-checked:bg-violet-950/30"
                            >
                                🧩
                                {{
                                    expansion.name
                                }}
                            </div>
                        </label>
                    </div>

                    <p
                        v-if="
                            availableExpansions.length ===
                            0
                        "
                        class="mt-4 text-sm text-zinc-500"
                    >
                        Este juego no tiene expansiones
                        registradas.
                    </p>

                    <InputError :message="form.errors.expansion_id" />
                </section>

                <!-- FECHA -->

                <section
                    class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <label class="font-bold">
                        Fecha de la partida
                    </label>

                    <input
                        v-model="
                            form.played_at
                        "
                        type="date"
                        class="mt-3 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                    />

                    <InputError :message="form.errors.played_at" />
                </section>

                <!-- PARTICIPANTES -->

                <section
                    class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <h2
                        class="text-xl font-bold"
                    >
                        Participantes
                    </h2>

                    <div
                        class="mt-5 grid gap-3 sm:grid-cols-2"
                    >
                        <label
                            v-for="player in players"
                            :key="player.id"
                            class="cursor-pointer"
                        >
                            <input
                                v-model="
                                    form.player_ids
                                "
                                type="checkbox"
                                :value="player.id"
                                class="peer sr-only"
                            />

                            <div
                                class="rounded-xl border border-zinc-200 p-4 font-semibold transition peer-checked:border-[#5200FF] peer-checked:bg-[#5200FF] peer-checked:text-white dark:border-zinc-800"
                            >
                                {{ player.name }}
                            </div>
                        </label>
                    </div>

                    <InputError :message="form.errors.player_ids" />
                </section>

                <!-- NOTAS -->

                <section
                    class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <label class="font-bold">
                        Notas
                    </label>

                    <textarea
                        v-model="form.notes"
                        rows="4"
                        class="mt-3 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                        placeholder="Notas de la partida..."
                    />

                    <InputError :message="form.errors.notes" />
                </section>

                <!-- GUARDAR -->

                <button
                    type="submit"
                    :disabled="
                        form.processing
                    "
                    class="w-full rounded-xl bg-[#5200FF] px-6 py-4 text-lg font-bold text-white transition hover:opacity-90 disabled:opacity-50"
                >
                    {{
                        form.processing
                            ? 'Guardando...'
                            : 'Guardar cambios'
                    }}
                </button>
            </form>
        </div>
        <ScrollToTopProgress :remember-position="false" />
    </AppLayout>
</template>
