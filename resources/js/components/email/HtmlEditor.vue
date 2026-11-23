<script setup lang="ts">
/** HTML code editing with a live preview in a sandboxed iframe (no scripts). */
import { refDebounced } from '@vueuse/core';

const model = defineModel<string>({ required: true });
const debounced = refDebounced(model, 400);
</script>

<template>
    <div class="grid min-h-[560px] gap-4 lg:grid-cols-2">
        <div class="grid gap-1.5">
            <label for="html-code" class="text-sm font-medium"
                >Código HTML</label
            >
            <textarea
                id="html-code"
                v-model="model"
                spellcheck="false"
                class="min-h-[520px] w-full rounded-lg border border-input bg-transparent p-3 font-mono text-xs leading-5 dark:bg-input/30"
            />
        </div>
        <div class="grid gap-1.5">
            <span class="text-sm font-medium">Vista previa</span>
            <iframe
                :srcdoc="debounced"
                sandbox=""
                title="Vista previa del HTML"
                class="min-h-[520px] w-full rounded-lg border bg-white"
            />
        </div>
    </div>
</template>
