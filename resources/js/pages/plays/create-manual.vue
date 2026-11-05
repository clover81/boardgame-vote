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

/*
|--------------------------------------------------------------------------
| Tipos
|--------------------------------------------------------------------------
*/

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

const props = defineProps<{
    games: Game[];
    players: Player[];

    selected_game_id:
        number | null;
}>();

/*
|--------------------------------------------------------------------------
| Fecha local de hoy
|--------------------------------------------------------------------------
*/

const today = (): string => {
    const date = new Date();

    const year =
        date.getFullYear();

    const month =
        String(
            date.getMonth() + 1
        ).padStart(
            2,
            '0'
        );

    const day =
        String(
            date.getDate()
        ).padStart(
            2,
            '0'
        );

    return `${year}-${month}-${day}`;
};

/*
|--------------------------------------------------------------------------
| Formulario
|--------------------------------------------------------------------------
*/

const form = useForm({
    game_id:
        props.selected_game_id,

    expansion_id:
        null as number | null,

    played_at:
        today(),

    player_ids:
        [] as number[],

    notes:
        '',
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

/*
|--------------------------------------------------------------------------
| Expansiones disponibles
|--------------------------------------------------------------------------
*/

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
*/

watch(
    () => form.game_id,
    (newGameId, oldGameId) => {
        if (oldGameId !== null && newGameId !== oldGameId) {
            form.expansion_id = null;
        }
    }
);

/*
|--------------------------------------------------------------------------
| Guardar
|--------------------------------------------------------------------------
*/

const submit = () => {
    form.transform(({ game_id, expansion_id, ...data }) => ({
        ...data,
        game_ids: game_id === null ? [] : [game_id],
        expansion_ids: game_id === null ? {} : { [game_id]: expansion_id },
    })).post('/plays', {
        onError: (errors) => {
            const gameError = errors.game_ids ?? errors['game_ids.0'];
            if (gameError) {
                form.setError('game_id', gameError);
            }

            const expansionError = errors.expansion_ids ?? errors[`expansion_ids.${form.game_id}`];
            if (expansionError) {
                form.setError('expansion_id', expansionError);
            }
        },
    });
};
</script>

<template>
    <Head title="Registrar partida" />

    <AppLayout>
        <div
            class="mx-auto w-full max-w-5xl px-4 py-6 sm:px-6"
        >
            <!-- VOLVER -->

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
                    class="mt-1 text-3xl font-bold text-zinc-900 dark:text-zinc-100"
                >
                    Registrar partida
                </h1>

                <p
                    class="mt-2 text-zinc-500 dark:text-zinc-400"
                >
                    Añade una partida sin necesidad
                    de crear una votación.
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
                        ¿A qué jugasteis?
                    </h2>

                    <p
                        class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"
                    >
                        Selecciona el juego base.
                    </p>

                    <div
                        class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
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
                                class="overflow-hidden rounded-2xl border border-zinc-200 bg-white transition peer-checked:border-[#5200FF] peer-checked:ring-2 peer-checked:ring-[#5200FF] dark:border-zinc-800 dark:bg-zinc-900"
                            >
                                <!-- PORTADA -->

                                <div
                                    v-if="
                                        game.image_url
                                    "
                                    class="aspect-video bg-zinc-100 dark:bg-zinc-800"
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
                                    class="flex aspect-video items-center justify-center bg-zinc-100 text-sm text-zinc-400 dark:bg-zinc-800 dark:text-zinc-500"
                                >
                                    Sin portada
                                </div>

                                <div
                                    class="p-4 font-bold text-zinc-900 dark:text-zinc-100"
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
                    class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <div>
                        <label
                            for="played-expansion"
                            class="block text-sm font-bold"
                        >
                            Expansión jugada

                            <span class="font-normal text-zinc-500">
                                (opcional)
                            </span>
                        </label>

                        <select
                            id="played-expansion"
                            v-model="form.expansion_id"
                            :disabled="!form.game_id || !availableExpansions.length"
                            class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100"
                        >
                            <option :value="null">
                                Sin expansión
                            </option>

                            <option
                                v-for="expansion in availableExpansions"
                                :key="expansion.id"
                                :value="expansion.id"
                            >
                                {{ expansion.name }}
                            </option>
                        </select>

                        <p
                            v-if="form.game_id && !availableExpansions.length"
                            class="mt-2 text-xs text-zinc-500"
                        >
                            Este juego no tiene
                            expansiones activas.
                        </p>

                        <InputError :message="form.errors.expansion_id" />
                    </div>
                </section>

                <!-- ================================================== -->
                <!-- FECHA                                              -->
                <!-- ================================================== -->

                <section
                    class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <label
                        class="font-bold"
                    >
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

                <!-- ================================================== -->
                <!-- PARTICIPANTES                                      -->
                <!-- ================================================== -->

                <section
                    class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <h2
                        class="text-xl font-bold"
                    >
                        ¿Quién jugó?
                    </h2>

                    <p
                        class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"
                    >
                        Selecciona los participantes
                        reales de la partida.
                    </p>

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
                                class="rounded-xl border border-zinc-200 bg-white p-4 font-semibold text-zinc-900 transition peer-checked:border-[#5200FF] peer-checked:bg-[#5200FF] peer-checked:text-white dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-100"
                            >
                                {{ player.name }}
                            </div>
                        </label>
                    </div>

                    <InputError :message="form.errors.player_ids" />
                </section>

                <!-- ================================================== -->
                <!-- NOTAS                                              -->
                <!-- ================================================== -->

                <section
                    class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <label
                        class="font-bold"
                    >
                        Notas
                    </label>

                    <textarea
                        v-model="form.notes"
                        rows="4"
                        class="mt-3 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 placeholder:text-zinc-400 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:placeholder:text-zinc-500"
                        placeholder="Ej: Primera partida con Matcha, partida muy igualada..."
                    ></textarea>

                    <InputError :message="form.errors.notes" />
                </section>

                <!-- ================================================== -->
                <!-- GUARDAR                                            -->
                <!-- ================================================== -->

                <button
                    type="submit"
                    :disabled="
                        form.processing
                    "
                    class="w-full rounded-xl bg-[#5200FF] px-6 py-4 text-lg font-bold text-white transition hover:opacity-90 disabled:opacity-50"
                >
                    {{
                        form.processing
                            ? 'Registrando...'
                            : 'Registrar partida'
                    }}
                </button>
            </form>
        </div>
    </AppLayout>
</template>
