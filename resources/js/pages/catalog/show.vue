<script setup lang="ts">
import YoutubeLogoIcon from '@/components/icons/YoutubeLogoIcon.vue';
import { Link as ChainIcon, BrainCog, Swords } from '@lucide/vue';
import PlayingCardsFanIcon from '@/components/icons/PlayingCardsFanIcon.vue';
import { nextTick, ref } from 'vue';
import { useResizeObserver } from '@vueuse/core';
import { Head, Link } from '@inertiajs/vue3';
import ScrollToTopProgress from '@/components/ScrollToTopProgress.vue';

interface Collection {
    id: number;
    name: string;
}

interface Expansion {
    id: number;
    name: string;
    image_url: string | null;
    bgg_url: string | null;
    youtube_url: string | null;
}

interface Game {
    id: number;
    name: string;
    image_url: string | null;
    min_players: number;
    max_players: number;
    min_playtime: number | null;
    max_playtime: number | null;
    complexity: string | number | null;
    game_mode: string | null;
    game_modes: string[];
    short_description: string | null;
    full_description: string | null;
    additional_information: string | null;
    bgg_url: string | null;
    youtube_url: string | null;
    ideal_player_counts: number[];
    collections: Collection[];
    expansions: Expansion[];
}

const props = defineProps<{
    catalogToken: string;
    catalogBasePath?: string;
    game: Game;
}>();

const imageDialog = ref<HTMLDialogElement | null>(null);
const coverHasError = ref(false);

const brokenExpansionIds = ref<number[]>([]);

const markExpansionAsBroken = (expansionId: number) => {
    if (!brokenExpansionIds.value.includes(expansionId)) {
        brokenExpansionIds.value.push(expansionId);
    }
};

const showFullDescription = ref(false);
const descriptionParagraph = ref<HTMLParagraphElement | null>(null);
const descriptionIsClipped = ref(false);

useResizeObserver(descriptionParagraph, () => {
    const paragraph = descriptionParagraph.value;
    if (paragraph && !showFullDescription.value) {
        descriptionIsClipped.value = paragraph.scrollHeight > paragraph.clientHeight;
    }
});

const openImage = () => {
    imageDialog.value?.showModal();
};

const closeImage = () => {
    imageDialog.value?.close();
};

const catalogUrl = props.catalogBasePath ?? `/catalog/${props.catalogToken}`;

const shareMessage = ref('');
const manualShareUrl = ref('');
const manualShareInput = ref<HTMLInputElement | null>(null);

const shareGame = async () => {
    shareMessage.value = '';
    manualShareUrl.value = '';

    const url = window.location.origin + window.location.pathname;

    // Compartir con las aplicaciones del dispositivo.
    if (navigator.share) {
        try {
            await navigator.share({
                title: props.game.name,
                url,
            });
            return;
        } catch (error) {
            // Si el usuario cancela, no hacemos nada.
            if (
                error instanceof DOMException &&
                error.name === 'AbortError'
            ) {
                return;
            }
        }
    }

    // Alternativa: copiar el enlace.
    try {
        await navigator.clipboard.writeText(url);
        shareMessage.value = '¡Enlace copiado!';
    } catch {
        manualShareUrl.value = url;
        shareMessage.value = 'Puedes copiar el enlace manualmente.';
        await nextTick();
        manualShareInput.value?.focus();
        manualShareInput.value?.select();
    }
};

const duration = () => {
    const min = props.game.min_playtime;
    const max = props.game.max_playtime;

    if (min === null && max === null) return 'Sin datos';
    if (min === null) return `${max} min`;
    if (max === null || min === max) return `${min} min`;

    return `${min}–${max} min`;
};

const gameMode = (): string => {
    const labels: Record<string, string> = {
        competitive: 'Competitivo',
        cooperative: 'Cooperativo',
        semi_cooperative: 'Semicooperativo',
    };

    if (props.game.game_modes.length === 0) {
        return props.game.max_players === 1
            ? 'Solitario'
            : 'Sin clasificar';
    }

    return props.game.game_modes
        .map(mode => labels[mode] ?? mode)
        .join(' · ');
};

