<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Head, router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { ArrowRight, Code, LayoutTemplate, Save } from '@lucide/vue';
import { ref } from 'vue';
import CampaignSteps from '@/components/CampaignSteps.vue';
import EmailEditor from '@/components/email/EmailEditor.vue';
import HtmlEditor from '@/components/email/HtmlEditor.vue';
import MergeTagList from '@/components/email/MergeTagList.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { requestJson } from '@/lib/http';
import { index, review, template as applyTemplate } from '@/routes/campaigns';
import { content as contentPage } from '@/routes/campaigns';
import { update as updateContent } from '@/routes/campaigns/content';
import { edit as automationEdit } from '@/routes/automations';

const props = defineProps<{
    campaign: {
        id: number;
        name: string;
        editor: 'visual' | 'html';
        mjml: string | null;
        design: Record<string, unknown> | null;
        html: string | null;
        subject: string | null;
        kind: 'regular' | 'automation';
    };
    variant: string | null;
    automationId: number | null;
    abContent: boolean;
    mergeTags: { tag: string; label: string }[];
    templates?: { id: number; name: string; editor: string }[];
    library?: { key: string; name: string; description: string }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Campañas', href: index() }] },
});

const hasContent = !!(props.campaign.html || props.campaign.mjml);
const html = ref(props.campaign.html ?? '');
const visual = ref<InstanceType<typeof EmailEditor> | null>(null);
const saving = ref(false);
const error = ref<string | null>(null);

const save = (then?: () => void) => {
    const content =
        props.campaign.editor === 'visual'
            ? visual.value?.getContent()
            : { html: html.value, mjml: null, design: null };

    if (!content) {
        return;
    }

    const payload: Record<string, FormDataConvertible> = {
        html: content.html,
        mjml: content.mjml,
        design: content.design as unknown as FormDataConvertible,
        variant: props.variant,
    };

    saving.value = true;
    error.value = null;

    requestJson<{ message: string }>(
        'PUT',
        updateContent.url(props.campaign.id),
        payload,
    )
        .then((response) => {
            toast.success(response.message);
            then?.();
        })
        .catch((e: Error) => (error.value = e.message))
        .finally(() => (saving.value = false));
};

const start = (data: { template_id?: number; base?: string }) =>
    router.post(applyTemplate.url(props.campaign.id), data);
</script>

<template>
    <Head :title="`Contenido · ${campaign.name}`" />

    <div class="flex flex-1 flex-col gap-4 p-4 md:p-6">
        <CampaignSteps
            v-if="!automationId"
            :campaign-id="campaign.id"
            current="content"
        />
        <p v-else class="text-sm text-muted-foreground">
            Correo de una automatización.
            <a
                :href="automationEdit.url(automationId)"
                class="underline underline-offset-2"
                >Volver a la automatización</a
            >
        </p>

        <template v-if="!hasContent">
            <h1 class="text-xl font-semibold">¿Cómo quieres empezar?</h1>
            <section
                v-if="templates?.length"
                class="space-y-2"
                aria-labelledby="s-mine"
            >
                <h2 id="s-mine" class="font-medium">Tus plantillas</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <button
                        v-for="t in templates"
                        :key="t.id"
                        type="button"
                        class="glass-card p-4 text-left hover:bg-muted/40"
                        @click="start({ template_id: t.id })"
                    >
                        <LayoutTemplate
                            class="mb-2 size-5 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span class="font-medium">{{ t.name }}</span>
                    </button>
                </div>
            </section>
            <section class="space-y-2" aria-labelledby="s-lib">
                <h2 id="s-lib" class="font-medium">Diseños base</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <button
                        v-for="t in library ?? []"
                        :key="t.key"
                        type="button"
                        class="glass-card p-4 text-left hover:bg-muted/40"
                        @click="start({ base: t.key })"
                    >
                        <span class="block font-medium">{{ t.name }}</span>
                        <span class="text-sm text-muted-foreground">{{
                            t.description
                        }}</span>
                    </button>
                    <button
                        type="button"
                        class="glass-card p-4 text-left hover:bg-muted/40"
                        @click="start({ base: 'html' })"
                    >
                        <span class="flex items-center gap-1.5 font-medium"
                            ><Code class="size-4" aria-hidden="true" />Código
                            HTML</span
                        >
                        <span class="text-sm text-muted-foreground"
                            >Pega o escribe tu propio HTML.</span
                        >
                    </button>
                </div>
            </section>
        </template>

        <template v-else>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-xl font-semibold">{{ campaign.name }}</h1>
                <div class="flex gap-2">
                    <Button
                        variant="secondary"
                        :disabled="saving"
                        @click="save()"
                        ><Spinner v-if="saving" /><Save
                            v-else
                            aria-hidden="true"
                        />Guardar</Button
                    >
                    <Button
                        v-if="automationId"
                        :disabled="saving"
                        @click="
                            save(() =>
                                router.visit(automationEdit.url(automationId!)),
                            )
                        "
                        >Guardar y volver<ArrowRight aria-hidden="true"
                    /></Button>
                    <Button
                        v-else
                        :disabled="saving"
                        @click="
                            save(() => router.visit(review.url(campaign.id)))
                        "
                        >Guardar y revisar<ArrowRight aria-hidden="true"
                    /></Button>
                </div>
            </div>
            <p v-if="error" class="text-sm text-destructive" role="alert">
                {{ error }}
            </p>
            <nav
                v-if="abContent"
                class="flex gap-2"
                aria-label="Versiones de la prueba A/B"
            >
                <a
                    v-for="v in ['A', 'B']"
                    :key="v"
                    :href="
                        contentPage.url(campaign.id, {
                            query: { variant: v === 'B' ? 'B' : undefined },
                        })
                    "
                    :aria-current="(variant ?? 'A') === v ? 'page' : undefined"
                    :class="[
                        'rounded-full border px-3 py-1 text-sm',
                        (variant ?? 'A') === v
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'hover:bg-muted',
                    ]"
                    >Versión {{ v }}</a
                >
                <span class="self-center text-xs text-muted-foreground"
                    >Guarda antes de cambiar de versión.</span
                >
            </nav>
            <MergeTagList :tags="mergeTags" />
            <p class="text-sm text-muted-foreground">
                Si no incluyes {{ '{' + '{ enlace_baja }' + '}' }}, se agregará
                automáticamente un pie con la dirección física y los enlaces de
                baja y preferencias.
            </p>
            <div
                v-if="campaign.editor === 'visual'"
                class="h-[calc(100vh-280px)] min-h-[600px]"
            >
                <EmailEditor
                    ref="visual"
                    :mjml="campaign.mjml"
                    :design="campaign.design"
                />
            </div>
            <HtmlEditor v-else v-model="html" />
        </template>
    </div>
</template>
