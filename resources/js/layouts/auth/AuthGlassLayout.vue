<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { BarChart3, MailCheck, ShieldCheck } from '@lucide/vue';
import GlobeVisual from '@/components/auth/GlobeVisual.vue';
import { home } from '@/routes';

/**
 * Sign-in screens: deep blue background with an animated globe and a glass card.
 * Always uses the dark glass look, regardless of the user's appearance setting.
 */
defineProps<{
    title?: string;
    description?: string;
}>();

const appName =
    (usePage().props.name as string | undefined) ?? 'Campañas AAPOS';

const chips = [
    {
        icon: MailCheck,
        title: 'Envíos por lotes',
        text: 'Sin repetir a nadie',
        class: 'top-[16%] left-[6%]',
        delay: '0s',
    },
    {
        icon: BarChart3,
        title: 'Aperturas y clics',
        text: 'Rastreo propio',
        class: 'top-[48%] right-[4%]',
        delay: '1.2s',
    },
    {
        icon: ShieldCheck,
        title: 'Bajas en un clic',
        text: 'Respeto a cada contacto',
        class: 'bottom-[14%] left-[12%]',
        delay: '2.4s',
    },
];
</script>

<template>
    <div
        class="dark relative isolate flex min-h-svh overflow-hidden text-foreground"
        style="background: var(--app-bg)"
    >
        <!-- Ambient glows -->
        <div
            class="auth-glow pointer-events-none absolute -top-40 -left-40 -z-10 size-[36rem] rounded-full bg-[oklch(0.55_0.18_240/0.35)] blur-3xl"
            aria-hidden="true"
        />
        <div
            class="auth-glow pointer-events-none absolute -right-40 -bottom-40 -z-10 size-[34rem] rounded-full bg-[oklch(0.45_0.22_280/0.35)] blur-3xl [animation-delay:-6s]"
            aria-hidden="true"
        />

        <!-- Globe side (background on mobile) -->
        <div
            class="pointer-events-none absolute inset-0 -z-10 flex items-center justify-center opacity-30 lg:pointer-events-auto lg:relative lg:inset-auto lg:z-0 lg:flex-1 lg:opacity-100"
        >
            <div class="relative aspect-square w-[min(90vw,40rem)]">
                <div class="globe-in h-full w-full">
                    <GlobeVisual />
                </div>
                <div
                    v-for="chip in chips"
                    :key="chip.title"
                    class="auth-float absolute hidden items-center gap-3 glass-card px-3 py-2 lg:flex"
                    :class="chip.class"
                    :style="{ animationDelay: chip.delay }"
                >
                    <span
                        class="flex size-8 items-center justify-center rounded-lg bg-primary/20 text-primary"
                    >
                        <component
                            :is="chip.icon"
                            class="size-4"
                            aria-hidden="true"
                        />
                    </span>
                    <span>
                        <span class="block text-sm font-medium">{{
                            chip.title
                        }}</span>
                        <span class="block text-xs text-muted-foreground">{{
                            chip.text
                        }}</span>
                    </span>
                </div>
            </div>
        </div>

        <!-- Form side -->
        <main
            class="relative flex w-full flex-col items-center justify-center p-6 lg:w-[30rem] lg:shrink-0 lg:p-10 xl:w-[34rem]"
        >
            <div class="w-full max-w-sm animate-rise glass-card p-8 sm:p-9">
                <Link
                    :href="home()"
                    class="mb-6 flex animate-rise flex-col items-center text-center"
                    style="--rise-index: 1"
                    aria-label="Ir al inicio"
                >
                    <span
                        class="relative mb-3 flex size-24 items-center justify-center"
                    >
                        <!-- Rotating rings around the seal -->
                        <span
                            class="seal-ring absolute inset-0 rounded-full border border-dashed border-[oklch(0.8_0.08_230/0.45)]"
                            aria-hidden="true"
                        />
                        <span
                            class="seal-arc absolute inset-1.5 rounded-full border-2 border-transparent border-b-[oklch(0.78_0.12_225)]"
                            aria-hidden="true"
                        />
                        <span
                            class="relative flex size-[4.75rem] items-center justify-center rounded-full bg-white p-1 shadow-[0_0_28px_oklch(0.85_0.12_85/0.55)]"
                        >
                            <img
                                src="/images/logo-aapos-azul.webp"
                                alt="Asociación Amigos Pro Obras Sociales"
                                width="88"
                                height="88"
                                class="size-full"
                            />
                        </span>
                    </span>
                    <span class="text-xl font-semibold tracking-tight">{{
                        appName
                    }}</span>
                    <span
                        class="mt-1 text-[10px] font-medium tracking-[0.16em] whitespace-nowrap text-muted-foreground uppercase"
                        >Asociación Amigos Pro Obras Sociales</span
                    >
                </Link>
                <div
                    class="mb-6 animate-rise space-y-1 text-center"
                    style="--rise-index: 2"
                >
                    <h1 class="text-2xl font-semibold tracking-tight">
                        {{ title }}
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        {{ description }}
                    </p>
                </div>
                <div class="auth-fields">
                    <slot />
                </div>
            </div>
            <p
                class="mt-6 animate-rise text-center text-xs text-muted-foreground"
                style="--rise-index: 6"
            >
                Apoyo a las Obras Sociales del Santo Hermano Pedro · Guatemala
            </p>
        </main>
    </div>
</template>

<style scoped>
.globe-in {
    animation: globe-in 1.4s cubic-bezier(0.2, 0.7, 0.2, 1) both;
}

.auth-float {
    animation:
        rise-in 0.8s cubic-bezier(0.2, 0.7, 0.2, 1) both,
        float 7s ease-in-out infinite;
}

.auth-glow {
    animation: drift 14s ease-in-out infinite alternate;
}

/* Form fields appear one after another. */
.auth-fields :deep(form > div > *),
.auth-fields :deep(form > *) {
    animation: rise-in 0.6s cubic-bezier(0.2, 0.7, 0.2, 1) both;
}

.auth-fields :deep(form > div > *:nth-child(1)) {
    animation-delay: 0.25s;
}

.auth-fields :deep(form > div > *:nth-child(2)) {
    animation-delay: 0.35s;
}

.auth-fields :deep(form > div > *:nth-child(3)) {
    animation-delay: 0.45s;
}

.auth-fields :deep(form > div > *:nth-child(4)) {
    animation-delay: 0.55s;
}

.seal-ring {
    animation: spin 40s linear infinite;
}

.seal-arc {
    animation: spin 6s linear infinite;
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

@keyframes globe-in {
    from {
        opacity: 0;
        transform: scale(0.9) rotate(-8deg);
    }
    to {
        opacity: 1;
        transform: none;
    }
}

@keyframes float {
    0%,
    100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-10px);
    }
}

@keyframes drift {
    from {
        transform: translate(0, 0) scale(1);
    }
    to {
        transform: translate(3rem, 2rem) scale(1.1);
    }
}

@media (prefers-reduced-motion: reduce) {
    .globe-in,
    .seal-ring,
    .seal-arc,
    .auth-float,
    .auth-glow,
    .auth-fields :deep(*) {
        animation: none !important;
    }
}
</style>
