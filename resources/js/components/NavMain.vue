<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavItem } from '@/types';

defineProps<{
    items: NavItem[];
}>();

const { isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel
            class="px-3 text-[10px] font-bold uppercase tracking-[0.18em] text-[#5200FF]/70 dark:text-[#CCFF00]/70"
        >
            Tu espacio
        </SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="isCurrentUrl(item.href)"
                    :tooltip="item.title"
                    class="h-10 rounded-xl px-3 font-medium transition-colors hover:bg-[#5200FF]/10 hover:text-[#5200FF] data-[active=true]:bg-[#5200FF] data-[active=true]:font-bold data-[active=true]:text-white dark:hover:bg-[#CCFF00]/10 dark:hover:text-[#CCFF00] dark:data-[active=true]:bg-[#CCFF00] dark:data-[active=true]:text-black"
                >
                    <Link :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
