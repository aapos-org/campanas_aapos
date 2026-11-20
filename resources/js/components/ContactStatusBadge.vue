<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';

const props = defineProps<{
    status: string;
    label: string;
}>();

const classes = computed(
    () =>
        ({
            subscribed:
                'border-transparent bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200',
            pending:
                'border-transparent bg-sky-100 text-sky-900 dark:bg-sky-950 dark:text-sky-200',
            unsubscribed:
                'border-transparent bg-zinc-200 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200',
            cleaned:
                'border-transparent bg-red-100 text-red-900 dark:bg-red-950 dark:text-red-200',
            non_subscribed: '',
        })[props.status] ?? '',
);
</script>

<template>
    <Badge variant="outline" :class="classes">{{ label }}</Badge>
</template>
