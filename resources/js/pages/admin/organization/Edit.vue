<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { edit, update } from '@/routes/admin/organization';

const props = defineProps<{
    organization: {
        name: string;
        physical_address: string | null;
        default_from_name: string | null;
        default_from_email: string | null;
        timezone: string;
        limits: {
            contacts: number | null;
            monthly_sends: number | null;
            daily_sends_per_connection: number | null;
        };
    };
    timezones: string[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Organización', href: edit() }] },
});

const form = useForm({
    name: props.organization.name,
    physical_address: props.organization.physical_address ?? '',
    default_from_name: props.organization.default_from_name ?? '',
    default_from_email: props.organization.default_from_email ?? '',
    timezone: props.organization.timezone,
    // Inputs hold strings; empty = no limit.
    limits: Object.fromEntries(
        Object.entries(props.organization.limits).map(([k, v]) => [
            k,
            v === null ? '' : String(v),
        ]),
    ) as Record<
        'contacts' | 'monthly_sends' | 'daily_sends_per_connection',
        string
    >,
});

const submit = () =>
    form
        .transform((data) => ({
            ...data,
            physical_address: data.physical_address || null,
            default_from_name: data.default_from_name || null,
            default_from_email: data.default_from_email || null,
            limits: Object.fromEntries(
                Object.entries(data.limits).map(([k, v]) => [
                    k,
                    v === '' ? null : Number(v),
                ]),
            ),
        }))
        .put(update.url(), { preserveScroll: true });

const limitFields = [
    {
        key: 'contacts',
        label: 'Contactos almacenados',
        help: 'Al llegar al límite no se pueden crear contactos nuevos (panel, importaciones, formularios, API).',
    },
    {
        key: 'monthly_sends',
        label: 'Envíos por mes',
        help: 'Campañas y automatizaciones. Al llegar al límite, las campañas se pausan.',
    },
    {
        key: 'daily_sends_per_connection',
        label: 'Envíos diarios por conexión (máximo)',
        help: 'Tope que se aplica al límite diario de cada conexión de envío al guardarla.',
    },
] as const;
</script>

<template>
    <Head title="Organización" />

    <form
        class="flex max-w-3xl flex-1 flex-col gap-8 p-4 md:p-6"
        @submit.prevent="submit"
    >
        <Heading
            title="Organización"
            description="Datos que aparecen en los correos y límites de uso."
        />

        <section class="grid gap-4" aria-labelledby="s-data">
            <h2 id="s-data" class="font-medium">Datos</h2>
            <div class="grid gap-1.5">
                <Label for="name">Nombre</Label>
                <Input id="name" v-model="form.name" required maxlength="150" />
                <InputError :message="form.errors.name" />
            </div>
            <div class="grid gap-1.5">
                <Label for="address">Dirección física</Label>
                <textarea
                    id="address"
                    v-model="form.physical_address"
                    rows="3"
                    maxlength="500"
                    class="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none dark:bg-input/30"
                />
                <p class="text-xs text-muted-foreground">
                    Obligatoria: aparece en el pie de todos los correos. Sin
                    ella no se pueden enviar campañas.
                </p>
                <InputError :message="form.errors.physical_address" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="from_name"
                        >Nombre del remitente por defecto</Label
                    >
                    <Input id="from_name" v-model="form.default_from_name" />
                    <InputError :message="form.errors.default_from_name" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="from_email"
                        >Correo del remitente por defecto</Label
                    >
                    <Input
                        id="from_email"
                        v-model="form.default_from_email"
                        type="email"
                    />
                    <InputError :message="form.errors.default_from_email" />
                </div>
            </div>
            <div class="grid gap-1.5 sm:max-w-sm">
                <Label for="timezone">Zona horaria</Label>
                <NativeSelect id="timezone" v-model="form.timezone">
                    <option v-for="tz in timezones" :key="tz" :value="tz">
                        {{ tz }}
                    </option>
                </NativeSelect>
                <p class="text-xs text-muted-foreground">
                    Se usa para programar envíos, cumpleaños y reportes.
                </p>
                <InputError :message="form.errors.timezone" />
            </div>
        </section>

        <section class="grid gap-4" aria-labelledby="s-limits">
            <div>
                <h2 id="s-limits" class="font-medium">Límites de uso</h2>
                <p class="text-sm text-muted-foreground">
                    Déjalos en blanco para no limitar. Puedes ver el consumo en
                    Reportes → Uso.
                </p>
            </div>
            <div
                v-for="f in limitFields"
                :key="f.key"
                class="grid gap-1.5 sm:max-w-sm"
            >
                <Label :for="`l-${f.key}`">{{ f.label }}</Label>
                <Input
                    :id="`l-${f.key}`"
                    v-model="form.limits[f.key]"
                    type="number"
                    min="1"
                    placeholder="Sin límite"
                />
                <p class="text-xs text-muted-foreground">{{ f.help }}</p>
                <InputError :message="form.errors[`limits.${f.key}`]" />
            </div>
        </section>

        <div>
            <Button type="submit" :disabled="form.processing"
                ><Spinner v-if="form.processing" />Guardar</Button
            >
        </div>
    </form>
</template>
