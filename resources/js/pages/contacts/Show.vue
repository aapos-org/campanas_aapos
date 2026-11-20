<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Ban, History, Mail, Pencil, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import ContactStatusBadge from '@/components/ContactStatusBadge.vue';
import EmptyState from '@/components/EmptyState.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFormat } from '@/composables/useFormat';
import {
    index as audiencesIndex,
    show as showAudience,
} from '@/routes/audiences';
import { destroy, edit } from '@/routes/contacts';
import { status as updateStatus } from '@/routes/subscriptions';
import type { FieldDefinition, Option, TagRef } from '@/types';

type SubscriptionRow = {
    id: number;
    audience: { id: number; name: string } | null;
    status: string;
    status_label: string;
    subscribed_at: string | null;
    unsubscribed_at: string | null;
    unsubscribe_reason: string | null;
    consent_at: string | null;
    consent_ip: string | null;
    consent_source: string | null;
    consent_detail: string | null;
    block_reason: string | null;
};

const props = defineProps<{
    contact: {
        id: number;
        email: string;
        first_name: string | null;
        last_name: string | null;
        full_name: string;
        phone: string | null;
        fields: Record<string, string | number | null>;
        created_at: string | null;
        tags: TagRef[];
    };
    fields: FieldDefinition[];
    subscriptions: SubscriptionRow[];
    suppression: { reason: string; created_at: string | null } | null;
    statuses: Option[];
    activity: {
        id: number;
        description: string;
        event: string | null;
        causer: string | null;
        created_at: string | null;
    }[];
    campaigns: unknown[];
    canManage: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Audiencias', href: audiencesIndex() }] },
});

const { dateTime } = useFormat();

const sourceLabels: Record<string, string> = {
    manual: 'Alta manual',
    import: 'Importación',
    form: 'Formulario',
    api: 'API',
    mailchimp: 'Migrado de Mailchimp',
};

const fieldDisplay = (field: FieldDefinition): string => {
    const value = props.contact.fields[field.key];

    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (field.type === 'birthday' && typeof value === 'string') {
        const [month, day] = value.split('-');
        return `${day}/${month}`;
    }

    if (field.type === 'date' && typeof value === 'string') {
        const [y, m, d] = value.split('-');
        return `${d}/${m}/${y}`;
    }

    return String(value);
};

// Status change per audience
const editing = ref<number | null>(null);
const newStatus = ref('');
const consentDetail = ref('');
const statusErrors = ref<Record<string, string>>({});

const startEdit = (s: SubscriptionRow) => {
    editing.value = s.id;
    newStatus.value = s.status;
    consentDetail.value = '';
    statusErrors.value = {};
};

const saveStatus = (s: SubscriptionRow) => {
    router.put(
        updateStatus.url(s.id),
        {
            status: newStatus.value,
            consent_detail: consentDetail.value || null,
        },
        {
            preserveScroll: true,
            onSuccess: () => (editing.value = null),
            onError: (errors) => (statusErrors.value = errors),
        },
    );
};

const confirmingDelete = ref(false);
const deleteContact = () => router.delete(destroy.url(props.contact.id));
</script>

