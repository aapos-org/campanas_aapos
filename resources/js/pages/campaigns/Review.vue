<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    Monitor,
    Send,
    Smartphone,
    XCircle,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import CampaignController from '@/actions/App/Http/Controllers/Campaigns/CampaignController';
import CampaignSteps from '@/components/CampaignSteps.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index, preview, review } from '@/routes/campaigns';
import type { CampaignDetail } from '@/types';

const props = defineProps<{
    campaign: CampaignDetail;
    checks: {
        key: string;
        status: 'ok' | 'warning' | 'error';
        message: string;
    }[];
    blocked: boolean;
    linksChecked: boolean;
    canSend: boolean;
    timezone: string;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Campañas', href: index() }] },
});

const device = ref<'desktop' | 'mobile'>('desktop');
const when = ref<'now' | 'later'>('now');
const scheduledAt = ref('');
const confirmation = ref('');
const checkingLinks = ref(false);

const recipients = computed(
    () => props.checks.find((c) => c.key === 'recipients')?.message ?? '',
);
const checkLinks = () =>
    router.get(
        review.url(props.campaign.id),
        { enlaces: 1 },
        {
            preserveScroll: true,
            onStart: () => (checkingLinks.value = true),
            onFinish: () => (checkingLinks.value = false),
        },
    );
</script>

