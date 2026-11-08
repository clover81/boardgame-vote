<script lang="ts" setup>
import type { ToasterProps } from "vue-sonner"
import { CircleCheckIcon, InfoIcon, Loader2Icon, OctagonXIcon, TriangleAlertIcon, XIcon } from "@lucide/vue"
import { Toaster as Sonner } from "vue-sonner"
import { cn } from "@/lib/utils"

import 'vue-sonner/style.css';

const props = defineProps<ToasterProps>()
</script>

<template>
  <Sonner
    :class="cn('toaster group', props.class)"
    :style="{
      '--normal-bg': 'var(--popover)',
      '--normal-text': 'var(--popover-foreground)',
      '--normal-border': 'var(--border)',
      '--border-radius': 'var(--radius)',
    }"
    v-bind="props"
  >
    <template #success-icon>
      <CircleCheckIcon class="size-4" />
    </template>
    <template #info-icon>
      <InfoIcon class="size-4" />
    </template>
    <template #warning-icon>
      <TriangleAlertIcon class="size-4" />
    </template>
    <template #error-icon>
      <OctagonXIcon class="size-4" />
    </template>
    <template #loading-icon>
      <div>
        <Loader2Icon class="size-4 animate-spin" />
      </div>
    </template>
    <template #close-icon>
      <XIcon class="size-4" />
    </template>
  </Sonner>
</template>


<style>
/* Juegos de mesa: contraste de notificaciones */

[data-sonner-toaster] [data-sonner-toast] {
    background: #111114 !important;
    color: #fafafa !important;
    border: 1px solid #3f3f46 !important;
}

[data-sonner-toaster] [data-sonner-toast] [data-title] {
    color: #fafafa !important;
}

[data-sonner-toaster] [data-sonner-toast] [data-description] {
    color: #e4e4e7 !important;
    opacity: 1 !important;
    line-height: 1.5;
}
</style>
