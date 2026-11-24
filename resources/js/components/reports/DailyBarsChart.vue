<script setup lang="ts">
import { computed, ref } from 'vue';
import { useFormat } from '@/composables/useFormat';

/**
 * Daily bars (one series at a time) with an average line, the best day highlighted and a
 * hover tooltip. A table view keeps the data available without the chart.
 */
const props = defineProps<{
    points: { date: string; value: number }[];
    valueLabel: string;
}>();

const { number } = useFormat();
const active = ref<number | null>(null);

const max = computed(() => Math.max(1, ...props.points.map((p) => p.value)));
const total = computed(() => props.points.reduce((a, p) => a + p.value, 0));
const average = computed(() =>
    props.points.length ? total.value / props.points.length : 0,
);
const best = computed(() =>
    props.points.reduce(
        (b, p, i) => (p.value > (props.points[b]?.value ?? -1) ? i : b),
        0,
    ),
);
// Rounded ceiling for the axis (e.g. 87 → 100, 1,240 → 1,500).
const ceiling = computed(() => {
    const m = max.value;
    const step = 10 ** Math.floor(Math.log10(m));
    const nice = [1, 2, 2.5, 5, 10].find((n) => n * step >= m) ?? 10;

    return nice * step;
});
const ticks = computed(() => [
    ...new Set(
        [0, 0.25, 0.5, 0.75, 1].map((f) => Math.round(ceiling.value * f)),
    ),
]);

const day = (iso: string, withWeekday = false) =>
    new Intl.DateTimeFormat('es-GT', {
        day: 'numeric',
        month: 'short',
        weekday: withWeekday ? 'short' : undefined,
        timeZone: 'UTC',
    }).format(new Date(`${iso}T12:00:00Z`));

const pct = (v: number) => `${(v * 100) / ceiling.value}%`;
</script>

<template>
    <figure class="space-y-3">
        <div class="relative h-56 pl-10">
            <p
                v-if="total === 0"
                class="absolute inset-0 z-10 flex items-center justify-center pl-10 text-sm text-muted-foreground"
            >
                Sin actividad en los últimos 30 días.
            </p>
            <!-- Recessive grid and axis labels -->
            <div
                v-for="t in ticks"
                :key="t"
                class="pointer-events-none absolute right-0 left-10 border-t border-dashed border-border"
                :style="{ bottom: pct(t) }"
                aria-hidden="true"
            >
                <span
                    class="absolute -top-2 -left-10 w-8 text-right text-[10px] text-muted-foreground tabular-nums"
                    >{{ number(t) }}</span
                >
            </div>

            <!-- Average -->
            <div
                v-if="average > 0"
                class="pointer-events-none absolute right-0 left-10 z-10 border-t-2 border-primary/60"
                :style="{ bottom: pct(average) }"
                aria-hidden="true"
            >
                <span
                    class="absolute -top-3 right-0 rounded-full bg-primary px-2 py-0.5 text-[10px] font-medium text-primary-foreground"
                    >prom. {{ number(Math.round(average)) }}</span
                >
            </div>

            <div
                class="relative flex h-full items-end gap-[3px]"
                role="img"
                :aria-label="`${valueLabel} por día en los últimos 30 días: total ${number(total)}, promedio ${number(Math.round(average))}. La tabla siguiente tiene los datos.`"
                @mouseleave="active = null"
            >
                <div
                    v-for="(p, i) in points"
                    :key="p.date"
                    class="relative flex h-full min-w-0 flex-1 items-end"
                    @mouseenter="active = i"
                >
                    <div
                        class="w-full origin-bottom rounded-t-[5px] transition-[opacity,transform] duration-300"
                        :class="[
                            i === best && p.value > 0
                                ? 'bg-gradient-to-t from-primary/70 to-primary'
                                : 'bg-gradient-to-t from-chart-2/35 to-chart-2/85',
                            active !== null && active !== i ? 'opacity-45' : '',
                        ]"
                        :style="{
                            height: p.value ? `max(3px, ${pct(p.value)})` : '0',
                            animation: `grow-bar 0.7s cubic-bezier(.2,.7,.2,1) ${i * 18}ms both`,
                        }"
                    />
                    <span
                        v-if="i === best && p.value > 0 && active === null"
                        class="absolute left-1/2 z-20 -translate-x-1/2 -translate-y-full rounded-full bg-foreground px-2 py-0.5 text-[10px] font-medium whitespace-nowrap text-background"
                        :style="{ bottom: `calc(${pct(p.value)} + 6px)` }"
                        >Mejor día · {{ number(p.value) }}</span
                    >
                </div>
            </div>

            <div
                v-if="active !== null && points[active]"
                class="pointer-events-none absolute top-0 left-1/2 z-30 -translate-x-1/2 rounded-lg border border-[var(--glass-border)] bg-popover px-3 py-1.5 text-xs text-popover-foreground shadow-lg"
            >
                {{ day(points[active].date, true) }} ·
                <strong class="tabular-nums">{{
                    number(points[active].value)
                }}</strong>
                {{ valueLabel.toLowerCase() }}
            </div>
        </div>

        <div
            class="flex justify-between pl-10 text-[10px] text-muted-foreground"
            aria-hidden="true"
        >
            <span v-for="i in [0, 7, 14, 21, 29]" :key="i">{{
                points[i] ? (i === 29 ? 'Hoy' : day(points[i].date)) : ''
            }}</span>
        </div>

        <details class="text-xs">
            <summary class="cursor-pointer text-muted-foreground">
                Ver tabla
            </summary>
            <table class="mt-2 w-full">
                <thead>
                    <tr class="text-left text-muted-foreground">
                        <th class="py-1 font-normal">Día</th>
                        <th class="py-1 text-right font-normal">
                            {{ valueLabel }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in points" :key="p.date" class="border-t">
                        <td class="py-1">{{ day(p.date, true) }}</td>
                        <td class="py-1 text-right tabular-nums">
                            {{ number(p.value) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </details>
    </figure>
</template>

<style scoped>
@keyframes grow-bar {
    from {
        transform: scaleY(0);
        opacity: 0;
    }
    to {
        transform: scaleY(1);
        opacity: 1;
    }
}

@media (prefers-reduced-motion: reduce) {
    div[style*='grow-bar'] {
        animation: none !important;
    }
}
</style>
