<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <main
    data-slot="sidebar-inset"
    :class="cn(
      'bg-background relative flex w-full flex-1 flex-col backdrop-blur-2xl',
      'md:peer-data-[variant=inset]:m-3 md:peer-data-[variant=inset]:ml-0 md:peer-data-[variant=inset]:rounded-3xl md:peer-data-[variant=inset]:border md:peer-data-[variant=inset]:border-[var(--glass-border)] md:peer-data-[variant=inset]:shadow-[var(--glass-shadow)] md:peer-data-[variant=inset]:peer-data-[state=collapsed]:ml-3',
      props.class,
    )"
  >
    <slot />
  </main>
</template>
