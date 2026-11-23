<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { ref } from 'vue';

defineProps<{ tags: { tag: string; label: string }[] }>();

const copied = ref<string | null>(null);
const copy = async (tag: string) => {
    await navigator.clipboard.writeText(tag);
    copied.value = tag;
    setTimeout(() => (copied.value = null), 1500);
};
</script>

<template>
    <details class="rounded-lg border p-3 text-sm">
        <summary class="cursor-pointer font-medium">
            Variables de personalización
        </summary>
        <p class="mt-2 text-muted-foreground">
            Cópialas y pégalas en el texto. Si el contacto no tiene el dato, se
            usa el texto entre comillas.
        </p>
        <ul class="mt-2 grid gap-1 sm:grid-cols-2">
            <li v-for="t in tags" :key="t.tag">
                <button
                    type="button"
                    class="flex w-full items-center gap-2 rounded px-2 py-1 text-left hover:bg-muted"
                    @click="copy(t.tag)"
                >
                    <Check
                        v-if="copied === t.tag"
                        class="size-3.5 text-emerald-600"
                        aria-hidden="true"
                    />
                    <Copy
                        v-else
                        class="size-3.5 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <code class="text-xs">{{ t.tag }}</code>
                    <span class="truncate text-xs text-muted-foreground">{{
                        t.label
                    }}</span>
                </button>
            </li>
        </ul>
    </details>
</template>
