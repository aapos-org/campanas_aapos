<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Head, router } from '@inertiajs/vue3';
import { ArrowRight, Code, LayoutTemplate, Save } from '@lucide/vue';
import { ref } from 'vue';
import CampaignSteps from '@/components/CampaignSteps.vue';
import EmailEditor from '@/components/email/EmailEditor.vue';
import HtmlEditor from '@/components/email/HtmlEditor.vue';
import MergeTagList from '@/components/email/MergeTagList.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { index, review, template as applyTemplate } from '@/routes/campaigns';
import { update as updateContent } from '@/routes/campaigns/content';

const props = defineProps<{
    campaign: {
        id: number;
        name: string;
        editor: 'visual' | 'html';
        mjml: string | null;
        design: Record<string, unknown> | null;
        html: string | null;
        subject: string | null;
    };
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
    };

    router.put(updateContent.url(props.campaign.id), payload, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => (saving.value = true),
        onFinish: () => (saving.value = false),
        onError: (e) =>
            (error.value = Object.values(e)[0] ?? 'No se pudo guardar.'),
        onSuccess: () => then?.(),
    });
};

const start = (data: { template_id?: number; base?: string }) =>
    router.post(applyTemplate.url(props.campaign.id), data);
</script>

<template>
    <Head :title="`Contenido · ${campaign.name}`" />

    <div class="flex flex-1 flex-col gap-4 p-4 md:p-6">
        <CampaignSteps :campaign-id="campaign.id" current="content" />

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
                        class="rounded-xl border p-4 text-left hover:bg-muted/40"
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
                        class="rounded-xl border p-4 text-left hover:bg-muted/40"
                        @click="start({ base: t.key })"
                    >
                        <span class="block font-medium">{{ t.name }}</span>
                        <span class="text-sm text-muted-foreground">{{
                            t.description
                        }}</span>
                    </button>
                    <button
                        type="button"
                        class="rounded-xl border p-4 text-left hover:bg-muted/40"
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
