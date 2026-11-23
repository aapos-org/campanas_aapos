<script setup lang="ts">
/**
 * Visual email editor: GrapesJS + MJML (responsive emails that work in Outlook).
 * MJML is compiled to HTML in the browser (mjml-browser); the server sanitizes the HTML.
 * Loaded lazily: the editor bundle is only downloaded on the pages that use it.
 */
import type { Editor } from 'grapesjs';
import { onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import { Spinner } from '@/components/ui/spinner';

const props = defineProps<{
    mjml: string | null;
    design: Record<string, unknown> | null;
}>();

const emit = defineEmits<{ ready: []; change: [] }>();

const container = ref<HTMLElement | null>(null);
const editor = shallowRef<Editor | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

onMounted(async () => {
    try {
        const [
            { default: grapesjs },
            { default: mjmlPlugin },
            { default: esCore },
            { default: esMjml },
        ] = await Promise.all([
            import('grapesjs'),
            import('grapesjs-mjml'),
            import('grapesjs/locale/es'),
            import('grapesjs-mjml/locale/es'),
            import('grapesjs/dist/css/grapes.min.css'),
            // Icons of the editor panels, bundled locally (GrapesJS would load them from a CDN).
            import('font-awesome/css/font-awesome.min.css'),
        ]);

        editor.value = grapesjs.init({
            container: container.value!,
            height: '100%',
            fromElement: false,
            storageManager: false,
            cssIcons: '',
            selectorManager: { componentFirst: true },
            i18n: {
                locale: 'es',
                detectLocale: false,
                messages: { es: esCore },
            },
            plugins: [mjmlPlugin],
            pluginsOpts: {
                [mjmlPlugin as unknown as string]: {
                    i18n: { es: esMjml },
                    imagePlaceholderSrc:
                        'https://placehold.co/600x300/e2e8f0/475569?text=Imagen',
                },
            },
        });

        if (props.design && Object.keys(props.design).length > 0) {
            editor.value.loadProjectData(props.design);
        } else {
            editor.value.setComponents(
                props.mjml ??
                    '<mjml><mj-body><mj-section><mj-column><mj-text>Hola {{ nombre | "amigo" }}</mj-text></mj-column></mj-section></mj-body></mjml>',
            );
        }

        editor.value.on('update', () => emit('change'));
        emit('ready');
    } catch (e) {
        error.value = 'No se pudo cargar el editor visual. Recarga la página.';
        console.error(e);
    } finally {
        loading.value = false;
    }
});

onBeforeUnmount(() => editor.value?.destroy());

/**
 * @returns MJML source, compiled HTML and the GrapesJS project (to reopen it later).
 */
const getContent = (): {
    mjml: string;
    html: string;
    design: Record<string, unknown>;
} | null => {
    if (!editor.value) {
        return null;
    }

    const compiled = editor.value.runCommand('mjml-code-to-html') as
        | { html?: string }
        | string
        | undefined;
    const html =
        typeof compiled === 'string' ? compiled : (compiled?.html ?? '');

    return {
        mjml: editor.value.getHtml(),
        html,
        design: editor.value.getProjectData() as Record<string, unknown>,
    };
};

defineExpose({ getContent });
</script>

<template>
    <div
        class="relative h-full min-h-[560px] overflow-hidden rounded-lg border bg-white"
    >
        <div
            v-if="loading"
            class="absolute inset-0 z-10 flex items-center justify-center gap-2 bg-background/80 text-sm"
        >
            <Spinner />
            Cargando el editor…
        </div>
        <p v-if="error" class="p-4 text-sm text-destructive" role="alert">
            {{ error }}
        </p>
        <div ref="container" class="h-full" />
    </div>
</template>
