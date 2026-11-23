<script setup lang="ts">
import { computed, ref } from 'vue';
import { useFormat } from '@/composables/useFormat';

/**
 * Single-series bar chart (one hue, thin bars anchored to the baseline) with a hover
 * tooltip and an accessible table view. Two measures are shown as two charts, never one
 * chart with two scales.
 */
const props = defineProps<{
    title: string;
    points: { label: string; value: number }[];
    valueLabel: string;
}>();

const { number } = useFormat();
const active = ref<number | null>(null);
const max = computed(() => Math.max(1, ...props.points.map((p) => p.value)));
const total = computed(() => props.points.reduce((a, p) => a + p.value, 0));
</script>

<template>
    <figure class="space-y-2">
        <figcaption class="flex items-baseline justify-between gap-2">
            <span class="text-sm font-medium">{{ title }}</span>
            <span class="text-xs text-muted-foreground tabular-nums"
                >Total: {{ number(total) }}</span
            >
        </figcaption>
        <div class="relative">
            <div
                class="flex h-32 items-end gap-0.5 border-b border-border"
                role="img"
                :aria-label="`${title}: gráfico de barras, total ${number(total)}. La tabla siguiente tiene los datos.`"
                @mouseleave="active = null"
            >
                <div
                    v-for="(p, i) in points"
                    :key="p.label"
                    class="flex h-full min-w-0 flex-1 items-end"
                    @mouseenter="active = i"
                >
                    <div
                        class="w-full rounded-t-[4px] bg-primary transition-opacity"
                        :class="{
                            'opacity-50': active !== null && active !== i,
                        }"
                        :style="{
                            height: p.value
                                ? `max(2px, ${(p.value * 100) / max}%)`
                                : '0',
                        }"
                    />
                </div>
            </div>
            <div
                v-if="active !== null && points[active]"
                class="pointer-events-none absolute -top-2 left-1/2 -translate-x-1/2 rounded-md border bg-popover px-2 py-1 text-xs whitespace-nowrap text-popover-foreground shadow-sm"
            >
                {{ points[active].label }} ·
                <strong class="tabular-nums">{{
                    number(points[active].value)
                }}</strong>
                {{ valueLabel }}
            </div>
        </div>
        <div
            class="flex justify-between text-[11px] text-muted-foreground tabular-nums"
            aria-hidden="true"
        >
            <span>{{ points[0]?.label }}</span>
            <span>{{ points[points.length - 1]?.label }}</span>
        </div>
        <details class="text-xs">
            <summary class="cursor-pointer text-muted-foreground">
                Ver tabla
            </summary>
            <table class="mt-2 w-full">
                <thead>
                    <tr class="text-left text-muted-foreground">
                        <th class="py-1 font-normal">Periodo</th>
                        <th class="py-1 text-right font-normal">
                            {{ valueLabel }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in points" :key="p.label" class="border-t">
                        <td class="py-1">{{ p.label }}</td>
                        <td class="py-1 text-right tabular-nums">
                            {{ number(p.value) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </details>
    </figure>
</template>
