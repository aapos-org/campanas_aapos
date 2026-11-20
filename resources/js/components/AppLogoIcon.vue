<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- Envelope with a send arrow: neutral mark of our own. -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        :class="className"
        v-bind="$attrs"
        aria-hidden="true"
    >
        <path
            d="M3 5.5A2.5 2.5 0 0 1 5.5 3h13A2.5 2.5 0 0 1 21 5.5V12h-2V7.2l-6.4 4.6a1 1 0 0 1-1.2 0L5 7.2v11.3c0 .3.2.5.5.5H12v2H5.5A2.5 2.5 0 0 1 3 18.5v-13Zm2.6-.5L12 9.8 18.4 5H5.6Z"
        />
        <path
            d="M14 14h5.6l-2.3-2.3 1.4-1.4L23.4 15l-4.7 4.7-1.4-1.4 2.3-2.3H14v-2Z"
        />
    </svg>
</template>
