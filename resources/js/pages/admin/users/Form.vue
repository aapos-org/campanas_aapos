<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { Mail } from '@lucide/vue';
import { computed, ref } from 'vue';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index, invitation } from '@/routes/admin/users';
import type { RoleOption, RoleValue } from '@/types';

type EditableUser = {
    id: number;
    name: string;
    email: string;
    role: RoleValue | null;
    is_active: boolean;
};

const props = defineProps<{
    user: EditableUser | null;
    roles: RoleOption[];
}>();

const isEdit = computed(() => props.user !== null);
const title = computed(() =>
    isEdit.value ? 'Editar usuario' : 'Invitar usuario',
);

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Usuarios', href: index() }],
    },
});

const selectedRole = ref<RoleValue>(props.user?.role ?? 'editor');
const isActive = ref<boolean>(props.user?.is_active ?? true);
const resending = ref(false);

const formDefinition = computed(() =>
    props.user
        ? UserController.update.form(props.user.id)
        : UserController.store.form(),
);

const resendInvitation = () => {
    if (!props.user) {
        return;
    }

    router.post(
        invitation.url(props.user.id),
        {},
        {
            preserveScroll: true,
            onStart: () => (resending.value = true),
            onFinish: () => (resending.value = false),
        },
    );
};
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            :title="title"
            :description="
                isEdit
                    ? 'Cambia los datos, el rol o el estado de la cuenta.'
                    : 'La persona recibirá un correo para definir su contraseña.'
            "
        />

        <Form
            v-bind="formDefinition"
            :transform="
                (data) => ({
                    ...data,
                    role: selectedRole,
                    is_active: isActive,
                })
            "
            class="max-w-xl space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Nombre completo</Label>
                <Input
                    id="name"
                    name="name"
                    :default-value="user?.name"
                    required
                    autocomplete="off"
                    v-focus
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Correo electrónico</Label>
                <Input
                    id="email"
                    name="email"
                    type="email"
                    :default-value="user?.email"
                    required
                    autocomplete="off"
                />
                <InputError :message="errors.email" />
            </div>

            <fieldset class="grid gap-3">
                <legend class="mb-1 text-sm font-medium">Rol</legend>
                <label
                    v-for="role in roles"
                    :key="role.value"
                    :class="[
                        'flex cursor-pointer gap-3 rounded-lg border p-3 transition-colors',
                        selectedRole === role.value
                            ? 'border-primary bg-primary/5'
                            : 'hover:bg-muted/50',
                    ]"
                >
                    <input
                        v-model="selectedRole"
                        type="radio"
                        name="role_choice"
                        :value="role.value"
                        class="mt-1 size-4 accent-current"
                    />
                    <span>
                        <span class="block text-sm font-medium">{{
                            role.label
                        }}</span>
                        <span class="block text-sm text-muted-foreground">{{
                            role.description
                        }}</span>
                    </span>
                </label>
                <InputError :message="errors.role" />
            </fieldset>

            <div v-if="isEdit" class="flex items-center gap-3">
                <Checkbox
                    id="is_active"
                    :model-value="isActive"
                    @update:model-value="(v) => (isActive = v === true)"
                />
                <Label for="is_active">Cuenta activa</Label>
                <InputError :message="errors.is_active" />
            </div>

            <div
                class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center"
            >
                <Button variant="outline" as-child>
                    <Link :href="index()">Cancelar</Link>
                </Button>
                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    {{ isEdit ? 'Guardar cambios' : 'Enviar invitación' }}
                </Button>
            </div>
        </Form>

        <section
            v-if="isEdit && user?.is_active"
            class="max-w-xl space-y-3 glass-card p-4"
            aria-labelledby="invite-title"
        >
            <h2 id="invite-title" class="font-medium">Invitación</h2>
            <p class="text-sm text-muted-foreground">
                Si la persona no recibió el correo o el enlace caducó, puedes
                enviarle uno nuevo.
            </p>
            <Button
                variant="secondary"
                :disabled="resending"
                @click="resendInvitation"
            >
                <Spinner v-if="resending" />
                <Mail v-else aria-hidden="true" />
                Reenviar invitación
            </Button>
        </section>
    </div>
</template>
