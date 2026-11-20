<script setup lang="ts">
// Native <select>: accessible, works well on mobile and with screen readers.
const model = defineModel<string | number | null>();

defineProps<{
    id?: string;
    name?: string;
    required?: boolean;
    disabled?: boolean;
}>();
</script>

<template>
    <select
        :id="id"
        v-model="model"
        :name="name"
        :required="required"
        :disabled="disabled"
        class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:opacity-50 dark:bg-input/30"
    >
        <slot />
    </select>
</template>
