<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

const props = withDefaults(defineProps<{ rememberPosition?: boolean; hideOnMobile?: boolean; compact?: boolean; compactOnMobile?: boolean; hideNearBottom?: boolean }>(), {
    rememberPosition: true,
    hideOnMobile: false,
    compact: false,
    compactOnMobile: false,
    hideNearBottom: false,
});

const scrollY = ref(0);
const progress = ref(0);
const nearBottom = ref(false);

const circumference = 2 * Math.PI * 20;

const progressOffset = computed(
    () => circumference * (1 - progress.value / 100),
);

const visible = computed(
    () => scrollY.value > 300 && !(props.hideNearBottom && nearBottom.value),
);

let storageKey = '';

const updateScroll = () => {
    scrollY.value = window.scrollY;

    const maxScroll =
        document.documentElement.scrollHeight -
        window.innerHeight;

    // Ocultar el botón flotante en el tramo final de formularios largos
    // para evitar que se superponga al botón principal de envío.
    nearBottom.value = maxScroll - scrollY.value <= window.innerHeight * 0.85;

    progress.value = maxScroll > 0
        ? Math.min(100, (scrollY.value / maxScroll) * 100)
        : 0;

    if (props.rememberPosition) {
        sessionStorage.setItem(storageKey, String(scrollY.value));
    }
};

const scrollToTop = () => {
    window.scrollTo({
        top: 0,
        behavior: 'smooth',
    });
};

onMounted(async () => {
    // El catálogo recuerda la posición; un formulario nuevo no debe
    // regresar a una posición antigua con su selección vacía.
    storageKey = `catalog-scroll:${window.location.pathname}`;

    const savedPosition = props.rememberPosition
        ? Number(sessionStorage.getItem(storageKey) ?? 0)
        : 0;

    await nextTick();

    // Restaurar después de que Vue haya montado la página.
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            if (Number.isFinite(savedPosition) && savedPosition > 0) {
                window.scrollTo(0, savedPosition);
            }

            updateScroll();
        });
    });

    window.addEventListener('scroll', updateScroll, {
        passive: true,
    });

    window.addEventListener('resize', updateScroll);
});

onBeforeUnmount(() => {
    if (props.rememberPosition) {
        sessionStorage.setItem(storageKey, String(window.scrollY));
    }

    window.removeEventListener('scroll', updateScroll);
    window.removeEventListener('resize', updateScroll);
});
</script>

<template>
    <Transition
        enter-active-class="transition duration-300 ease-out"
        enter-from-class="translate-y-4 opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition duration-200"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <button
            v-if="visible"
            type="button"
            aria-label="Volver al inicio"
            title="Volver arriba"
            class="fixed z-50 items-center justify-center rounded-full bg-[#181424] text-white shadow-xl transition hover:scale-110 hover:shadow-2xl"
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
</template>
