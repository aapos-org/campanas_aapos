<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    CircleAlert,
    Pencil,
    Plug,
    Plus,
    Send,
    ShieldCheck,
    Trash2,
    XCircle,
} from '@lucide/vue';
import { ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
import {
    create,
    destroy,
    edit,
    index,
    test,
    verify,
} from '@/routes/connections';
import { redirect as googleRedirect } from '@/routes/connections/google';
import type { MailConnection } from '@/types';

defineProps<{
    connections: MailConnection[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Conexiones de envío', href: index() }],
    },
});

const { dateTime, number } = useFormat();

const testTarget = ref<MailConnection | null>(null);
const deleteTarget = ref<MailConnection | null>(null);
const deleting = ref(false);
const verifying = ref<number | null>(null);

const statusVariant = (status: MailConnection['status']) =>
    status === 'active'
        ? 'secondary'
        : status === 'error'
          ? 'destructive'
          : 'outline';

const usagePercent = (c: MailConnection) =>
    c.daily_limit
        ? Math.min(100, Math.round((c.used_today * 100) / c.daily_limit))
        : 0;

const runVerify = (c: MailConnection) => {
    router.post(
        verify.url(c.id),
        {},
        {
            preserveScroll: true,
            onStart: () => (verifying.value = c.id),
            onFinish: () => (verifying.value = null),
        },
    );
};

const confirmDelete = () => {
    if (!deleteTarget.value) {
        return;
    }

    router.delete(destroy.url(deleteTarget.value.id), {
        onStart: () => (deleting.value = true),
        onFinish: () => {
            deleting.value = false;
            deleteTarget.value = null;
        },
    });
};

const records = [
    { key: 'spf', label: 'SPF' },
    { key: 'dkim', label: 'DKIM' },
    { key: 'dmarc', label: 'DMARC' },
    { key: 'mx', label: 'MX' },
] as const;
</script>

