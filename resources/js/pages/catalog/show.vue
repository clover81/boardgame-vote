<script setup lang="ts">
import { ref } from 'vue';
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

const openImage = () => {
    imageDialog.value?.showModal();
};

const closeImage = () => {
    imageDialog.value?.close();
};

const catalogUrl = `/catalog/${props.catalogToken}`;

const shareMessage = ref('');

const shareGame = async () => {
    shareMessage.value = '';

    const url = window.location.href;

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
        shareMessage.value = 'No se pudo copiar el enlace.';
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
            <div class="mx-auto max-w-6xl px-5 py-6 sm:px-8">
                <Link
                    :href="catalogUrl"
                    class="inline-flex items-center gap-2 text-sm font-semibold text-zinc-300 transition hover:text-[#CCFF00]"
                >
                    ← Volver al catálogo
                </Link>

                <div class="mt-6 flex items-center gap-3">
                    <span class="text-2xl">🎲</span>
                    <span class="text-lg font-black tracking-tight">
                        BOARDGAME<span class="text-[#CCFF00]">VOTE</span>
                    </span>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-5 py-6 sm:px-8 sm:py-10">
            <!-- PRESENTACIÓN -->
            <section class="grid gap-6 sm:gap-9 lg:grid-cols-[minmax(0,380px)_1fr]">
                <!-- PORTADA AMPLIABLE -->
                <div
                    class="flex min-h-60 items-center justify-center overflow-hidden rounded-3xl border border-zinc-200 bg-white p-4 shadow-sm sm:min-h-80 sm:p-7"
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
                            class="max-h-[260px] w-full object-contain transition-transform duration-300 group-hover:scale-105 sm:max-h-[440px]"
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
                            <h2 class="text-lg font-black text-zinc-900">
                                {{ game.name }}
                            </h2>

                            <button
                                type="button"
                                class="rounded-xl bg-zinc-100 px-4 py-2 text-sm font-bold text-zinc-800 hover:bg-zinc-200"
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
                    <p class="text-sm font-bold uppercase tracking-widest text-[#5200FF]">
                        Ficha del juego
                    </p>

                    <h1 class="mt-3 text-3xl font-black leading-tight tracking-tight sm:text-5xl">
                        {{ game.name }}
                    </h1>

                    <div class="mt-5 flex flex-wrap gap-2">
                        <span
                            v-for="collection in game.collections"
                            :key="collection.id"
                            class="rounded-full bg-violet-100 px-3 py-1.5 text-xs font-bold text-violet-700"
                        >
                            {{ collection.name }}
                        </span>
                    </div>

                    <!-- DATOS PRINCIPALES -->
                    <div class="catalog-stats mt-5 grid grid-cols-2 gap-2 sm:mt-8 sm:grid-cols-3 sm:gap-3">
                        <div class="rounded-2xl bg-white p-4 shadow-sm">
                            <p class="text-xs text-zinc-500">Jugadores</p>
                            <p class="mt-2 text-xl font-black">
                                {{ game.min_players }}–{{ game.max_players }}
                            </p>
                        </div>

                        <div class="rounded-2xl bg-white p-4 shadow-sm">
                            <p class="text-xs text-zinc-500">Duración</p>
                            <p class="mt-2 text-lg font-black">
                                {{ duration() }}
                            </p>
                        </div>

                        <div class="rounded-2xl bg-white p-4 shadow-sm">
                            <p class="text-xs text-zinc-500">Dificultad</p>
                            <p class="mt-2 text-xl font-black">
                                {{
                                    game.complexity !== null
                                        ? Number(game.complexity).toFixed(1) + ' / 5'
                                        : 'Sin datos'
                                }}
                            </p>
                        </div>
                    </div>

                    <!-- JUGADORES IDEALES -->
                    <div
                        v-if="game.ideal_player_counts.length"
                        class="mt-4 rounded-2xl border border-lime-200 bg-lime-50 p-4 sm:mt-6 sm:p-5"
                    >
                        <p class="text-sm font-bold text-zinc-700">
                            ⭐ Mejor número de jugadores
                        </p>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <span
                                v-for="count in game.ideal_player_counts"
                                :key="count"
                                class="rounded-xl bg-[#CCFF00] px-4 py-2 text-base font-black"
                            >
                                {{ count }}
                            </span>
                        </div>
                    </div>

                    <p class="mt-5 text-sm font-medium text-zinc-500">
                        Modalidad: {{ gameMode() }}
                    </p>

                    <!-- ENLACES EXTERNOS -->
                    <div class="mt-5 flex flex-wrap gap-2 sm:mt-7 sm:gap-3">
                        <button
                            type="button"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#181424] px-5 py-3 text-sm font-bold text-white transition hover:bg-[#5200FF] sm:w-auto"
                            @click="shareGame"
                        >
                            <span aria-hidden="true">🔗</span>
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
                            class="block w-full rounded-xl bg-[#5200FF] px-5 py-3 text-center text-sm font-bold text-white transition hover:bg-[#4000cc] sm:w-auto"
                        >
                            Ver en BoardGameGeek ↗
                        </a>

                        <a
                            v-if="externalUrl(game.youtube_url)"
                            :href="externalUrl(game.youtube_url)"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="block w-full rounded-xl border border-zinc-300 bg-white px-5 py-3 text-center text-sm font-bold transition hover:bg-zinc-100 sm:w-auto"
                        >
                            ▶ Ver vídeo
                        </a>
                    </div>
                </div>
            </section>

            <!-- DESCRIPCIÓN -->
            <section
                v-if="game.full_description || game.short_description || game.additional_information"
                class="mt-7 rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm sm:mt-10 sm:p-8"
            >
                <h2 class="text-2xl font-black">
                    Sobre el juego
                </h2>

                <div
                    v-if="game.full_description || game.short_description"
                    class="mt-5 max-w-3xl"
                >
                    <p
                        class="whitespace-pre-line text-base leading-8 text-zinc-700"
                        :class="{
                            'line-clamp-6':
                                !showFullDescription &&
                                (game.full_description || game.short_description || '').length > 420
                        }"
                    >
                        {{ game.full_description || game.short_description }}
                    </p>

                    <button
                        v-if="(game.full_description || game.short_description || '').length > 420"
                        type="button"
                        class="mt-4 rounded-xl bg-violet-50 px-4 py-2 text-sm font-bold text-[#5200FF] transition hover:bg-violet-100"
                        :aria-expanded="showFullDescription"
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
            <section v-if="game.expansions.length > 0" class="mt-8 sm:mt-12">
                <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-bold uppercase tracking-wider text-[#5200FF]">
                            Amplía la experiencia
                        </p>
                        <h2 class="mt-2 text-2xl font-black">
                            Expansiones
                        </h2>
                    </div>

                    <span class="rounded-full bg-white px-4 py-2 text-sm font-bold text-zinc-600">
                        {{ game.expansions.length }} expansiones
                    </span>
                </div>

                <div
                    v-if="game.expansions.length"
                    class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <article
                        v-for="expansion in game.expansions"
                        :key="expansion.id"
                        class="group flex h-full flex-col overflow-hidden rounded-3xl border border-zinc-200 bg-white shadow-sm transition duration-300 hover:-translate-y-1 hover:shadow-lg"
                    >
                        <!-- PORTADA -->
                        <div
                            class="relative flex aspect-square items-center justify-center overflow-hidden bg-gradient-to-br from-[#f0ebff] via-white to-[#eef2fa] p-6"
                        >
                            <img
                                v-if="expansion.image_url && !brokenExpansionIds.includes(expansion.id)"
                                :src="expansion.image_url"
                                @error="markExpansionAsBroken(expansion.id)"
                                :alt="`Portada de ${expansion.name}`"
                                loading="lazy"
                                class="h-full w-full object-contain drop-shadow-xl transition-transform duration-500 group-hover:scale-105"
                            />

                            <div
                                v-else
                                class="flex flex-col items-center gap-2 text-zinc-400"
                            >
                                <span class="text-5xl">🎲</span>
                                <span class="text-sm">Sin portada disponible</span>
                            </div>

                            <span
                                class="absolute left-3 top-3 rounded-full bg-[#181424] px-3 py-1.5 text-xs font-bold text-white shadow-md"
                            >
                                Expansión
                            </span>
                        </div>

                        <!-- INFORMACIÓN -->
                        <div class="flex flex-1 flex-col p-5">
                            <p class="text-xs font-semibold uppercase tracking-wider text-[#5200FF]">
                                Ampliación del juego base
                            </p>

                            <h3 class="mt-2 text-lg font-black leading-snug">
                                {{ expansion.name }}
                            </h3>

                            <p class="mt-2 text-sm text-zinc-500">
                                {{ game.name }}
                            </p>

                            <!-- ENLACES -->
                            <div class="mt-auto flex flex-wrap gap-2 pt-6">
                                <a
                                    v-if="externalUrl(expansion.bgg_url)"
                                    :href="externalUrl(expansion.bgg_url)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="rounded-xl bg-[#5200FF] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#4000cc]"
                                >
                                    Ver en BGG ↗
                                </a>

                                <a
                                    v-if="externalUrl(expansion.youtube_url)"
                                    :href="externalUrl(expansion.youtube_url)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="rounded-xl border border-zinc-200 px-4 py-2.5 text-sm font-bold text-zinc-700 transition hover:bg-zinc-100"
                                >
                                    ▶ Ver vídeo
                                </a>
                            </div>
                        </div>
                    </article>
                </div>

                <p v-else class="rounded-2xl bg-white p-6 text-sm text-zinc-500">
                    Este juego no tiene expansiones registradas.
                </p>
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
            Boardgame Vote · Biblioteca compartida
        </footer>

        <ScrollToTopProgress :remember-position="false" />
    </div>
</template>

<style scoped>

/* La última característica ocupa toda la fila cuando queda sola. */
@media (max-width: 639px) {
    .catalog-stats > :last-child:nth-child(odd) {
        grid-column: 1 / -1;
    }
}
</style>
