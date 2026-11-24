<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Copy, KeyRound, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFormat } from '@/composables/useFormat';
import { index } from '@/routes/admin/api';
import { destroy, store } from '@/routes/admin/api/tokens';

type Token = {
    id: number;
    name: string;
    owner: string;
    abilities: string[];
    last_used_at: string | null;
    expires_at: string | null;
    created_at: string | null;
};

defineProps<{
    tokens: Token[];
    abilities: { value: string; label: string; permission: string }[];
    newToken: string | null;
    baseUrl: string;
    docsUrl: string;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'API', href: index() }] },
});

const { dateTime, date } = useFormat();
const form = useForm({
    name: '',
    abilities: [] as string[],
    expires_in_days: '365',
});
const revoking = ref<Token | null>(null);

const toggle = (value: string) => {
    form.abilities = form.abilities.includes(value)
        ? form.abilities.filter((a) => a !== value)
        : [...form.abilities, value];
};

const submit = () =>
    form
        .transform((d) => ({
            ...d,
            expires_in_days: d.expires_in_days
                ? Number(d.expires_in_days)
                : null,
        }))
        .post(store.url(), { onSuccess: () => form.reset() });

const copy = async (text: string) => {
    await navigator.clipboard.writeText(text);
    toast.success('Copiado.');
};
</script>

<template>
    <Head title="API" />

    <div class="flex max-w-4xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="API"
            description="Conecta otros sistemas (sitio web, CRM) con la plataforma. Cada token tiene solo los permisos que elijas."
        />

        <p class="text-sm">
            Dirección base:
            <code class="rounded bg-muted px-1.5 py-0.5">{{ baseUrl }}</code>
            ·
            <a
                :href="docsUrl"
                target="_blank"
                rel="noopener"
                class="underline underline-offset-2"
                >Documentación (OpenAPI)</a
            >
        </p>

        <Alert v-if="newToken">
            <KeyRound />
            <AlertTitle>Copia el token ahora</AlertTitle>
            <AlertDescription class="space-y-2">
                <p>No se volverá a mostrar. Guárdalo en un lugar seguro.</p>
                <div class="flex items-center gap-2">
                    <code
                        class="block flex-1 overflow-x-auto rounded bg-muted px-2 py-1 text-xs break-all"
                        >{{ newToken }}</code
                    >
                    <Button
                        type="button"
                        size="sm"
                        variant="secondary"
                        @click="copy(newToken)"
                        ><Copy aria-hidden="true" />Copiar</Button
                    >
                </div>
                <p class="text-xs">
                    Úsalo en la cabecera
                    <code>Authorization: Bearer &lt;token&gt;</code>.
                </p>
            </AlertDescription>
        </Alert>

        <form
            class="grid gap-4 rounded-xl border p-4"
            aria-labelledby="s-new"
            @submit.prevent="submit"
        >
            <h2 id="s-new" class="font-medium">Nuevo token</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="t-name">Nombre</Label>
                    <Input
                        id="t-name"
                        v-model="form.name"
                        placeholder="Ej.: Sitio web"
                        required
                    />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="t-exp">Vence en (días, vacío = no vence)</Label>
                    <Input
                        id="t-exp"
                        v-model="form.expires_in_days"
                        type="number"
                        min="1"
                        max="730"
                    />
                    <InputError :message="form.errors.expires_in_days" />
                </div>
            </div>
            <fieldset class="grid gap-2">
                <legend class="mb-1 text-sm font-medium">Permisos</legend>
                <label
                    v-for="a in abilities"
                    :key="a.value"
                    class="flex items-center gap-2 text-sm"
                    ><Checkbox
                        :model-value="form.abilities.includes(a.value)"
                        @update:model-value="toggle(a.value)"
                    />{{ a.label }}
                    <code class="text-xs text-muted-foreground">{{
                        a.value
                    }}</code></label
                >
                <InputError :message="form.errors.abilities" />
            </fieldset>
            <div>
                <Button type="submit" :disabled="form.processing"
                    >Crear token</Button
                >
            </div>
        </form>

        <section class="space-y-2" aria-labelledby="s-tokens">
            <h2 id="s-tokens" class="font-medium">Tokens</h2>
            <EmptyState
                v-if="!tokens.length"
                :icon="KeyRound"
                title="Aún no hay tokens"
                description="Crea uno para conectar otro sistema."
            />
            <ul v-else class="grid gap-3">
                <li
                    v-for="t in tokens"
                    :key="t.id"
                    class="flex flex-col gap-2 rounded-xl border p-4 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div class="min-w-0 space-y-1">
                        <p class="font-medium">{{ t.name }}</p>
                        <p class="text-sm text-muted-foreground">
                            De {{ t.owner }} · creado {{ date(t.created_at) }} ·
                            {{
                                t.last_used_at
                                    ? `último uso ${dateTime(t.last_used_at)}`
                                    : 'sin usar'
                            }}
                            ·
                            {{
                                t.expires_at
                                    ? `vence ${date(t.expires_at)}`
                                    : 'no vence'
                            }}
                        </p>
                        <div class="flex flex-wrap gap-1">
                            <Badge
                                v-for="a in t.abilities"
                                :key="a"
                                variant="outline"
                                >{{ a }}</Badge
                            >
                        </div>
                    </div>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="text-destructive"
                        @click="revoking = t"
                        ><Trash2 aria-hidden="true" />Revocar</Button
                    >
                </li>
            </ul>
        </section>
    </div>

    <ConfirmDialog
        :open="revoking !== null"
        title="¿Revocar el token?"
        :description="`«${revoking?.name}» dejará de funcionar de inmediato. Los sistemas que lo usen perderán el acceso.`"
        confirm-label="Revocar"
        destructive
        @update:open="(v: boolean) => !v && (revoking = null)"
        @confirm="
            revoking &&
            router.delete(destroy.url(revoking.id), {
                onFinish: () => (revoking = null),
            })
        "
    />
</template>