// Admitimos únicamente enlaces web externos.
const externalUrl = (url: string | null): string | undefined => {
    if (!url) return undefined;

    try {
        const parsed = new URL(url);
        return ['http:', 'https:'].includes(parsed.protocol)
            ? parsed.href
            : undefined;
    } catch {
        return undefined;
    }
};
</script>

<template>
    <Head :title="game.name" />

    <div class="min-h-screen bg-[#f7f7f5] text-zinc-950">
        <!-- CABECERA -->
        <header class="bg-[#181424] text-white">
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3 sm:px-8 sm:py-4">
                <Link
                    :href="catalogUrl"
                    class="inline-flex items-center gap-2 text-sm font-semibold text-zinc-300 transition hover:text-[#CCFF00]"
                >
                    ← Volver al catálogo
                </Link>

                <div class="flex shrink-0 items-center gap-2 sm:gap-3">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-[#CCFF00] text-[#5200FF] shadow-sm sm:size-11 sm:rounded-2xl" aria-hidden="true">
                        <PlayingCardsFanIcon class="!m-0 !size-5 shrink-0 sm:!size-6" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-xs font-black leading-tight tracking-tight sm:text-lg">Juegos de mesa</p>
                        <p class="mt-0.5 text-[11px] font-medium text-zinc-300 sm:mt-1 sm:text-xs">Clover</p>
                    </div>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-4 py-4 sm:px-8 sm:py-10">
            <!-- PRESENTACIÓN -->
            <section class="grid gap-4 sm:gap-9 lg:grid-cols-[minmax(0,380px)_1fr]">
                <!-- PORTADA AMPLIABLE -->
                <div
                    class="flex min-h-0 items-center justify-center overflow-hidden rounded-3xl border border-zinc-200 bg-white p-3 shadow-sm sm:min-h-80 sm:p-7"
                >
                    <button
                        v-if="game.image_url && !coverHasError"
                        type="button"
                        :aria-label="`Ampliar portada de ${game.name}`"
                        class="group flex w-full cursor-zoom-in items-center justify-center rounded-xl focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#5200FF]"
                        @click="openImage"
                    >
                        <img
                            :src="game.image_url"
                            :alt="`Portada de ${game.name}`"
                            @error="coverHasError = true"
                            class="max-h-[220px] w-full object-contain transition-transform duration-300 group-hover:scale-105 sm:max-h-[440px]"
                        />
                    </button>

                    <span v-else class="text-zinc-400">
                        Sin portada disponible
                    </span>
                </div>

                <!-- VISTA AMPLIADA -->
                <dialog
                    ref="imageDialog"
                    :aria-label="`Portada ampliada de ${game.name}`"
                    class="fixed inset-0 m-auto max-h-[95vh] w-[94vw] max-w-[900px] rounded-3xl border-0 bg-white p-5 shadow-2xl backdrop:bg-black/85"
                    @click.self="closeImage"
                >
                    <div class="flex flex-col gap-4">
                        <div class="flex items-center justify-between gap-4">
                            <h2 class="min-w-0 break-words text-lg font-black text-zinc-900">
                                {{ game.name }}
                            </h2>

                            <button
                                type="button"
                                class="min-h-11 shrink-0 whitespace-nowrap rounded-xl bg-zinc-100 px-4 py-2 text-sm font-bold text-zinc-800 hover:bg-zinc-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF]"
                                @click="closeImage"
                            >
                                ✕ Cerrar
                            </button>
                        </div>

                        <img
                            v-if="game.image_url && !coverHasError"
                            :src="game.image_url"
                            :alt="`Portada ampliada de ${game.name}`"
                            class="max-h-[78vh] w-full object-contain"
                        />
                    </div>
                </dialog>

                <!-- INFORMACIÓN -->
                <div class="flex flex-col justify-center">
                    <h1 class="break-words text-2xl font-bold leading-tight sm:text-3xl">
                        {{ game.name }}
                    </h1>

                    <div class="mt-3 flex flex-wrap gap-2 sm:mt-5">
                        <span
                            v-for="collection in game.collections"
                            :key="collection.id"
                            class="rounded-full bg-violet-100 px-3 py-1.5 text-xs font-bold text-violet-700"
                        >
                            {{ collection.name }}
                        </span>
                    </div>

                    <!-- DATOS PRINCIPALES -->
                    <div class="catalog-stats mt-4 grid grid-cols-2 gap-2 min-[360px]:grid-cols-3 sm:mt-8 sm:gap-3">
                        <div class="min-w-0 rounded-2xl bg-white p-2.5 shadow-sm sm:p-4">
                            <p class="text-[11px] text-zinc-500 sm:text-xs">Jugadores</p>
                            <p class="mt-1 text-base font-black sm:mt-2 sm:text-xl">
                                {{ game.min_players }}–{{ game.max_players }}
                            </p>
                        </div>

                        <div class="min-w-0 rounded-2xl bg-white p-2.5 shadow-sm sm:p-4">
                            <p class="text-[11px] text-zinc-500 sm:text-xs">Duración</p>
                            <p class="mt-1 break-words text-sm font-black leading-snug sm:mt-2 sm:text-lg">
                                {{ duration() }}
                            </p>
                        </div>

                        <div class="min-w-0 rounded-2xl bg-white p-2.5 shadow-sm sm:p-4">
                            <p class="text-[11px] text-zinc-500 sm:text-xs">Dificultad</p>
                            <p class="mt-1 inline-flex items-center gap-1 text-sm font-black sm:mt-2 sm:gap-2 sm:text-xl">
                                <BrainCog class="size-4 shrink-0 text-pink-600 sm:size-5" :stroke-width="2" aria-hidden="true" />{{
                                    game.complexity !== null
                                        ? Number(game.complexity).toFixed(2) + ' / 5'
                                        : 'Sin datos'
                                }}
                            </p>
                        </div>
                    </div>

                    <!-- JUGADORES IDEALES -->
                    <div
                        v-if="game.ideal_player_counts.length"
                        class="mt-3 flex flex-wrap items-center gap-2 rounded-2xl border border-lime-200 bg-lime-50 p-3 sm:mt-5 sm:gap-3 sm:p-4"
                    >
                        <p class="text-sm font-bold text-zinc-700">
                            Mejor número de jugadores
                        </p>

                        <div class="flex flex-wrap gap-2">
                            <span
                                v-for="count in game.ideal_player_counts"
                                :key="count"
                                class="rounded-lg bg-[#CCFF00] px-3 py-1.5 text-sm font-black sm:rounded-xl sm:px-4 sm:py-2 sm:text-base"
                            >
                                {{ count }}
                            </span>
                        </div>
                    </div>

                    <p class="mt-3 text-sm font-medium text-zinc-500 sm:mt-5">
                        Modalidad: <Swords v-if="game.game_modes.includes('competitive')" class="mr-1 inline-block size-4 shrink-0 align-[-0.14em] text-orange-700" :stroke-width="2.2" aria-hidden="true" /> {{ gameMode() }}
                    </p>

                    <!-- ENLACES EXTERNOS -->
                    <div class="mt-4 flex flex-wrap gap-2 sm:mt-7 sm:gap-3">
                        <button
                            v-if="!catalogBasePath"
                            type="button"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#181424] px-5 py-3 text-sm font-bold text-white transition hover:bg-[#5200FF] sm:w-auto"
                            @click="shareGame"
                        >
                            <span aria-hidden="true"><ChainIcon class="inline-block h-4 w-4 shrink-0 align-[-0.15em]" aria-hidden="true" /></span>
                            Compartir juego
                        </button>

                        <span
                            v-if="shareMessage"
                            role="status"
                            class="self-center text-sm font-semibold text-[#5200FF]"
                        >
                            {{ shareMessage }}
                        </span>

                        <a
                            v-if="externalUrl(game.bgg_url)"
                            :href="externalUrl(game.bgg_url)"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex min-h-11 min-w-0 flex-1 items-center justify-center rounded-xl border border-violet-200 bg-violet-50 px-3 py-2.5 text-center text-sm font-bold text-[#5200FF] transition hover:bg-violet-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF] sm:flex-none sm:px-5"
                        >
                            Ver en BGG <ChainIcon class="ml-1 inline-block h-4 w-4 shrink-0 align-[-0.15em]" aria-hidden="true" />
                        </a>

                        <a
                            v-if="externalUrl(game.youtube_url)"
                            :href="externalUrl(game.youtube_url)"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex min-h-11 min-w-0 flex-1 items-center justify-center gap-1.5 rounded-xl border border-violet-200 bg-white px-3 py-2.5 text-center text-sm font-bold text-[#5200FF] transition-all duration-200 hover:bg-[#f1ebff] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF] motion-reduce:transform-none sm:flex-none sm:px-5"
                        >
                            <YoutubeLogoIcon class="size-4 shrink-0" aria-hidden="true" /> Ver tutorial <ChainIcon class="ml-1 inline-block h-4 w-4 shrink-0 align-[-0.15em]" aria-hidden="true" />
                        </a>
                    </div>

                    <label v-if="manualShareUrl" class="mt-3 block text-sm font-semibold text-zinc-700">
                        Enlace del juego
                        <input
                            ref="manualShareInput"
                            :value="manualShareUrl"
                            readonly
                            class="mt-1 block w-full min-w-0 rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm"
                        />
                    </label>
                </div>
            </section>

            <!-- DESCRIPCIÓN -->
            <section
                v-if="game.full_description || game.short_description || game.additional_information"
                class="mt-6 rounded-3xl border border-zinc-200 bg-white p-4 shadow-sm sm:mt-10 sm:p-8"
            >
                <h2 class="text-xl font-black sm:text-2xl">
                    Sobre el juego
                </h2>

                <div
                    v-if="game.full_description || game.short_description"
                    class="mt-3 max-w-3xl sm:mt-5"
                >
                    <p
                        id="catalog-game-description"
                        ref="descriptionParagraph"
                        class="whitespace-pre-line text-base leading-7 text-zinc-700 sm:leading-8"
                        :class="{ 'line-clamp-6': !showFullDescription }"
                    >
                        {{ game.full_description || game.short_description }}
                    </p>

                    <button
                        v-if="descriptionIsClipped || showFullDescription"
                        type="button"
                        class="mt-4 rounded-xl bg-violet-50 px-4 py-2 text-sm font-bold text-[#5200FF] transition hover:bg-violet-100"
                        :aria-expanded="showFullDescription"
                        aria-controls="catalog-game-description"
                        @click="showFullDescription = !showFullDescription"
                    >
                        {{
                            showFullDescription
                                ? 'Mostrar menos ↑'
                                : 'Leer descripción completa ↓'
                        }}
                    </button>
                </div>

                <div
                    v-if="game.additional_information"
                    class="mt-7 border-t border-zinc-100 pt-6"
                >
                    <h3 class="font-bold">
                        Información adicional
                    </h3>

                    <p class="mt-3 whitespace-pre-line leading-7 text-zinc-600">
                        {{ game.additional_information }}
                    </p>
                </div>
            </section>

            <!-- EXPANSIONES -->
            <section v-if="game.expansions.length > 0" class="mt-7 sm:mt-12">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3 sm:mb-6">
                    <h2 class="text-xl font-black sm:text-2xl">Expansiones</h2>
                    <span class="rounded-full bg-white px-4 py-2 text-sm font-bold text-zinc-600">
                        {{ game.expansions.length }} {{ game.expansions.length === 1 ? 'expansión' : 'expansiones' }}
                    </span>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <article
                        v-for="expansion in game.expansions"
                        :key="expansion.id"
                        class="group flex h-full flex-row overflow-hidden rounded-3xl border border-zinc-200 bg-white shadow-sm transition-all duration-300 hover:-translate-y-1 hover:border-[#5200FF] hover:shadow-xl focus-within:border-[#5200FF] motion-reduce:transform-none motion-reduce:transition-none sm:flex-col"
                    >
                        <!-- PORTADA -->
                        <div
                            class="relative flex w-28 shrink-0 items-center justify-center overflow-hidden bg-gradient-to-br from-[#f0ebff] via-white to-[#eef2fa] p-2 sm:aspect-square sm:h-auto sm:w-auto sm:p-6"
                        >
                            <img
                                v-if="expansion.image_url && !brokenExpansionIds.includes(expansion.id)"
                                :src="expansion.image_url"
                                @error="markExpansionAsBroken(expansion.id)"
                                :alt="`Portada de ${expansion.name}`"
                                loading="lazy"
                                class="max-h-44 w-full object-contain drop-shadow-xl transition-transform duration-500 group-hover:scale-105 motion-reduce:transform-none sm:h-full sm:max-h-none"
                            />

                            <div
                                v-else
                                class="flex min-h-24 flex-col items-center justify-center gap-1 text-center text-zinc-500"
                            >
                                <span class="text-5xl"><PlayingCardsFanIcon class="mr-1 inline-block size-12 shrink-0 align-[-0.14em] text-violet-700" aria-hidden="true" /></span>
                                <span class="text-xs">Sin portada</span>
                            </div>
                        </div>

                        <!-- INFORMACIÓN -->
                        <div class="flex min-w-0 flex-1 flex-col p-3 sm:p-5">
                            <h3 class="break-words text-base font-black leading-snug sm:text-lg">
                                {{ expansion.name }}
                            </h3>

                            <!-- ENLACES -->
                            <div
                                v-if="externalUrl(expansion.bgg_url) || externalUrl(expansion.youtube_url)"
                                class="mt-auto flex flex-wrap gap-2 pt-3 sm:pt-6"
                            >
                                <a
                                    v-if="externalUrl(expansion.bgg_url)"
                                    :href="externalUrl(expansion.bgg_url)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex min-h-11 min-w-0 flex-1 basis-28 items-center justify-center rounded-xl bg-[#5200FF] px-2 py-2.5 text-center text-xs font-bold text-white transition-all duration-200 hover:-translate-y-0.5 hover:bg-[#4000cc] hover:shadow-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF] motion-reduce:transform-none sm:min-w-[125px] sm:px-3 sm:text-sm"
                                >
                                    Ver en BGG <ChainIcon class="ml-1 inline-block h-4 w-4 shrink-0 align-[-0.15em]" aria-hidden="true" />
                                </a>

                                <a
                                    v-if="externalUrl(expansion.youtube_url)"
                                    :href="externalUrl(expansion.youtube_url)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex min-h-11 min-w-0 flex-1 basis-28 items-center justify-center gap-1 rounded-xl border-2 border-[#5200FF] bg-white px-2 py-2 text-center text-xs font-bold text-[#5200FF] transition-all duration-200 hover:-translate-y-0.5 hover:bg-[#f1ebff] hover:shadow-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF] motion-reduce:transform-none sm:min-w-[125px] sm:gap-1.5 sm:px-3 sm:text-sm"
                                >
                                    <YoutubeLogoIcon class="size-4 shrink-0" aria-hidden="true" /> Ver tutorial <ChainIcon class="ml-1 inline-block h-4 w-4 shrink-0 align-[-0.15em]" aria-hidden="true" />
                                </a>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <!-- REGRESAR -->
            <div class="mt-12 text-center">
                <Link
                    :href="catalogUrl"
                    class="inline-flex rounded-xl bg-[#181424] px-7 py-3 font-bold text-white transition hover:bg-[#5200FF]"
                >
                    ← Seguir explorando juegos
                </Link>
            </div>
        </main>

        <footer class="mt-12 border-t border-zinc-200 py-8 text-center text-sm text-zinc-500">
            Juegos de mesa · Biblioteca compartida

            <!-- CRÉDITOS DE ICONOS - CLOVER -->
            <a href="/creditos-iconos.html" class="ml-2 inline-block font-semibold text-[#5200FF] underline-offset-4 hover:underline focus-visible:underline">Créditos de iconos</a>
        </footer>

        <ScrollToTopProgress :remember-position="false" compact-on-mobile />
    </div>
</template>

<style scoped>

/* En móviles muy estrechos mantenemos dos columnas para que los datos no se aprieten. */
@media (max-width: 359px) {
    .catalog-stats > :last-child:nth-child(odd) {
        grid-column: 1 / -1;
    }
}
</style>
