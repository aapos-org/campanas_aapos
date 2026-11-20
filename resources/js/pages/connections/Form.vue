<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { AlertTriangle, Info } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import MailConnectionController from '@/actions/App/Http/Controllers/Connections/MailConnectionController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index } from '@/routes/connections';
import type { DriverValue, MailConnection, Option } from '@/types';

const props = defineProps<{
    connection: MailConnection | null;
    drivers: Option<DriverValue>[];
    googleConfigured: boolean;
    suggestedLimits: Record<string, number | null>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Conexiones de envío', href: index() }],
    },
});

const isEdit = computed(() => props.connection !== null);
const driver = ref<DriverValue>(props.connection?.driver ?? 'elastic_email');
const isDefault = ref<boolean>(props.connection?.is_default ?? false);
const dailyLimit = ref<string>(
    props.connection?.daily_limit != null
        ? String(props.connection.daily_limit)
        : '',
);

watch(driver, (value) => {
    if (!isEdit.value) {
        const suggested = props.suggestedLimits[value];
        dailyLimit.value = suggested ? String(suggested) : '';
    }
});

const isGmail = computed(() => driver.value !== 'elastic_email');

const formDefinition = computed(() =>
    props.connection
        ? MailConnectionController.update.form(props.connection.id)
        : MailConnectionController.store.form(),
);

const descriptions: Record<DriverValue, string> = {
    elastic_email:
        'Para los envíos reales. Usa la API v4 y una clave con permisos de envío.',
    gmail_smtp:
        'Para pruebas. Necesita la verificación en dos pasos y una contraseña de aplicación.',
    gmail_api:
        'Para pruebas. Autorizas la cuenta en Google; no se guarda ninguna contraseña.',
};
</script>