<template>
    <Head title="Conexiones de envío" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                title="Conexiones de envío"
                description="Cuentas desde las que se envían los correos. Cada campaña elige una."
            />
            <Button as-child class="w-full sm:w-auto">
                <Link :href="create()">
                    <Plus aria-hidden="true" />
                    Nueva conexión
                </Link>
            </Button>
        </div>

        <EmptyState
            v-if="connections.length === 0"
            :icon="Plug"
            title="Todavía no hay conexiones"
            description="Agrega una cuenta de Gmail para pruebas o ElasticEmail para los envíos reales."
        >
            <Button as-child class="mt-2">
                <Link :href="create()">Agregar conexión</Link>
            </Button>
        </EmptyState>

        <div v-else class="grid gap-4 xl:grid-cols-2">
            <Card v-for="c in connections" :key="c.id">
                <CardHeader>
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <CardTitle class="flex items-center gap-2">
                                <span class="truncate">{{ c.name }}</span>
                                <Badge v-if="c.is_default" variant="outline"
                                    >Predeterminada</Badge
                                >
                            </CardTitle>
                            <CardDescription class="mt-1">
                                {{ c.driver_label }}
                            </CardDescription>
                        </div>
                        <Badge :variant="statusVariant(c.status)">
                            <CheckCircle2
                                v-if="c.status === 'active'"
                                aria-hidden="true"
                            />
                            <XCircle
                                v-else-if="c.status === 'error'"
                                aria-hidden="true"
                            />
                            {{ c.status_label }}
                        </Badge>
                    </div>
                </CardHeader>

                <CardContent class="space-y-4 text-sm">
                    <dl class="grid gap-2 sm:grid-cols-2">
                        <div>
                            <dt class="text-muted-foreground">Remitente</dt>
                            <dd class="break-all">
                                {{ c.from_name ? `${c.from_name} · ` : ''
                                }}{{ c.from_email }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Última verificación
                            </dt>
                            <dd>{{ dateTime(c.last_verified_at) }}</dd>
                        </div>
                        <div v-if="c.driver === 'gmail_api'">
                            <dt class="text-muted-foreground">
                                Cuenta autorizada
                            </dt>
                            <dd>{{ c.oauth_account ?? 'Sin autorizar' }}</dd>
                        </div>
                    </dl>

                    <div v-if="c.daily_limit" class="space-y-1">
                        <div class="flex justify-between text-muted-foreground">
                            <span>Envíos de hoy</span>
                            <span class="tabular-nums"
                                >{{ number(c.used_today) }} /
                                {{ number(c.daily_limit) }}</span
                            >
                        </div>
                        <div
                            class="h-2 overflow-hidden rounded-full bg-muted"
                            role="progressbar"
                            :aria-valuenow="c.used_today"
                            :aria-valuemax="c.daily_limit"
                            aria-label="Uso del límite diario"
                        >
                            <div
                                class="h-full rounded-full bg-primary transition-all"
                                :class="{
                                    'bg-destructive': usagePercent(c) >= 90,
                                }"
                                :style="{ width: `${usagePercent(c)}%` }"
                            />
                        </div>
                    </div>
                    <p v-else class="text-muted-foreground">
                        Envíos de hoy: {{ number(c.used_today) }} (sin límite en
                        la aplicación)
                    </p>

                    <Alert
                        v-if="c.is_gmail"
                        class="border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100"
                    >
                        <AlertTriangle />
                        <AlertTitle
                            >Solo para pruebas o listas pequeñas</AlertTitle
                        >
                        <AlertDescription>
                            Gmail limita los envíos diarios (≈500 en cuentas
                            gratuitas y 2,000 en Workspace). La aplicación se
                            detiene al llegar al límite configurado.
                        </AlertDescription>
                    </Alert>

                    <div
                        v-if="c.verification?.records"
                        class="space-y-2"
                        aria-label="Estado DNS del dominio"
                    >
                        <p class="text-muted-foreground">
                            DNS de {{ c.verification.domain }}
                        </p>
                        <ul class="flex flex-wrap gap-2">
                            <li v-for="r in records" :key="r.key">
                                <Badge
                                    :variant="
                                        c.verification.records[r.key]
                                            ? 'secondary'
                                            : 'destructive'
                                    "
                                >
                                    <ShieldCheck
                                        v-if="c.verification.records[r.key]"
                                        aria-hidden="true"
                                    />
                                    <CircleAlert v-else aria-hidden="true" />
                                    {{ r.label }}
                                    <span class="sr-only">{{
                                        c.verification.records[r.key]
                                            ? 'verificado'
                                            : 'sin verificar'
                                    }}</span>
                                </Badge>
                            </li>
                        </ul>
                    </div>

                    <p
                        v-if="c.provider_tracking_locked"
                        class="rounded-md bg-muted p-3 text-muted-foreground"
                    >
                        Tu plan de ElasticEmail no permite desactivar su propio
                        rastreo de aperturas y clics, así que lo mantiene
                        activo. Los envíos funcionan con normalidad y las
                        métricas de esta aplicación no se ven afectadas.
                    </p>

                    <p
                        v-if="c.last_error"
                        class="rounded-md bg-destructive/10 p-3 text-destructive"
                        role="status"
                    >
                        {{ c.last_error }}
                    </p>
                </CardContent>

                <CardFooter class="flex flex-wrap gap-2">
                    <Button
                        v-if="c.driver === 'gmail_api' && !c.oauth_connected"
                        as="a"
                        :href="googleRedirect.url(c.id)"
                        size="sm"
                    >
                        Conectar con Google
                    </Button>
                    <Button
                        variant="secondary"
                        size="sm"
                        :disabled="verifying === c.id"
                        @click="runVerify(c)"
                    >
                        <Spinner v-if="verifying === c.id" />
                        <ShieldCheck v-else aria-hidden="true" />
                        Verificar
                    </Button>
                    <Button
                        variant="secondary"
                        size="sm"
                        @click="testTarget = c"
                    >
                        <Send aria-hidden="true" />
                        Enviar prueba
                    </Button>
                    <Button variant="ghost" size="sm" as-child>
                        <Link :href="edit(c.id)">
                            <Pencil aria-hidden="true" />
                            Editar
                        </Link>
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="text-destructive"
                        @click="deleteTarget = c"
                    >
                        <Trash2 aria-hidden="true" />
                        Eliminar
                    </Button>
                </CardFooter>
            </Card>
        </div>

        <section
            class="space-y-2 rounded-xl border p-4 text-sm"
            aria-labelledby="dns-guide"
        >
            <h2 id="dns-guide" class="font-medium">
                Configurar el dominio de envío (ElasticEmail)
            </h2>
            <ol class="list-decimal space-y-1 pl-5 text-muted-foreground">
                <li>
                    En ElasticEmail, agrega tu dominio en
                    <em>Settings → Domains</em>.
                </li>
                <li>
                    Crea en tu DNS los registros que indica:
                    <strong>SPF</strong> (TXT), <strong>DKIM</strong> (TXT) y el
                    CNAME de rastreo.
                </li>
                <li>
                    Agrega un registro <strong>DMARC</strong>, por ejemplo
                    <code>_dmarc</code> TXT
                    <code>v=DMARC1; p=none; rua=mailto:dmarc@tu-dominio</code>,
                    y endurécelo a <code>quarantine</code> cuando todo funcione.
                </li>
                <li>
                    Vuelve aquí y pulsa <strong>Verificar</strong> para ver el
                    estado de cada registro.
                </li>
            </ol>
        </section>
    </div>

    <Dialog
        :open="testTarget !== null"
        @update:open="(v) => !v && (testTarget = null)"
    >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Enviar correo de prueba</DialogTitle>
                <DialogDescription>
                    Se enviará con «{{ testTarget?.name }}» y contará para su
                    límite diario.
                </DialogDescription>
            </DialogHeader>
            <Form
                v-if="testTarget"
                v-bind="test.form(testTarget.id)"
                :options="{ preserveScroll: true }"
                class="space-y-4"
                @success="testTarget = null"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="test-to">Destinatario</Label>
                    <Input
                        id="test-to"
                        name="to"
                        type="email"
                        required
                        placeholder="tu-correo@ejemplo.com"
                        v-focus
                    />
                    <InputError :message="errors.to" />
                </div>
                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        @click="testTarget = null"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="processing">
                        <Spinner v-if="processing" />
                        Enviar
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog
        :open="deleteTarget !== null"
        title="¿Eliminar la conexión?"
        :description="`Se eliminará «${deleteTarget?.name}». Las campañas que la usen deberán elegir otra.`"
        confirm-label="Eliminar"
        destructive
        :processing="deleting"
        @update:open="(v) => !v && (deleteTarget = null)"
        @confirm="confirmDelete"
    />
</template>
