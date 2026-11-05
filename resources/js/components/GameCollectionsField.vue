<script setup lang="ts">
import { computed } from 'vue';

interface Collection {
    id: number;
    name: string;
    is_default: boolean;
}

const props = defineProps<{
    collections: Collection[];
    modelValue: number[];
    error?: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: number[]];
}>();

const selectedCollections = computed({
    get: () => props.modelValue,

    set: (value: number[]) => {
        emit(
            'update:modelValue',
            value
        );
    },
});
</script>

<template>
    <section
        class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950"
    >
        <div>
            <p
                class="font-semibold text-[#5200FF]"
            >
                COLECCIONES
            </p>

            <h2
                class="mt-1 text-xl font-bold text-zinc-900 dark:text-zinc-100"
            >
                ¿Dónde estará este juego?
            </h2>

            <p
                class="mt-2 text-sm text-zinc-500 dark:text-zinc-400"
            >
                Puedes incluir el mismo juego en
                varias colecciones.
            </p>
        </div>

        <div
            v-if="collections.length"
            class="mt-5 grid gap-3 sm:grid-cols-2"
        >
            <label
                v-for="collection in collections"
                :key="collection.id"
                class="cursor-pointer"
            >
                <input
                    v-model="
                        selectedCollections
                    "
                    type="checkbox"
                    :value="
                        collection.id
                    "
                    class="peer sr-only"
                />

                <div
                    class="rounded-xl border border-zinc-200 bg-white p-4 transition peer-checked:border-[#5200FF] peer-checked:bg-violet-50 peer-checked:ring-1 peer-checked:ring-[#5200FF] dark:border-zinc-800 dark:bg-zinc-900 dark:peer-checked:bg-violet-950/30"
                >
                    <div
                        class="flex items-center justify-between gap-3"
                    >
                        <span
                            class="font-bold text-zinc-900 dark:text-zinc-100"
                        >
                            {{
                                collection.name
                            }}
                        </span>

                        <span
                            v-if="
                                collection.is_default
                            "
                            class="rounded-full bg-[#CCFF00] px-2 py-1 text-xs font-bold text-black"
                        >
                            Principal
                        </span>
                    </div>

                    <p
                        class="mt-2 text-sm text-zinc-500 dark:text-zinc-400"
                    >
                        {{
                            selectedCollections.includes(
                                collection.id
                            )
                                ? '✓ Incluido'
                                : 'No incluido'
                        }}
                    </p>
                </div>
            </label>
        </div>

        <div
            v-else
            class="mt-5 rounded-xl bg-orange-100 p-4 text-sm font-semibold text-orange-800 dark:bg-orange-950/30 dark:text-orange-300"
        >
            No tienes ninguna colección.
        </div>

        <p
            v-if="error"
            class="mt-3 text-sm text-red-500"
        >
            {{ error }}
        </p>
    </section>
</template>
