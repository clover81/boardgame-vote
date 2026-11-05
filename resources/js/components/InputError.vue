<script setup lang="ts">
defineProps<{
    message?: string;
}>();
</script>

<template>
    <div
        v-if="message"
        role="alert"
        class="mt-2 flex items-start gap-2.5 rounded-xl border border-red-200 bg-red-50 px-3 py-2.5 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300"
    >
        <svg
            class="mt-0.5 h-4 w-4 shrink-0"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            <circle cx="12" cy="12" r="10" />
            <path d="M12 8v4" />
            <path d="M12 16h.01" />
        </svg>

        <span class="leading-5">
            {{ message }}
        </span>
    </div>
</template>
