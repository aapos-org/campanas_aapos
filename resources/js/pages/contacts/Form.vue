<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ContactController from '@/actions/App/Http/Controllers/Contacts/ContactController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    index as audiencesIndex,
    show as showAudience,
} from '@/routes/audiences';
import { show as showContact } from '@/routes/contacts';
import type { FieldDefinition } from '@/types';

type EditableContact = {
    id: number;
    email: string;
    first_name: string | null;
    last_name: string | null;
    phone: string | null;
    fields: Record<string, string | number | null>;
    tags: string[];
};

const props = defineProps<{
    contact: EditableContact | null;
    audience: { id: number; name: string } | null;
    fields: FieldDefinition[];
    tags: string[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Audiencias', href: audiencesIndex() }] },
});

const status = ref<'subscribed' | 'non_subscribed'>('non_subscribed');
const tagsText = ref((props.contact?.tags ?? []).join(', '));

const formDefinition = computed(() =>
    props.contact
        ? ContactController.update.form(props.contact.id)
        : ContactController.store.form(props.audience!.id),
);

const cancelHref = computed(() =>
    props.contact
        ? showContact(props.contact.id)
        : showAudience(props.audience!.id),
);

const fieldValue = (key: string): string => {
    const value = props.contact?.fields?.[key];

    if (value === null || value === undefined) {
        return '';
    }

    // Birthdays are stored as MM-DD and edited as dd/mm.
    const definition = props.fields.find((f) => f.key === key);
    if (definition?.type === 'birthday' && typeof value === 'string') {
        const [month, day] = value.split('-');
        return `${day}/${month}`;
    }

    return String(value);
};

const inputType = (type: FieldDefinition['type']) =>
    ({ date: 'date', number: 'number', phone: 'tel' })[type as string] ??
    'text';
</script>

<template>
    <Head :title="contact ? 'Editar contacto' : 'Nuevo contacto'" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            :title="contact ? 'Editar contacto' : 'Nuevo contacto'"
            :description="
                audience ? `Se agregará a «${audience.name}».` : undefined
            "
        />

        <Form
            v-bind="formDefinition"
            :transform="
                (data) => ({
                    ...data,
                    tags: tagsText
                        .split(',')
                        .map((t) => t.trim())
                        .filter(Boolean),
                    ...(contact ? {} : { status }),
                })
            "
            class="max-w-2xl space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="email">Correo electrónico</Label>
                <Input
                    id="email"
                    name="email"
                    type="email"
                    :default-value="contact?.email"
                    required
                    v-focus
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="first_name">Nombre</Label>
                    <Input
                        id="first_name"
                        name="first_name"
                        :default-value="contact?.first_name ?? ''"
                        autocomplete="off"
                    />
                    <InputError :message="errors.first_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="last_name">Apellido</Label>
                    <Input
                        id="last_name"
                        name="last_name"
                        :default-value="contact?.last_name ?? ''"
                        autocomplete="off"
                    />
                    <InputError :message="errors.last_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="phone">Teléfono</Label>
                    <Input
                        id="phone"
                        name="phone"
                        type="tel"
                        :default-value="contact?.phone ?? ''"
                        placeholder="5555 1234 o +502 5555 1234"
                    />
                    <InputError :message="errors.phone" />
                </div>

                <div
                    v-for="field in fields"
                    :key="field.key"
                    class="grid gap-2"
                >
                    <Label :for="`field-${field.key}`">
                        {{ field.label }}{{ field.required ? ' *' : '' }}
                    </Label>
                    <select
                        v-if="field.type === 'select'"
                        :id="`field-${field.key}`"
                        :name="`fields[${field.key}]`"
                        :required="field.required"
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs dark:bg-input/30"
                    >
                        <option value="">—</option>
                        <option
                            v-for="option in field.options"
                            :key="option"
                            :value="option"
                            :selected="fieldValue(field.key) === option"
                        >
                            {{ option }}
                        </option>
                    </select>
                    <Input
                        v-else
                        :id="`field-${field.key}`"
                        :name="`fields[${field.key}]`"
                        :type="inputType(field.type)"
                        :default-value="fieldValue(field.key)"
                        :required="field.required"
                        :placeholder="
                            field.type === 'birthday' ? 'dd/mm' : undefined
                        "
                    />
                    <InputError :message="errors[`fields.${field.key}`]" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="tags">Etiquetas (separadas por comas)</Label>
                <Input
                    id="tags"
                    v-model="tagsText"
                    list="known-tags"
                    autocomplete="off"
                />
                <datalist id="known-tags">
                    <option v-for="t in tags" :key="t" :value="t" />
                </datalist>
            </div>

            <fieldset v-if="!contact" class="grid gap-3">
                <legend class="mb-1 text-sm font-medium">
                    Estado en la audiencia
                </legend>
                <label class="flex gap-3 rounded-lg border p-3">
                    <input
                        v-model="status"
                        type="radio"
                        value="non_subscribed"
                        class="mt-1 size-4"
                    />
                    <span class="text-sm">
                        <span class="block font-medium">No suscrito</span>
                        <span class="text-muted-foreground"
                            >Se guardan sus datos, pero no recibe
                            campañas.</span
                        >
                    </span>
                </label>
                <label class="flex gap-3 rounded-lg border p-3">
                    <input
                        v-model="status"
                        type="radio"
                        value="subscribed"
                        class="mt-1 size-4"
                    />
                    <span class="text-sm">
                        <span class="block font-medium">Suscrito</span>
                        <span class="text-muted-foreground"
                            >Solo si la persona dio su consentimiento.</span
                        >
                    </span>
                </label>
                <InputError :message="errors.status" />

                <div v-if="status === 'subscribed'" class="grid gap-2">
                    <Label for="consent_detail"
                        >¿Cómo dio su consentimiento?</Label
                    >
                    <Input
                        id="consent_detail"
                        name="consent_detail"
                        placeholder="Ej.: Formulario firmado en el evento del 12/03/2026"
                        required
                    />
                    <InputError :message="errors.consent_detail" />
                </div>
            </fieldset>

            <div class="flex flex-col-reverse gap-3 sm:flex-row">
                <Button variant="outline" as-child>
                    <Link :href="cancelHref">Cancelar</Link>
                </Button>
                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    Guardar
                </Button>
            </div>
        </Form>
    </div>
</template>
