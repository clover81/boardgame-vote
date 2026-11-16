<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import RollingDicesIcon from '@/components/icons/RollingDicesIcon.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="relative flex min-h-svh items-center justify-center overflow-hidden bg-[#f7f7f5] px-5 py-10 text-[#181424] dark:bg-[#100d19] dark:text-white sm:px-8">
        <div aria-hidden="true" class="pointer-events-none absolute -right-24 top-12 size-72 rounded-full bg-[#5200FF]/10 blur-3xl dark:bg-[#5200FF]/20" />
        <div aria-hidden="true" class="pointer-events-none absolute -bottom-28 -left-24 size-72 rounded-full bg-[#5200FF]/10 blur-3xl dark:bg-[#5200FF]/20" />

        <div class="relative z-10 w-full max-w-md">
            <Link :href="home()" class="mx-auto flex w-fit items-center gap-3 rounded-xl focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#5200FF]">
                <span class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-[#5200FF] shadow-lg shadow-[#5200FF]/15">
                    <RollingDicesIcon class="size-8 text-[#CCFF00]" />
                </span>
                <span class="flex flex-col">
                    <span class="text-lg font-extrabold leading-tight tracking-tight">Juegos de mesa</span>
                    <span class="text-xs font-bold uppercase tracking-[0.2em] text-[#5200FF] dark:text-[#CCFF00]">Clover</span>
                </span>
            </Link>

            <main class="mt-8 rounded-3xl border border-zinc-200/80 bg-white p-6 shadow-[0_16px_50px_rgba(24,20,36,0.07)] sm:p-8 dark:border-white/10 dark:bg-[#1b1628]">
                <div class="mb-7 space-y-2 text-center">
                    <h1 class="text-2xl font-extrabold tracking-tight">{{ title }}</h1>
                    <p v-if="description" class="text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">{{ description }}</p>
                </div>
                <slot />
            </main>

            <p class="mt-6 text-center text-xs text-zinc-500 dark:text-zinc-400">Juegos de mesa · Clover</p>
        </div>
    </div>
</template>
