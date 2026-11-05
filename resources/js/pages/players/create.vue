<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import InputError from '@/components/InputError.vue';

const form = useForm({
    name: '',
});

const submit = () => {
    form.post('/players');
};
</script>

<template>
    <Head title="Añadir jugador" />

    <AppLayout>
        <div class="mx-auto w-full max-w-xl px-4 py-6 sm:px-6">

            <Link
                href="/players"
                class="text-sm font-semibold text-[#5200FF]"
            >
                ← Volver a jugadores
            </Link>

            <h1 class="mt-4 text-3xl font-bold">
                Añadir jugador
            </h1>

            <form
                class="mt-8 space-y-6 rounded-2xl border p-6"
                @submit.prevent="submit"
            >
                <div>
                    <label
                        class="mb-2 block font-semibold"
                    >
                        Nombre
                    </label>

                    <input
                        v-model="form.name"
                        type="text"
                        placeholder="Juan"
                        class="w-full rounded-xl border px-4 py-3"
                    />

                    <InputError :message="form.errors.name" />
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
    </AppLayout>
</template>
