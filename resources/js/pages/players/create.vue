<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import InputError from '@/components/InputError.vue';
import PlayerColorPicker from '@/components/PlayerColorPicker.vue';
import ScrollToTopProgress from '@/components/ScrollToTopProgress.vue';

const form = useForm({
    name: '',
    color: 'violet',
});

const submit = () => {
    if (form.processing) return;
    form.post('/players');
};
</script>

<template>
    <Head title="Añadir jugador" />

    <AppLayout>
        <div class="mx-auto w-full max-w-3xl px-4 py-6 sm:px-6">

            <Link
                href="/players"
                class="text-sm font-semibold text-[#5200FF] hover:underline dark:text-[#CCFF00]"
            >
                ← Volver a jugadores
            </Link>

            <h1 class="mt-4 text-2xl font-bold text-zinc-900 dark:text-zinc-100 sm:text-3xl">
                Añadir jugador
            </h1>

            <form
                class="mt-6 space-y-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm sm:p-7 dark:border-zinc-800 dark:bg-zinc-950"
                @submit.prevent="submit"
            >
                <div>
                    <label for="create-player-name" class="mb-2 block font-bold">
                        Nombre
                    </label>

                    <input
                        id="create-player-name"
                        v-model="form.name"
                        type="text"
                        maxlength="255"
                        required
                        placeholder="Juan"
                        class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 focus:border-violet-500 focus:outline-none focus:ring-2 focus:ring-violet-500/30 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                    />

                    <InputError :message="form.errors.name" />
                </div>

                <div>
                    <PlayerColorPicker v-model="form.color" />
                    <InputError :message="form.errors.color" />
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full rounded-xl bg-[#5200FF] px-6 py-3 font-semibold text-white disabled:opacity-50"
                >
                    {{
                        form.processing
                            ? 'Guardando...'
                            : 'Guardar jugador'
                    }}
                </button>
            </form>

        </div>
        <ScrollToTopProgress :remember-position="false" compact-on-mobile hide-near-bottom />
    </AppLayout>
</template>