<template>
    <Head :title="`Revisión · ${campaign.name}`" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <CampaignSteps :campaign-id="campaign.id" current="review" />
        <h1 class="text-xl font-semibold">{{ campaign.name }}</h1>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
            <section class="space-y-3" aria-labelledby="s-preview">
                <div class="flex items-center justify-between">
                    <h2 id="s-preview" class="font-medium">
                        Vista previa
                        <span class="text-sm font-normal text-muted-foreground"
                            >(con datos de ejemplo)</span
                        >
                    </h2>
                    <div
                        class="flex gap-1"
                        role="group"
                        aria-label="Tamaño de la vista previa"
                    >
                        <Button
                            size="sm"
                            :variant="
                                device === 'desktop' ? 'secondary' : 'ghost'
                            "
                            :aria-pressed="device === 'desktop'"
                            @click="device = 'desktop'"
                            ><Monitor aria-hidden="true" />Escritorio</Button
                        >
                        <Button
                            size="sm"
                            :variant="
                                device === 'mobile' ? 'secondary' : 'ghost'
                            "
                            :aria-pressed="device === 'mobile'"
                            @click="device = 'mobile'"
                            ><Smartphone aria-hidden="true" />Móvil</Button
                        >
                    </div>
                </div>
                <div class="rounded-lg border bg-muted/40 p-3">
                    <p class="mb-2 truncate text-sm">
                        <span class="text-muted-foreground">Asunto:</span>
                        <strong>{{ campaign.subject ?? '—' }}</strong>
                    </p>
                    <iframe
                        :src="preview.url(campaign.id)"
                        sandbox=""
                        title="Vista previa del correo"
                        :class="[
                            'mx-auto block h-[640px] rounded border bg-white transition-all',
                            device === 'mobile'
                                ? 'w-[375px] max-w-full'
                                : 'w-full',
                        ]"
                    />
                </div>
            </section>

            <aside class="space-y-6">
                <section class="space-y-2" aria-labelledby="s-checks">
                    <h2 id="s-checks" class="font-medium">
                        Lista de verificación
                    </h2>
                    <ul class="grid gap-2">
                        <li
                            v-for="c in checks"
                            :key="c.key"
                            class="flex gap-2 text-sm"
                        >
                            <CheckCircle2
                                v-if="c.status === 'ok'"
                                class="mt-0.5 size-4 shrink-0 text-emerald-600"
                                aria-label="Correcto"
                            />
                            <AlertTriangle
                                v-else-if="c.status === 'warning'"
                                class="mt-0.5 size-4 shrink-0 text-amber-600"
                                aria-label="Advertencia"
                            />
                            <XCircle
                                v-else
                                class="mt-0.5 size-4 shrink-0 text-destructive"
                                aria-label="Error"
                            />
                            <span>{{ c.message }}</span>
                        </li>
                    </ul>
                    <Button
                        v-if="!linksChecked"
                        size="sm"
                        variant="outline"
                        :disabled="checkingLinks"
                        @click="checkLinks"
                        ><Spinner v-if="checkingLinks" />Comprobar
                        enlaces</Button
                    >
                </section>

                <section class="space-y-2" aria-labelledby="s-test">
                    <h2 id="s-test" class="font-medium">Enviar una prueba</h2>
                    <Form
                        v-bind="CampaignController.sendTest.form(campaign.id)"
                        :options="{ preserveScroll: true }"
                        class="flex gap-2"
                        v-slot="{ errors, processing }"
                    >
                        <div class="grid flex-1 gap-1">
                            <Label for="test-emails" class="sr-only"
                                >Correos de prueba</Label
                            >
                            <Input
                                id="test-emails"
                                name="emails"
                                placeholder="tu@correo.com, otra@correo.com"
                            />
                            <InputError :message="errors.emails" />
                        </div>
                        <Button
                            type="submit"
                            variant="secondary"
                            :disabled="processing"
                            ><Spinner v-if="processing" />Probar</Button
                        >
                    </Form>
                    <p class="text-xs text-muted-foreground">
                        Hasta 5 correos. Si alguno es un contacto, se usan sus
                        datos reales.
                    </p>
                </section>

                <section
                    v-if="canSend"
                    class="space-y-3 rounded-xl border p-4"
                    aria-labelledby="s-send"
                >
                    <h2 id="s-send" class="font-medium">Enviar</h2>
                    <p class="text-sm text-muted-foreground">
                        {{ recipients }}
                    </p>
                    <fieldset class="flex gap-4 text-sm">
                        <legend class="sr-only">Cuándo enviar</legend>
                        <label class="flex items-center gap-2"
                            ><input
                                v-model="when"
                                type="radio"
                                value="now"
                                class="size-4"
                            />Ahora</label
                        >
                        <label class="flex items-center gap-2"
                            ><input
                                v-model="when"
                                type="radio"
                                value="later"
                                class="size-4"
                            />Programar</label
                        >
                    </fieldset>
                    <Form
                        v-bind="CampaignController.send.form(campaign.id)"
                        :transform="
                            (d) => ({
                                ...d,
                                scheduled_at:
                                    when === 'later' ? scheduledAt : null,
                            })
                        "
                        class="space-y-3"
                        v-slot="{ errors, processing }"
                    >
                        <div v-if="when === 'later'" class="grid gap-1.5">
                            <Label for="scheduled"
                                >Fecha y hora ({{ timezone }})</Label
                            >
                            <Input
                                id="scheduled"
                                v-model="scheduledAt"
                                type="datetime-local"
                                required
                            />
                            <InputError :message="errors.scheduled_at" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="confirmation"
                                >Escribe <strong>ENVIAR</strong> para
                                confirmar</Label
                            >
                            <Input
                                id="confirmation"
                                v-model="confirmation"
                                name="confirmation"
                                autocomplete="off"
                            />
                            <InputError :message="errors.confirmation" />
                        </div>
                        <Button
                            type="submit"
                            class="w-full"
                            :disabled="
                                processing ||
                                blocked ||
                                confirmation.trim().toUpperCase() !== 'ENVIAR'
                            "
                        >
                            <Spinner v-if="processing" /><Send
                                v-else
                                aria-hidden="true"
                            />
                            {{
                                when === 'later'
                                    ? 'Programar envío'
                                    : 'Enviar ahora'
                            }}
                        </Button>
                        <p v-if="blocked" class="text-sm text-destructive">
                            Corrige los puntos en rojo para poder enviar.
                        </p>
                    </Form>
                </section>
                <p v-else class="text-sm text-muted-foreground">
                    Tu rol no permite enviar campañas.
                </p>
                <Link
                    :href="index()"
                    class="block text-sm text-muted-foreground underline"
                    >Volver a campañas</Link
                >
            </aside>
        </div>
    </div>
</template>