<template>
    <Head :title="isEdit ? 'Editar conexión' : 'Nueva conexión'" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            :title="isEdit ? 'Editar conexión' : 'Nueva conexión de envío'"
            description="Los datos de acceso se guardan cifrados y nunca se muestran de nuevo."
        />

        <Form
            v-bind="formDefinition"
            :transform="
                (data) => ({
                    ...data,
                    driver: isEdit ? undefined : driver,
                    is_default: isDefault,
                })
            "
            class="max-w-2xl space-y-6"
            v-slot="{ errors, processing }"
        >
            <fieldset v-if="!isEdit" class="grid gap-3">
                <legend class="mb-1 text-sm font-medium">
                    Tipo de conexión
                </legend>
                <label
                    v-for="d in drivers"
                    :key="d.value"
                    :class="[
                        'flex cursor-pointer gap-3 rounded-lg border p-3 transition-colors',
                        driver === d.value
                            ? 'border-primary bg-primary/5'
                            : 'hover:bg-muted/50',
                    ]"
                >
                    <input
                        v-model="driver"
                        type="radio"
                        name="driver_choice"
                        :value="d.value"
                        class="mt-1 size-4"
                    />
                    <span>
                        <span class="block text-sm font-medium">{{
                            d.label
                        }}</span>
                        <span class="block text-sm text-muted-foreground">{{
                            descriptions[d.value]
                        }}</span>
                    </span>
                </label>
                <InputError :message="errors.driver" />
            </fieldset>

            <Alert
                v-if="isGmail"
                class="border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100"
            >
                <AlertTriangle />
                <AlertTitle>Gmail es solo para pruebas</AlertTitle>
                <AlertDescription>
                    Google limita los envíos (≈500 al día en cuentas gratuitas y
                    2,000 en Workspace) y puede bloquear la cuenta si detecta
                    envíos masivos. Configura un límite diario con margen.
                </AlertDescription>
            </Alert>

            <div class="grid gap-2">
                <Label for="name">Nombre de la conexión</Label>
                <Input
                    id="name"
                    name="name"
                    :default-value="connection?.name"
                    placeholder="Ej.: ElasticEmail boletín"
                    required
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="from_name">Nombre del remitente</Label>
                    <Input
                        id="from_name"
                        name="from_name"
                        :default-value="connection?.from_name ?? ''"
                        placeholder="AAPOS"
                    />
                    <InputError :message="errors.from_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="from_email">Correo del remitente</Label>
                    <Input
                        id="from_email"
                        name="from_email"
                        type="email"
                        :default-value="connection?.from_email"
                        placeholder="boletin@tu-dominio.org"
                        required
                    />
                    <InputError :message="errors.from_email" />
                </div>
                <div class="grid gap-2">
                    <Label for="reply_to">Responder a (opcional)</Label>
                    <Input
                        id="reply_to"
                        name="reply_to"
                        type="email"
                        :default-value="connection?.reply_to ?? ''"
                    />
                    <InputError :message="errors.reply_to" />
                </div>
                <div class="grid gap-2">
                    <Label for="daily_limit">
                        Límite diario de envíos{{
                            isGmail ? '' : ' (opcional)'
                        }}
                    </Label>
                    <Input
                        id="daily_limit"
                        v-model="dailyLimit"
                        name="daily_limit"
                        type="number"
                        min="1"
                        :max="isGmail ? 2000 : undefined"
                        :required="isGmail"
                    />
                    <InputError :message="errors.daily_limit" />
                </div>
            </div>

            <template v-if="driver === 'gmail_smtp'">
                <div class="grid gap-2">
                    <Label for="username">Cuenta de Gmail</Label>
                    <Input
                        id="username"
                        name="username"
                        type="email"
                        :default-value="connection?.username ?? ''"
                        autocomplete="off"
                        required
                    />
                    <InputError :message="errors.username" />
                </div>
                <div class="grid gap-2">
                    <Label for="password">Contraseña de aplicación</Label>
                    <PasswordInput
                        id="password"
                        name="password"
                        autocomplete="new-password"
                        :placeholder="
                            connection?.has_password
                                ? 'Guardada · escribe una nueva para cambiarla'
                                : 'abcd efgh ijkl mnop'
                        "
                        :required="!isEdit"
                    />
                    <p class="text-sm text-muted-foreground">
                        Créala en tu cuenta de Google → Seguridad → Contraseñas
                        de aplicaciones (requiere la verificación en dos pasos).
                    </p>
                    <InputError :message="errors.password" />
                </div>
            </template>

            <template v-if="driver === 'elastic_email'">
                <div class="grid gap-2">
                    <Label for="api_key">Clave de API (v4)</Label>
                    <PasswordInput
                        id="api_key"
                        name="api_key"
                        autocomplete="off"
                        :placeholder="
                            connection?.api_key_hint
                                ? `Guardada (${connection.api_key_hint}) · escribe una nueva para cambiarla`
                                : 'Pega aquí la clave'
                        "
                        :required="!isEdit"
                    />
                    <p class="text-sm text-muted-foreground">
                        En ElasticEmail: Settings → Manage API Keys. Permisos
                        necesarios: enviar por HTTP, ver cuenta y ver ajustes.
                    </p>
                    <InputError :message="errors.api_key" />
                </div>
            </template>

            <Alert v-if="driver === 'gmail_api'">
                <Info />
                <AlertTitle>Autorización con Google</AlertTitle>
                <AlertDescription>
                    <template v-if="googleConfigured">
                        Al guardar te llevaremos a Google para autorizar el
                        envío como
                        {{ connection?.from_email ?? 'el remitente' }}. Usa la
                        misma cuenta que el correo del remitente.
                    </template>
                    <template v-else>
                        Un administrador del servidor debe configurar
                        GOOGLE_CLIENT_ID y GOOGLE_CLIENT_SECRET antes de usar
                        esta opción. Mientras tanto, usa Gmail por SMTP.
                    </template>
                </AlertDescription>
            </Alert>

            <div class="flex items-center gap-3">
                <Checkbox
                    id="is_default"
                    :model-value="isDefault"
                    @update:model-value="(v) => (isDefault = v === true)"
                />
                <Label for="is_default"
                    >Usar como conexión predeterminada</Label
                >
            </div>

            <div
                class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center"
            >
                <Button variant="outline" as-child>
                    <Link :href="index()">Cancelar</Link>
                </Button>
                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    {{ isEdit ? 'Guardar cambios' : 'Guardar y verificar' }}
                </Button>
            </div>
        </Form>
    </div>
</template>
