<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowRight, Info } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import CampaignSteps from '@/components/CampaignSteps.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { content, index, update } from '@/routes/campaigns';
import type { CampaignDetail } from '@/types';

const props = defineProps<{
    campaign: CampaignDetail;
    audiences: { id: number; name: string }[];
    tags: { id: number; name: string }[];
    connections: {
        id: number;
        name: string;
        driver_label: string;
        from_email: string;
        status: string;
        daily_limit: number | null;
    }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Campañas', href: index() }] },
});

const form = reactive({
    name: props.campaign.name,
    subject: props.campaign.subject ?? '',
    preheader: props.campaign.preheader ?? '',
    from_name: props.campaign.from_name ?? '',
    from_email: props.campaign.from_email ?? '',
    reply_to: props.campaign.reply_to ?? '',
    audience_id: props.campaign.audience_id
        ? String(props.campaign.audience_id)
        : '',
    tag_ids: [...props.campaign.tag_ids],
    mail_connection_id: props.campaign.mail_connection_id
        ? String(props.campaign.mail_connection_id)
        : '',
    rate_per_minute: props.campaign.rate_per_minute
        ? String(props.campaign.rate_per_minute)
        : '',
    track_opens: props.campaign.track_opens,
    track_clicks: props.campaign.track_clicks,
});
const errors = ref<Record<string, string>>({});
const saving = ref(false);

const connection = computed(() =>
    props.connections.find((c) => String(c.id) === form.mail_connection_id),
);
const fromDomainMismatch = computed(() => {
    const a = form.from_email.split('@')[1]?.toLowerCase();
    const b = connection.value?.from_email.split('@')[1]?.toLowerCase();
    return !!a && !!b && a !== b;
});

const save = (then?: () => void) => {
    router.put(
        update.url(props.campaign.id),
        {
            ...form,
            audience_id: form.audience_id ? Number(form.audience_id) : null,
            mail_connection_id: form.mail_connection_id
                ? Number(form.mail_connection_id)
                : null,
            rate_per_minute: form.rate_per_minute
                ? Number(form.rate_per_minute)
                : null,
            subject: form.subject || null,
            preheader: form.preheader || null,
            from_name: form.from_name || null,
            from_email: form.from_email || null,
            reply_to: form.reply_to || null,
        },
        {
            preserveScroll: true,
            onStart: () => (saving.value = true),
            onFinish: () => (saving.value = false),
            onError: (e) => (errors.value = e),
            onSuccess: () => {
                errors.value = {};
                then?.();
            },
        },
    );
};

const toggleTag = (id: number) => {
    form.tag_ids = form.tag_ids.includes(id)
        ? form.tag_ids.filter((t) => t !== id)
        : [...form.tag_ids, id];
};
</script>

