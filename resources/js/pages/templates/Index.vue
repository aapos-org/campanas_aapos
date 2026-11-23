<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Code, LayoutTemplate, Plus } from '@lucide/vue';
import { ref } from 'vue';
import TemplateController from '@/actions/App/Http/Controllers/Campaigns/TemplateController';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useFormat } from '@/composables/useFormat';
import { edit, index } from '@/routes/templates';

type Row = {
    id: number;
    name: string;
    description: string | null;
    editor: string;
    source: string;
    updated_at: string | null;
};

defineProps<{
    templates: Row[];
    library: { key: string; name: string; description: string }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Plantillas', href: index() }] },
});

const { dateTime } = useFormat();
const creating = ref(false);
const base = ref('boletin');
</script>

<template>
    <Head title="Plantillas" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                title="Plantillas"
                description="Diseños reutilizables para tus campañas."
            />
            <Button class="w-full sm:w-auto" @click="creating = true"
                ><Plus aria-hidden="true" />Nueva plantilla</Button
            >
        </div>

        <EmptyState
            v-if="templates.length === 0"
            :icon="LayoutTemplate"
            title="Aún no tienes plantillas"
            description="Crea una a partir de un diseño base o escribe tu propio HTML."
        />

        <ul v-else class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <li v-for="t in templates" :key="t.id">
                <Link
                    :href="edit(t.id)"
                    class="block h-full rounded-xl border p-4 hover:bg-muted/40"
                >
                    <p class="font-medium">{{ t.name }}</p>
                    <p
                        v-if="t.description"
                        class="text-sm text-muted-foreground"
                    >
                        {{ t.description }}
                    </p>
                    <div
                        class="mt-3 flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                    >
                        <Badge variant="outline">{{
                            t.editor === 'visual' ? 'Editor visual' : 'HTML'
                        }}</Badge>
                        <Badge
                            v-if="t.source === 'mailchimp'"
                            variant="secondary"
                            >De Mailchimp</Badge
                        >
                        <span>{{ dateTime(t.updated_at) }}</span>
                    </div>
                </Link>
            </li>
        </ul>
    </div>

    <Dialog v-model:open="creating">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Nueva plantilla</DialogTitle>
                <DialogDescription
                    >Elige un punto de partida. Todos los diseños son adaptables
                    a móvil.</DialogDescription
                >
            </DialogHeader>
            <Form
                v-bind="TemplateController.store.form()"
                :transform="(d) => ({ ...d, base })"
                class="space-y-4"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-1.5">
                    <Label for="tpl-name">Nombre</Label>
                    <Input id="tpl-name" name="name" required v-focus />
                    <InputError :message="errors.name" />
                </div>
                <fieldset class="grid gap-2">
                    <legend class="mb-1 text-sm font-medium">
                        Diseño base
                    </legend>
                    <label
                        v-for="item in [
                            ...library,
                            {
                                key: 'html',
                                name: 'Código HTML',
                                description: 'Pega o escribe tu propio HTML.',
                            },
                        ]"
                        :key="item.key"
                        :class="[
                            'flex cursor-pointer gap-3 rounded-lg border p-3',
                            base === item.key
                                ? 'border-primary bg-primary/5'
                                : 'hover:bg-muted/50',
                        ]"
                    >
                        <input
                            v-model="base"
                            type="radio"
                            :value="item.key"
                            class="mt-1 size-4"
                        />
                        <span class="text-sm">
                            <span class="flex items-center gap-1.5 font-medium"
                                ><Code
                                    v-if="item.key === 'html'"
                                    class="size-3.5"
                                    aria-hidden="true"
                                />{{ item.name }}</span
                            >
                            <span class="text-muted-foreground">{{
                                item.description
                            }}</span>
                        </span>
                    </label>
                </fieldset>
                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        @click="creating = false"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="processing"
                        ><Spinner v-if="processing" />Crear y editar</Button
                    >
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
