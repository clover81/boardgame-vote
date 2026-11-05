<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

const scrollY = ref(0);
const progress = ref(0);

const circumference = 2 * Math.PI * 20;

const progressOffset = computed(
    () => circumference * (1 - progress.value / 100),
);

const visible = computed(() => scrollY.value > 300);

let storageKey = '';

const updateScroll = () => {
    scrollY.value = window.scrollY;

    const maxScroll =
        document.documentElement.scrollHeight -
        window.innerHeight;

    progress.value = maxScroll > 0
        ? Math.min(100, (scrollY.value / maxScroll) * 100)
        : 0;

    sessionStorage.setItem(storageKey, String(scrollY.value));
};

const scrollToTop = () => {
    window.scrollTo({
        top: 0,
        behavior: 'smooth',
    });
};

onMounted(async () => {
    // Cada catálogo conserva su propia posición.
    storageKey = `catalog-scroll:${window.location.pathname}`;

    const savedPosition = Number(
        sessionStorage.getItem(storageKey) ?? 0,
    );

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
    sessionStorage.setItem(storageKey, String(window.scrollY));

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
            class="fixed bottom-6 right-5 z-50 flex h-14 w-14 items-center justify-center rounded-full bg-[#181424] text-white shadow-xl transition hover:scale-110 hover:shadow-2xl sm:bottom-8 sm:right-8"
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
                class="relative h-6 w-6"
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