<template>
    <Head :title="campaign.name" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <CampaignSteps :campaign-id="campaign.id" current="settings" />
        <Heading
            :title="campaign.name"
            description="Asunto, remitente y a quién se envía."
        />

        <form class="max-w-3xl space-y-8" @submit.prevent="save()">
            <section class="grid gap-4" aria-labelledby="s-msg">
                <h2 id="s-msg" class="font-medium">Mensaje</h2>
                <div class="grid gap-1.5">
                    <Label for="name">Nombre interno</Label>
                    <Input id="name" v-model="form.name" required />
                    <InputError :message="errors.name" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="subject">Asunto</Label>
                    <Input
                        id="subject"
                        v-model="form.subject"
                        maxlength="200"
                        placeholder='Ej.: {{ nombre | "Hola" }}, estas son las novedades'
                    />
                    <p class="text-xs text-muted-foreground">
                        {{ form.subject.length }}/200 · Se recomiendan menos de
                        60 caracteres. Admite variables como
                        {{ '{' + '{ nombre }' + '}' }}.
                    </p>
                    <InputError :message="errors.subject" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="preheader">Texto de vista previa</Label>
                    <Input
                        id="preheader"
                        v-model="form.preheader"
                        maxlength="200"
                        placeholder="Aparece junto al asunto en la bandeja de entrada"
                    />
                    <InputError :message="errors.preheader" />
                </div>
            </section>

            <section class="grid gap-4 sm:grid-cols-2" aria-labelledby="s-from">
                <h2 id="s-from" class="font-medium sm:col-span-2">Remitente</h2>
                <div class="grid gap-1.5">
                    <Label for="from_name">Nombre</Label>
                    <Input id="from_name" v-model="form.from_name" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="from_email">Correo</Label>
                    <Input
                        id="from_email"
                        v-model="form.from_email"
                        type="email"
                    />
                    <InputError :message="errors.from_email" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="reply_to">Responder a (opcional)</Label>
                    <Input id="reply_to" v-model="form.reply_to" type="email" />
                    <InputError :message="errors.reply_to" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="connection">Conexión de envío</Label>
                    <NativeSelect
                        id="connection"
                        v-model="form.mail_connection_id"
                    >
                        <option value="">— Elige —</option>
                        <option
                            v-for="c in connections"
                            :key="c.id"
                            :value="String(c.id)"
                        >
                            {{ c.name }} ({{ c.driver_label }}){{
                                c.status !== 'active' ? ' · sin verificar' : ''
                            }}
                        </option>
                    </NativeSelect>
                    <InputError :message="errors.mail_connection_id" />
                </div>
                <Alert v-if="fromDomainMismatch" class="sm:col-span-2">
                    <Info />
                    <AlertDescription
                        >El correo del remitente usa otro dominio que la
                        conexión ({{ connection?.from_email }}). Si ese dominio
                        no está verificado en el proveedor, los correos pueden
                        ir a spam o ser rechazados.</AlertDescription
                    >
                </Alert>
            </section>

            <section class="grid gap-4" aria-labelledby="s-to">
                <h2 id="s-to" class="font-medium">Destinatarios</h2>
                <div class="grid gap-1.5 sm:max-w-sm">
                    <Label for="audience">Audiencia</Label>
                    <NativeSelect id="audience" v-model="form.audience_id">
                        <option value="">— Elige —</option>
                        <option
                            v-for="a in audiences"
                            :key="a.id"
                            :value="String(a.id)"
                        >
                            {{ a.name }}
                        </option>
                    </NativeSelect>
                    <InputError :message="errors.audience_id" />
                </div>
                <fieldset v-if="tags.length" class="grid gap-2">
                    <legend class="text-sm font-medium">
                        Solo contactos con alguna de estas etiquetas (opcional)
                    </legend>
                    <div class="flex max-h-48 flex-wrap gap-2 overflow-y-auto">
                        <button
                            v-for="t in tags"
                            :key="t.id"
                            type="button"
                            :aria-pressed="form.tag_ids.includes(t.id)"
                            :class="[
                                'rounded-full border px-3 py-1 text-sm',
                                form.tag_ids.includes(t.id)
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'hover:bg-muted',
                            ]"
                            @click="toggleTag(t.id)"
                        >
                            {{ t.name }}
                        </button>
                    </div>
                </fieldset>
                <p class="text-sm text-muted-foreground">
                    Solo reciben la campaña los contactos suscritos. Las bajas,
                    rebotes y quejas se excluyen siempre.
                </p>
            </section>

            <section class="grid gap-4" aria-labelledby="s-adv">
                <h2 id="s-adv" class="font-medium">Opciones de envío</h2>
                <div class="grid gap-1.5 sm:max-w-xs">
                    <Label for="rate"
                        >Velocidad máxima (correos por minuto, opcional)</Label
                    >
                    <Input
                        id="rate"
                        v-model="form.rate_per_minute"
                        type="number"
                        min="1"
                        placeholder="Sin límite"
                    />
                    <InputError :message="errors.rate_per_minute" />
                </div>
                <label class="flex items-center gap-2 text-sm"
                    ><Checkbox
                        :model-value="form.track_opens"
                        @update:model-value="
                            (v) => (form.track_opens = v === true)
                        "
                    />Medir aperturas (píxel invisible)</label
                >
                <label class="flex items-center gap-2 text-sm"
                    ><Checkbox
                        :model-value="form.track_clicks"
                        @update:model-value="
                            (v) => (form.track_clicks = v === true)
                        "
                    />Medir clics en los enlaces</label
                >
            </section>

            <div class="flex flex-col-reverse gap-3 sm:flex-row">
                <Button type="submit" variant="secondary" :disabled="saving"
                    ><Spinner v-if="saving" />Guardar</Button
                >
                <Button
                    type="button"
                    :disabled="saving"
                    @click="save(() => router.visit(content.url(campaign.id)))"
                    >Guardar y diseñar el contenido<ArrowRight
                        aria-hidden="true"
                /></Button>
            </div>
        </form>
        <Link :href="index()" class="text-sm text-muted-foreground underline"
            >Volver a campañas</Link
        >
    </div>
</template>
