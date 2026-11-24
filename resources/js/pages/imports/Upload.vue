<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { FileSpreadsheet } from '@lucide/vue';
import ImportController from '@/actions/App/Http/Controllers/Imports/ImportController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    index as audiencesIndex,
    show as showAudience,
} from '@/routes/audiences';

defineProps<{ audience: { id: number; name: string } }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Audiencias', href: audiencesIndex() }] },
});
</script>

<template>
    <Head title="Importar contactos" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Importar contactos"
            :description="`Paso 1 de 2 · Sube el archivo para «${audience.name}».`"
        />

        <Form
            v-bind="ImportController.store.form(audience.id)"
            class="max-w-xl space-y-6"
            v-slot="{ errors, processing, progress }"
        >
            <div class="grid gap-3 glass-card border-dashed p-6 text-center">
                <FileSpreadsheet
                    class="mx-auto size-10 text-muted-foreground"
                    aria-hidden="true"
                />
                <Label for="file" class="justify-center"
                    >Archivo CSV o Excel (.xlsx), hasta 50 MB</Label
                >
                <input
                    id="file"
                    name="file"
                    type="file"
                    accept=".csv,.txt,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                    required
                    class="mx-auto block w-full max-w-xs text-sm file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-1.5 file:text-primary-foreground"
                />
                <InputError :message="errors.file" />
                <progress
                    v-if="progress"
                    :value="progress.percentage"
                    max="100"
                    class="w-full"
                >
                    {{ progress.percentage }}%
                </progress>
            </div>

            <ul class="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                <li>La primera fila debe tener los nombres de las columnas.</li>
                <li>Debe haber una columna con el correo electrónico.</li>
                <li>
                    Las direcciones dadas de baja o rebotadas nunca se vuelven a
                    suscribir.
                </li>
            </ul>

            <div class="flex flex-col-reverse gap-3 sm:flex-row">
                <Button variant="outline" as-child>
                    <Link :href="showAudience(audience.id)">Cancelar</Link>
                </Button>
                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    Continuar
                </Button>
            </div>
        </Form>
    </div>
</template>
