<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { content, edit, review } from '@/routes/campaigns';

const props = defineProps<{
    campaignId: number;
    current: 'settings' | 'content' | 'review';
}>();

const steps = [
    {
        key: 'settings',
        label: '1. Configuración',
        href: () => edit(props.campaignId),
    },
    {
        key: 'content',
        label: '2. Contenido',
        href: () => content(props.campaignId),
    },
    {
        key: 'review',
        label: '3. Revisión y envío',
        href: () => review(props.campaignId),
    },
] as const;
</script>

<template>
    <nav aria-label="Pasos de la campaña" class="flex flex-wrap gap-2">
        <Link
            v-for="step in steps"
            :key="step.key"
            :href="step.href()"
            :aria-current="current === step.key ? 'step' : undefined"
            :class="[
                'rounded-full border px-3 py-1 text-sm',
                current === step.key
                    ? 'border-primary bg-primary text-primary-foreground'
                    : 'hover:bg-muted',
            ]"
            >{{ step.label }}</Link
        >
    </nav>
</template>
