<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Copy, Pencil, RotateCcw, Send, Trash2, Webhook } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFormat } from '@/composables/useFormat';
import {
    destroy,
    index,
    store,
    test,
    update,
} from '@/routes/admin/outgoing-webhooks';
import { retry } from '@/routes/admin/webhook-deliveries';
import type { Paginated } from '@/types';

type Endpoint = {
    id: number;
    name: string;
    url: string;
    secret: string;
    events: string[];
    is_active: boolean;
    consecutive_failures: number;
    last_success_at: string | null;
    last_failure_at: string | null;
};
type Delivery = {
    id: number;
    endpoint: string | null;
    event: string;
    status: 'pending' | 'delivered' | 'failed';
    attempts: number;
    response_status: number | null;
    error: string | null;
    payload: unknown;
    response_body: string | null;
    created_at: string | null;
    delivered_at: string | null;
};

const props = defineProps<{
    endpoints: Endpoint[];
    deliveries: Paginated<Delivery>;
    eventOptions: { value: string; label: string }[];
    filters: { endpoint: number | null };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Webhooks salientes', href: index() }] },
});

const { dateTime } = useFormat();
const editing = ref<Endpoint | null>(null);
const deleting = ref<Endpoint | null>(null);
const endpointForm = ref({
    name: '',
    url: '',
    events: props.eventOptions.map((o) => o.value),
    is_active: true,
});
const errors = ref<Record<string, string>>({});
const processing = ref(false);

const resetForm = () => {
    editing.value = null;
    endpointForm.value.name = '';
    endpointForm.value.url = '';
    endpointForm.value.events = props.eventOptions.map((o) => o.value);
    endpointForm.value.is_active = true;
    errors.value = {};
};
const startEdit = (e: Endpoint) => {
    editing.value = e;
    endpointForm.value.name = e.name;
    endpointForm.value.url = e.url;
    endpointForm.value.events = [...e.events];
    endpointForm.value.is_active = e.is_active;
};
const toggleEvent = (v: string) => {
    endpointForm.value.events = endpointForm.value.events.includes(v)
        ? endpointForm.value.events.filter((x) => x !== v)
        : [...endpointForm.value.events, v];
};
const submit = () => {
    const options = {
        preserveScroll: true,
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
        onError: (e: Record<string, string>) => (errors.value = e),
        onSuccess: resetForm,
    };

    if (editing.value) {
        router.put(
            update.url(editing.value.id),
            { ...endpointForm.value },
            options,
        );
    } else {
        router.post(store.url(), { ...endpointForm.value }, options);
    }
};

const copy = async (text: string) => {
    await navigator.clipboard.writeText(text);
    toast.success('Secreto copiado.');
};
const deliveryStatusLabel = (status: Delivery['status']) =>
    ({ pending: 'Pendiente', delivered: 'Entregado', failed: 'Falló' })[status];
const eventLabel = (v: string) =>
    props.eventOptions.find((o) => o.value === v)?.label ?? v;
</script>

