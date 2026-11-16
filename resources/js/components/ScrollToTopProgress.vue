<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

const props = withDefaults(defineProps<{ rememberPosition?: boolean; hideOnMobile?: boolean; compact?: boolean; compactOnMobile?: boolean; hideNearBottom?: boolean; hideNearBottomDistance?: number; showAfter?: number }>(), {
    rememberPosition: true,
    hideOnMobile: false,
    compact: false,
    compactOnMobile: false,
    hideNearBottom: false,
    showAfter: 180,
});

const scrollY = ref(0);
const progress = ref(0);
const nearBottom = ref(false);

const circumference = 2 * Math.PI * 20;

const progressOffset = computed(
    () => circumference * (1 - progress.value / 100),
);

const visible = computed(
    () => scrollY.value > props.showAfter && !(props.hideNearBottom && nearBottom.value),
);

let storageKey = '';
let mounted = false;
let restoreFrame = 0;
let resizeObserver: ResizeObserver | null = null;

const savePosition = (position: number) => {
    if (!props.rememberPosition) return;

    try {
        sessionStorage.setItem(storageKey, String(position));
    } catch {
        // El botón sigue funcionando aunque no se pueda guardar la posición.
    }
};

// En páginas habituales se desplaza la ventana. Si algún layout usa
// un <main> con desplazamiento propio, usamos ese contenedor en su lugar.
const getScrollHost = (): HTMLElement | null => {
    const content = document.querySelector<HTMLElement>('main[data-slot="sidebar-inset"]');
    if (content && content.scrollHeight > content.clientHeight + 1) {
        const overflowY = window.getComputedStyle(content).overflowY;
        if (overflowY === 'auto' || overflowY === 'scroll') return content;
    }
    return document.scrollingElement as HTMLElement | null;
};

const scrollPageTo = (top: number, behavior: ScrollBehavior = 'auto') => {
    const host = getScrollHost();
    if (host && host !== document.scrollingElement) {
        host.scrollTo({ top, behavior });
    } else {
        window.scrollTo({ top, behavior });
    }
};

const updateScroll = () => {
    const host = getScrollHost();
    scrollY.value = host?.scrollTop ?? window.scrollY;

    const maxScroll = host
        ? Math.max(0, host.scrollHeight - host.clientHeight)
        : Math.max(0, document.documentElement.scrollHeight - window.innerHeight);

    // Ocultar el botón flotante en el tramo final de formularios largos
    // para evitar que se superponga al botón principal de envío.
    const hideDistance = props.hideNearBottomDistance ?? window.innerHeight * 0.85;
    nearBottom.value = maxScroll - scrollY.value <= hideDistance;

    progress.value = maxScroll > 0
        ? Math.min(100, (scrollY.value / maxScroll) * 100)
        : 0;

    savePosition(scrollY.value);
};

const scrollToTop = (event: MouseEvent) => {
    if (event.detail === 0) {
        const content = document.querySelector<HTMLElement>('main');

        if (content) {
            if (!content.hasAttribute('tabindex')) {
                content.setAttribute('tabindex', '-1');
                content.addEventListener('blur', () => content.removeAttribute('tabindex'), { once: true });
            }

            content.focus({ preventScroll: true });
        }
    }

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    scrollPageTo(0, reduceMotion ? 'instant' : 'smooth');
};

onMounted(async () => {
    mounted = true;
    // El catálogo recuerda la posición; un formulario nuevo no debe
    // regresar a una posición antigua con su selección vacía.
    storageKey = `catalog-scroll:${window.location.pathname}`;

    let savedPosition = 0;
    if (props.rememberPosition) {
        try {
            savedPosition = Number(sessionStorage.getItem(storageKey) ?? 0);
        } catch {
            // Sin una posición guardada se usa el desplazamiento actual.
        }
    }

    await nextTick();
    if (!mounted) return;

    // Restaurar después de que Vue haya montado la página.
    restoreFrame = requestAnimationFrame(() => {
        restoreFrame = requestAnimationFrame(() => {
            if (Number.isFinite(savedPosition) && savedPosition > 0) {
                scrollPageTo(savedPosition);
            }

            updateScroll();
        });
    });

    window.addEventListener('scroll', updateScroll, { passive: true });
    // Captura también el desplazamiento de un contenedor interno, si existe.
    document.addEventListener('scroll', updateScroll, true);
    window.addEventListener('resize', updateScroll);

    // La altura puede cambiar al abrir Gestión BGG, filtros y resultados sin
    // que se produzca un resize de ventana.
    resizeObserver = new ResizeObserver(updateScroll);
    resizeObserver.observe(document.documentElement);
    const content = document.querySelector<HTMLElement>('main[data-slot="sidebar-inset"]');
    if (content) resizeObserver.observe(content);
});

onBeforeUnmount(() => {
    mounted = false;
    cancelAnimationFrame(restoreFrame);

    savePosition(getScrollHost()?.scrollTop ?? window.scrollY);

    window.removeEventListener('scroll', updateScroll);
    document.removeEventListener('scroll', updateScroll, true);
    window.removeEventListener('resize', updateScroll);
    resizeObserver?.disconnect();
    resizeObserver = null;
});
</script>

<template>
    <Teleport to="body">
    <Transition
        enter-active-class="transition duration-300 ease-out motion-reduce:transition-none"
        enter-from-class="translate-y-4 opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition duration-200 motion-reduce:transition-none"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <button
            v-if="visible"
            type="button"
            aria-label="Volver al inicio"
            title="Volver arriba"
            class="fixed z-40 items-center justify-center rounded-full bg-[#181424] text-white shadow-xl transition motion-safe:hover:scale-110 hover:shadow-2xl motion-reduce:transition-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF] dark:focus-visible:outline-[#CCFF00]"
            :class="[
                props.hideOnMobile ? 'hidden sm:flex' : 'flex',
                props.compact
                    ? 'bottom-3 right-2 h-11 w-11 sm:bottom-4 sm:right-2'
                    : props.compactOnMobile
                      ? 'bottom-3 right-2 h-11 w-11 sm:bottom-8 sm:right-2 sm:h-14 sm:w-14'
                      : 'bottom-6 right-2 h-14 w-14 sm:bottom-8 sm:right-2',
            ]"
            @click="scrollToTop"
        >
            <svg
                viewBox="0 0 52 52"
                class="absolute inset-0 h-full w-full"
                aria-hidden="true"
            >
                <!-- Círculo de fondo -->
                <circle
                    cx="26"
                    cy="26"
                    r="20"
                    fill="none"
                    stroke="white"
                    stroke-opacity="0.18"
                    stroke-width="3"
                />

                <!-- Progreso de lectura -->
                <circle
                    cx="26"
                    cy="26"
                    r="20"
                    fill="none"
                    stroke="#CCFF00"
                    stroke-width="3"
                    stroke-linecap="round"
                    :stroke-dasharray="circumference"
                    :stroke-dashoffset="progressOffset"
                    transform="rotate(-90 26 26)"
                />
            </svg>

            <!-- Flecha -->
            <svg
                viewBox="0 0 24 24"
                :class="props.compact
                    ? 'relative h-5 w-5'
                    : props.compactOnMobile
                      ? 'relative h-5 w-5 sm:h-6 sm:w-6'
                      : 'relative h-6 w-6'"
                fill="none"
                stroke="currentColor"
                stroke-width="2.5"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
            >
                <path d="M12 19V5" />
                <path d="m5 12 7-7 7 7" />
            </svg>
        </button>
    </Transition>
    </Teleport>
</template>