<template>
    <Head :title="contact.email" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <div class="min-w-0">
                <h1 class="truncate text-2xl font-semibold tracking-tight">
                    {{ contact.full_name || contact.email }}
                </h1>
                <p class="flex items-center gap-1.5 text-muted-foreground">
                    <Mail class="size-4" aria-hidden="true" />
                    <span class="truncate">{{ contact.email }}</span>
                </p>
            </div>
            <div v-if="canManage" class="flex gap-2">
                <Button as-child size="sm" variant="secondary">
                    <Link :href="edit(contact.id)">
                        <Pencil aria-hidden="true" />
                        Editar
                    </Link>
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    class="text-destructive"
                    @click="confirmingDelete = true"
                >
                    <Trash2 aria-hidden="true" />
                    Eliminar
                </Button>
            </div>
        </div>

        <Alert v-if="suppression" variant="destructive">
            <Ban />
            <AlertTitle>En la lista de supresión global</AlertTitle>
            <AlertDescription>
                Motivo: {{ suppression.reason }} ({{
                    dateTime(suppression.created_at)
                }}). No recibirá campañas de ninguna audiencia.
            </AlertDescription>
        </Alert>

        <div class="grid gap-6 lg:grid-cols-3">
            <Card class="lg:col-span-1">
                <CardHeader>
                    <CardTitle>Datos</CardTitle>
                </CardHeader>
                <CardContent>
                    <dl class="grid gap-3 text-sm">
                        <div>
                            <dt class="text-muted-foreground">Nombre</dt>
                            <dd>{{ contact.first_name ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Apellido</dt>
                            <dd>{{ contact.last_name ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Teléfono</dt>
                            <dd>{{ contact.phone ?? '—' }}</dd>
                        </div>
                        <div v-for="field in fields" :key="field.key">
                            <dt class="text-muted-foreground">
                                {{ field.label }}
                            </dt>
                            <dd>{{ fieldDisplay(field) }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Etiquetas</dt>
                            <dd class="mt-1 flex flex-wrap gap-1">
                                <Badge
                                    v-for="tag in contact.tags"
                                    :key="tag.id"
                                    variant="secondary"
                                    >{{ tag.name }}</Badge
                                >
                                <span v-if="!contact.tags.length">—</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Creado</dt>
                            <dd>{{ dateTime(contact.created_at) }}</dd>
                        </div>
                    </dl>
                </CardContent>
            </Card>

            <div class="space-y-6 lg:col-span-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Audiencias</CardTitle>
                        <CardDescription
                            >Estado y prueba de consentimiento en cada
                            audiencia.</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <p
                            v-if="!subscriptions.length"
                            class="text-sm text-muted-foreground"
                        >
                            No pertenece a ninguna audiencia.
                        </p>
                        <article
                            v-for="s in subscriptions"
                            :key="s.id"
                            class="space-y-3 rounded-lg border p-4"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <Link
                                    v-if="s.audience"
                                    :href="showAudience(s.audience.id)"
                                    class="font-medium hover:underline"
                                    >{{ s.audience.name }}</Link
                                >
                                <ContactStatusBadge
                                    :status="s.status"
                                    :label="s.status_label"
                                />
                            </div>
                            <dl class="grid gap-2 text-sm sm:grid-cols-2">
                                <div>
                                    <dt class="text-muted-foreground">
                                        Consentimiento
                                    </dt>
                                    <dd>
                                        <template v-if="s.consent_at">
                                            {{
                                                sourceLabels[
                                                    s.consent_source ?? ''
                                                ] ?? s.consent_source
                                            }}
                                            · {{ dateTime(s.consent_at) }}
                                            <span
                                                v-if="s.consent_ip"
                                                class="text-muted-foreground"
                                                >· IP {{ s.consent_ip }}</span
                                            >
                                        </template>
                                        <template v-else>Sin registro</template>
                                    </dd>
                                </div>
                                <div v-if="s.consent_detail">
                                    <dt class="text-muted-foreground">
                                        Detalle
                                    </dt>
                                    <dd>{{ s.consent_detail }}</dd>
                                </div>
                                <div v-if="s.unsubscribed_at">
                                    <dt class="text-muted-foreground">
                                        Baja / limpieza
                                    </dt>
                                    <dd>{{ dateTime(s.unsubscribed_at) }}</dd>
                                </div>
                            </dl>

                            <p
                                v-if="s.block_reason"
                                class="rounded-md bg-muted p-2 text-sm text-muted-foreground"
                            >
                                {{ s.block_reason }}
                            </p>

                            <template v-if="canManage">
                                <Button
                                    v-if="editing !== s.id"
                                    size="sm"
                                    variant="outline"
                                    @click="startEdit(s)"
                                    >Cambiar estado</Button
                                >
                                <div v-else class="grid gap-3 sm:grid-cols-2">
                                    <div class="grid gap-1.5">
                                        <Label :for="`status-${s.id}`"
                                            >Nuevo estado</Label
                                        >
                                        <NativeSelect
                                            :id="`status-${s.id}`"
                                            v-model="newStatus"
                                        >
                                            <option
                                                v-for="option in statuses"
                                                :key="option.value"
                                                :value="option.value"
                                            >
                                                {{ option.label }}
                                            </option>
                                        </NativeSelect>
                                    </div>
                                    <div
                                        v-if="
                                            newStatus === 'subscribed' &&
                                            !s.consent_at
                                        "
                                        class="grid gap-1.5"
                                    >
                                        <Label :for="`consent-${s.id}`"
                                            >¿Cómo dio su consentimiento?</Label
                                        >
                                        <Input
                                            :id="`consent-${s.id}`"
                                            v-model="consentDetail"
                                        />
                                    </div>
                                    <p
                                        v-if="statusErrors.status"
                                        class="text-sm text-destructive sm:col-span-2"
                                        role="alert"
                                    >
                                        {{ statusErrors.status }}
                                    </p>
                                    <div class="flex gap-2 sm:col-span-2">
                                        <Button size="sm" @click="saveStatus(s)"
                                            >Guardar</Button
                                        >
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            @click="editing = null"
                                            >Cancelar</Button
                                        >
                                    </div>
                                </div>
                            </template>
                        </article>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Campañas recibidas</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <EmptyState
                            :icon="Mail"
                            title="Sin campañas todavía"
                            description="Aquí aparecerán las campañas enviadas a este contacto y su actividad (aperturas, clics)."
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <History class="size-4" aria-hidden="true" />
                            Actividad
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ol v-if="activity.length" class="space-y-3 text-sm">
                            <li
                                v-for="item in activity"
                                :key="item.id"
                                class="flex flex-col gap-0.5 border-l-2 pl-3"
                            >
                                <span>{{ item.description }}</span>
                                <span class="text-xs text-muted-foreground">
                                    {{ dateTime(item.created_at) }}
                                    <template v-if="item.causer">
                                        · {{ item.causer }}</template
                                    >
                                </span>
                            </li>
                        </ol>
                        <p v-else class="text-sm text-muted-foreground">
                            Sin actividad registrada.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>

    <ConfirmDialog
        v-model:open="confirmingDelete"
        title="¿Eliminar el contacto?"
        :description="`Se eliminará ${contact.email} de todas las audiencias. Si está en la lista de supresión, la supresión se conserva.`"
        confirm-label="Eliminar"
        destructive
        @confirm="deleteContact"
    />
</template>
