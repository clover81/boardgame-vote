<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import InputError from '@/components/InputError.vue';
import PlayerColorPicker from '@/components/PlayerColorPicker.vue';
import ScrollToTopProgress from '@/components/ScrollToTopProgress.vue';

const props = defineProps<{
    player: { id: number; name: string; color: string | null };
}>();

const form = useForm({
    name: props.player.name,
    color: props.player.color ?? 'violet',
});

const submit = () => form.patch(`/players/${props.player.id}`);
</script>

<template>
    <Head :title="`Editar ${player.name}`" />
    <AppLayout>
        <div class="mx-auto w-full max-w-3xl px-4 py-6 sm:px-6">
            <Link href="/players" class="text-sm font-bold text-[#5200FF] hover:underline dark:text-[#CCFF00]">
                ← Volver a jugadores
            </Link>
            <h1 class="mt-4 text-2xl font-bold text-zinc-900 dark:text-zinc-100 sm:text-3xl">Editar jugador</h1>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">Cambia el nombre o el color sin perder sus partidas ni sus votaciones.</p>

            <form class="mt-6 space-y-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm sm:p-7 dark:border-zinc-800 dark:bg-zinc-950" @submit.prevent="submit">
                <div>
                    <label for="edit-player-name" class="mb-2 block font-bold">Nombre</label>
                    <input
                        id="edit-player-name"
                        v-model="form.name"
                        type="text"
                        maxlength="255"
                        required
                        class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 focus:border-violet-500 focus:outline-none focus:ring-2 focus:ring-violet-500/30 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                    />
                    <InputError :message="form.errors.name" />
                </div>
                <div>
                    <PlayerColorPicker v-model="form.color" />
                    <InputError :message="form.errors.color" />
                </div>
                <button type="submit" :disabled="form.processing" class="w-full rounded-xl bg-[#5200FF] px-6 py-3 font-bold text-white transition hover:brightness-110 disabled:opacity-50">
                    {{ form.processing ? 'Guardando…' : 'Guardar cambios' }}
                </button>
            </form>
        </div>
        <ScrollToTopProgress :remember-position="false" />
    </AppLayout>
</template>
