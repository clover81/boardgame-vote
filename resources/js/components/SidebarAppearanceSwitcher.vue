<script setup lang="ts">
import { computed } from 'vue';
import { Check, Monitor, Moon, Sun, SunMoon } from '@lucide/vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useAppearance, type Appearance } from '@/composables/useAppearance';

const { appearance, updateAppearance } = useAppearance();
const { state, isMobile } = useSidebar();
const collapsed = computed(() => state.value === 'collapsed' && !isMobile.value);

const options = [
    { value: 'light', label: 'Claro', icon: Sun },
    { value: 'dark', label: 'Oscuro', icon: Moon },
    { value: 'system', label: 'Sistema', icon: Monitor },
] as const;

const activeLabel = computed(() =>
    options.find((option) => option.value === appearance.value)?.label ?? 'Sistema',
);

function chooseAppearance(value: Appearance) {
    updateAppearance(value);
}
</script>

<template>
    <!-- En el sidebar contraído, mantenemos el selector accesible con un menú. -->
    <SidebarMenu v-if="collapsed">
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton
                        tooltip="Apariencia"
                        :aria-label="`Apariencia: ${activeLabel}`"
                    >
                        <SunMoon class="size-4 text-[#CCFF00]" aria-hidden="true" />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent side="right" align="start" :side-offset="8">
                    <DropdownMenuItem
                        v-for="option in options"
                        :key="option.value"
                        @select="chooseAppearance(option.value)"
                    >
                        <component :is="option.icon" class="mr-2 size-4" aria-hidden="true" />
                        <span>{{ option.label }}</span>
                        <Check
                            v-if="appearance === option.value"
                            class="ml-auto size-4 text-[#5200FF] dark:text-[#CCFF00]"
                            aria-hidden="true"
                        />
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>

    <!-- Abierto y en móvil: tres opciones visibles, sin navegar a Ajustes. -->
    <div v-else class="px-2 pb-2">
        <div class="mb-2 flex items-center gap-2 px-1 text-xs font-semibold text-sidebar-foreground">
            <SunMoon class="size-4 text-[#5200FF] dark:text-[#CCFF00]" aria-hidden="true" />
            <span>Apariencia</span>
        </div>
        <div class="flex gap-1 rounded-xl border border-border bg-muted/40 p-1" role="group" aria-label="Apariencia">
            <button
                v-for="option in options"
                :key="option.value"
                type="button"
                :title="option.label"
                :aria-pressed="appearance === option.value"
                class="flex min-w-0 flex-1 flex-col items-center justify-center gap-1 rounded-lg px-1 py-2 text-[11px] font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#5200FF]"
                :class="
                    appearance === option.value
                        ? 'bg-[#5200FF] text-white shadow-sm'
                        : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground'
                "
                @click="chooseAppearance(option.value)"
            >
                <component :is="option.icon" class="size-4 shrink-0" aria-hidden="true" />
                <span>{{ option.label }}</span>
            </button>
        </div>
    </div>
</template>