<template>
    <Head title="Webhooks salientes" />

    <div class="flex max-w-4xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Webhooks salientes"
            description="Avisa a otros sistemas cuando alguien se suscribe o se da de baja, rebota un correo, hace clic o termina una campaña."
        />

        <details class="rounded-xl border p-4 text-sm">
            <summary class="cursor-pointer font-medium">
                Cómo verificar la firma
            </summary>
            <div class="mt-2 space-y-2 text-muted-foreground">
                <p>
                    Cada envío es un <code>POST</code> con JSON y la cabecera
                    <code
                        >X-AAPOS-Signature: t=&lt;marca de
                        tiempo&gt;,v1=&lt;firma&gt;</code
                    >. La firma es HMAC-SHA256, en hexadecimal, de
                    <code>&lt;marca de tiempo&gt;.&lt;cuerpo&gt;</code> con el
                    secreto del destino.
                </p>
                <p>
                    Responde con un código 2xx. Si falla, se reintenta hasta 6
                    veces (1 min, 5 min, 30 min, 2 h, 6 h). Tras 20 fallos
                    seguidos el destino se desactiva.
                </p>
            </div>
        </details>

        <form
            class="grid gap-4 rounded-xl border p-4"
            aria-labelledby="s-form"
            @submit.prevent="submit"
        >
            <h2 id="s-form" class="font-medium">
                {{
                    editing ? 'Editar «' + editing.name + '»' : 'Nuevo destino'
                }}
            </h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="w-name">Nombre</Label>
                    <Input id="w-name" v-model="endpointForm.name" required />
                    <InputError :message="errors.name" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="w-url">URL</Label>
                    <Input
                        id="w-url"
                        v-model="endpointForm.url"
                        type="url"
                        placeholder="https://"
                        required
                    />
                    <InputError :message="errors.url" />
                </div>
            </div>
            <fieldset class="grid gap-2 sm:grid-cols-2">
                <legend class="mb-1 text-sm font-medium">Eventos</legend>
                <label
                    v-for="option in eventOptions"
                    :key="option.value"
                    class="flex items-center gap-2 text-sm"
                    ><Checkbox
                        :model-value="
                            endpointForm.events.includes(option.value)
                        "
                        @update:model-value="toggleEvent(option.value)"
                    />{{ option.label }}
                    <code class="text-xs text-muted-foreground">{{
                        option.value
                    }}</code></label
                >
                <InputError :message="errors.events" />
            </fieldset>
            <label class="flex items-center gap-2 text-sm"
                ><Checkbox
                    :model-value="endpointForm.is_active"
                    @update:model-value="
                        (v) => (endpointForm.is_active = v === true)
                    "
                />Activo</label
            >
            <div class="flex gap-2">
                <Button type="submit" :disabled="processing">{{
                    editing ? 'Guardar' : 'Crear destino'
                }}</Button>
                <Button
                    v-if="editing"
                    type="button"
                    variant="ghost"
                    @click="resetForm"
                    >Cancelar</Button
                >
            </div>
        </form>

        <section class="space-y-2" aria-labelledby="s-endpoints">
            <h2 id="s-endpoints" class="font-medium">Destinos</h2>
            <EmptyState
                v-if="!endpoints.length"
                :icon="Webhook"
                title="Aún no hay destinos"
                description="Agrega la URL del sistema que debe recibir los avisos."
            />
            <ul v-else class="grid gap-3">
                <li
                    v-for="e in endpoints"
                    :key="e.id"
                    class="space-y-2 rounded-xl border p-4"
                >
                    <div
                        class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div class="min-w-0">
                            <p class="flex items-center gap-2 font-medium">
                                {{ e.name }}
                                <Badge
                                    :variant="
                                        e.is_active ? 'default' : 'outline'
                                    "
                                    >{{
                                        e.is_active ? 'Activo' : 'Inactivo'
                                    }}</Badge
                                >
                            </p>
                            <p class="truncate text-sm text-muted-foreground">
                                {{ e.url }}
                            </p>
                        </div>
                        <div class="flex shrink-0 gap-1">
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="router.post(test.url(e.id))"
                                ><Send aria-hidden="true" />Probar</Button
                            >
                            <Button
                                variant="ghost"
                                size="icon"
                                :aria-label="`Editar ${e.name}`"
                                @click="startEdit(e)"
                                ><Pencil aria-hidden="true"
                            /></Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                :aria-label="`Eliminar ${e.name}`"
                                @click="deleting = e"
                                ><Trash2 aria-hidden="true"
                            /></Button>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-1">
                        <Badge
                            v-for="ev in e.events"
                            :key="ev"
                            variant="outline"
                            >{{ eventLabel(ev) }}</Badge
                        >
                    </div>
                    <div class="flex items-center gap-2 text-sm">
                        <span class="text-muted-foreground">Secreto:</span>
                        <code
                            class="truncate rounded bg-muted px-1.5 py-0.5 text-xs"
                            >{{ e.secret.slice(0, 10) }}…</code
                        >
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="copy(e.secret)"
                            ><Copy aria-hidden="true" />Copiar</Button
                        >
                    </div>
                    <p
                        v-if="e.consecutive_failures"
                        class="text-sm text-destructive"
                    >
                        {{ e.consecutive_failures }} fallos seguidos.
                    </p>
                </li>
            </ul>
        </section>

        <section class="space-y-2" aria-labelledby="s-deliveries">
            <h2 id="s-deliveries" class="font-medium">Registro de entregas</h2>
            <p
                v-if="!deliveries.data.length"
                class="text-sm text-muted-foreground"
            >
                Aún no hay entregas.
            </p>
            <ul v-else class="grid gap-2">
                <li
                    v-for="d in deliveries.data"
                    :key="d.id"
                    class="rounded-xl border p-3 text-sm"
                >
                    <div
                        class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <span
                            ><strong>{{ d.event }}</strong> → {{ d.endpoint }}
                            <span class="text-muted-foreground"
                                >· {{ dateTime(d.created_at) }} ·
                                {{ d.attempts }} intento(s)<template
                                    v-if="d.response_status"
                                >
                                    · HTTP {{ d.response_status }}</template
                                ></span
                            ></span
                        >
                        <span class="flex items-center gap-2">
                            <Badge
                                :variant="
                                    d.status === 'failed'
                                        ? 'destructive'
                                        : d.status === 'delivered'
                                          ? 'default'
                                          : 'outline'
                                "
                                >{{ deliveryStatusLabel(d.status) }}</Badge
                            >
                            <Button
                                v-if="d.status === 'failed'"
                                variant="ghost"
                                size="sm"
                                @click="router.post(retry.url(d.id))"
                                ><RotateCcw
                                    aria-hidden="true"
                                />Reenviar</Button
                            >
                        </span>
                    </div>
                    <p v-if="d.error" class="mt-1 text-destructive">
                        {{ d.error }}
                    </p>
                    <details class="mt-1">
                        <summary class="cursor-pointer text-muted-foreground">
                            Ver contenido
                        </summary>
                        <pre
                            class="mt-2 overflow-x-auto rounded-md bg-muted p-3 text-xs"
                            >{{ JSON.stringify(d.payload, null, 2) }}</pre>
                    </details>
                </li>
            </ul>
            <Pagination :page="deliveries" />
        </section>
    </div>

    <ConfirmDialog
        :open="deleting !== null"
        title="¿Eliminar el destino?"
        :description="`«${deleting?.name}» dejará de recibir avisos y se borrará su registro de entregas.`"
        confirm-label="Eliminar"
        destructive
        @update:open="(v: boolean) => !v && (deleting = null)"
        @confirm="
            deleting &&
            router.delete(destroy.url(deleting.id), {
                onFinish: () => (deleting = null),
            })
        "
    />
</template>
