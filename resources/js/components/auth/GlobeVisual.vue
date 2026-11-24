<script setup lang="ts">
import { onMounted, ref } from 'vue';

/**
 * Decorative animated globe for the sign-in screen: rotating meridians, an orbiting
 * satellite and light arcs leaving Guatemala (emails travelling). Pure SVG, no images.
 * Animations are not rendered when the user prefers reduced motion.
 */
const animate = ref(false);

onMounted(() => {
    animate.value = !window.matchMedia('(prefers-reduced-motion: reduce)')
        .matches;
});

const R = 200;
const meridians = 8;
const parallels = [-60, -30, 0, 30, 60];

// Points on the visible hemisphere (x, y relative to the centre). Guatemala is the origin.
const origin = { x: -78, y: 38 };
const destinations = [
    { x: -40, y: -70 },
    { x: 60, y: -40 },
    { x: 120, y: 30 },
    { x: 20, y: 110 },
    { x: -140, y: -20 },
];
const arc = (to: { x: number; y: number }) => {
    const mx = (origin.x + to.x) / 2;
    const my = (origin.y + to.y) / 2 - 70;

    return `M ${origin.x} ${origin.y} Q ${mx} ${my} ${to.x} ${to.y}`;
};

// Pseudo-random "city lights" (deterministic so the picture is stable).
const lights = Array.from({ length: 70 }, (_, i) => {
    const a = (i * 137.5 * Math.PI) / 180;
    const r = Math.sqrt((i + 0.5) / 70) * (R - 14);

    return { x: Math.cos(a) * r, y: Math.sin(a) * r, d: (i % 7) * 0.6 };
});
</script>

<template>
    <svg
        viewBox="-260 -260 520 520"
        class="h-full w-full"
        role="img"
        aria-label="Globo terráqueo con correos que salen de Guatemala hacia el mundo"
    >
        <defs>
            <radialGradient id="globe-fill" cx="35%" cy="30%" r="75%">
                <stop
                    offset="0%"
                    stop-color="oklch(0.55 0.16 240)"
                    stop-opacity="0.55"
                />
                <stop
                    offset="55%"
                    stop-color="oklch(0.3 0.12 255)"
                    stop-opacity="0.55"
                />
                <stop
                    offset="100%"
                    stop-color="oklch(0.14 0.06 265)"
                    stop-opacity="0.9"
                />
            </radialGradient>
            <radialGradient id="globe-glow" cx="50%" cy="50%" r="50%">
                <stop
                    offset="70%"
                    stop-color="oklch(0.75 0.15 225)"
                    stop-opacity="0"
                />
                <stop
                    offset="88%"
                    stop-color="oklch(0.75 0.15 225)"
                    stop-opacity="0.35"
                />
                <stop
                    offset="100%"
                    stop-color="oklch(0.75 0.15 225)"
                    stop-opacity="0"
                />
            </radialGradient>
            <linearGradient id="arc-stroke" x1="0" x2="1">
                <stop
                    offset="0%"
                    stop-color="oklch(0.85 0.12 220)"
                    stop-opacity="0.1"
                />
                <stop
                    offset="100%"
                    stop-color="oklch(0.9 0.12 210)"
                    stop-opacity="1"
                />
            </linearGradient>
            <clipPath id="globe-clip">
                <circle :r="R" />
            </clipPath>
        </defs>

        <!-- Halo and sphere -->
        <circle :r="R + 40" fill="url(#globe-glow)" />
        <circle
            :r="R"
            fill="url(#globe-fill)"
            stroke="oklch(0.8 0.1 230 / 0.45)"
            stroke-width="1.2"
        />

        <g
            clip-path="url(#globe-clip)"
            fill="none"
            stroke="oklch(0.8 0.1 230 / 0.22)"
            stroke-width="0.8"
        >
            <!-- Parallels -->
            <ellipse
                v-for="lat in parallels"
                :key="lat"
                :cy="Math.sin((lat * Math.PI) / 180) * R"
                :rx="Math.cos((lat * Math.PI) / 180) * R"
                :ry="Math.cos((lat * Math.PI) / 180) * R * 0.16"
            />
            <!-- Meridians (rx oscillates to suggest rotation) -->
            <ellipse
                v-for="m in meridians"
                :key="`m${m}`"
                :rx="R * Math.abs(Math.cos((m * Math.PI) / meridians))"
                :ry="R"
            >
                <animate
                    v-if="animate"
                    attributeName="rx"
                    :values="`${R};0;${R}`"
                    dur="18s"
                    :begin="`-${(m * 18) / meridians}s`"
                    repeatCount="indefinite"
                />
            </ellipse>

            <!-- City lights -->
            <circle
                v-for="(l, i) in lights"
                :key="`l${i}`"
                :cx="l.x"
                :cy="l.y"
                r="1.4"
                fill="oklch(0.85 0.12 215)"
                stroke="none"
                opacity="0.55"
            >
                <animate
                    v-if="animate"
                    attributeName="opacity"
                    values="0.15;0.9;0.15"
                    dur="4s"
                    :begin="`${l.d}s`"
                    repeatCount="indefinite"
                />
            </circle>
        </g>

        <!-- Arcs from Guatemala -->
        <g
            fill="none"
            stroke="url(#arc-stroke)"
            stroke-width="1.6"
            stroke-linecap="round"
        >
            <path
                v-for="(d, i) in destinations"
                :key="`a${i}`"
                :d="arc(d)"
                pathLength="100"
                stroke-dasharray="100"
                :stroke-dashoffset="animate ? 100 : 0"
            >
                <animate
                    v-if="animate"
                    attributeName="stroke-dashoffset"
                    values="100;0;0;-100"
                    keyTimes="0;0.45;0.7;1"
                    dur="5s"
                    :begin="`${i * 0.9}s`"
                    repeatCount="indefinite"
                />
            </path>
        </g>
        <circle
            v-for="(d, i) in destinations"
            :key="`d${i}`"
            :cx="d.x"
            :cy="d.y"
            r="3"
            fill="oklch(0.9 0.1 210)"
        />
        <!-- Guatemala -->
        <circle
            :cx="origin.x"
            :cy="origin.y"
            r="5"
            fill="oklch(0.92 0.1 200)"
        />
        <circle
            :cx="origin.x"
            :cy="origin.y"
            r="5"
            fill="none"
            stroke="oklch(0.92 0.1 200)"
            stroke-width="1.5"
        >
            <animate
                v-if="animate"
                attributeName="r"
                values="5;22"
                dur="2.4s"
                repeatCount="indefinite"
            />
            <animate
                v-if="animate"
                attributeName="opacity"
                values="0.9;0"
                dur="2.4s"
                repeatCount="indefinite"
            />
        </circle>

        <!-- Orbit and satellite -->
        <ellipse
            rx="245"
            ry="70"
            fill="none"
            stroke="oklch(0.8 0.1 230 / 0.25)"
            stroke-width="1"
            transform="rotate(-18)"
        />
        <circle r="4" fill="oklch(0.95 0.06 210)" transform="rotate(-18)">
            <animateMotion
                v-if="animate"
                dur="14s"
                repeatCount="indefinite"
                path="M 245 0 A 245 70 0 1 1 -245 0 A 245 70 0 1 1 245 0"
            />
        </circle>
    </svg>
</template>
