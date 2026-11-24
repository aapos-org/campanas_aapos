<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, ShieldAlert, ShieldCheck, UserPlus, Users } from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { reactive } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFormat } from '@/composables/useFormat';
import { create, edit, index } from '@/routes/admin/users';
import type { Paginated } from '@/types';

type UserRow = {
    id: number;
    name: string;
    email: string;
    role: string | null;
    role_label: string | null;
    is_active: boolean;
    two_factor_enabled: boolean;
    last_login_at: string | null;
};

const props = defineProps<{
    users: Paginated<UserRow>;
    filters: { search: string; status: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Usuarios', href: index() }],
    },
});

const { dateTime } = useFormat();

const form = reactive({ ...props.filters });

watchDebounced(
    form,
    () => {
        router.get(
            index.url(),
            {
                search: form.search || undefined,
                status: form.status || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    },
    { debounce: 300 },
);
</script>

<template>
    <Head title="Usuarios" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                title="Usuarios"
                description="Personas con acceso al panel y su rol."
            />
            <Button as-child class="w-full sm:w-auto">
                <Link :href="create()">
                    <UserPlus aria-hidden="true" />
                    Invitar usuario
                </Link>
            </Button>
        </div>

        <form
            class="grid gap-3 sm:grid-cols-[1fr_12rem]"
            role="search"
            @submit.prevent
        >
            <div class="grid gap-1.5">
                <Label for="search">Buscar</Label>
                <Input
                    id="search"
                    v-model="form.search"
                    type="search"
                    placeholder="Nombre o correo"
                    autocomplete="off"
                />
            </div>
            <div class="grid gap-1.5">
                <Label for="status">Estado</Label>
                <select
                    id="status"
                    v-model="form.status"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none dark:bg-input/30"
                >
                    <option value="">Todos</option>
                    <option value="active">Activos</option>
                    <option value="inactive">Desactivados</option>
                </select>
            </div>
        </form>

        <EmptyState
            v-if="users.data.length === 0"
            :icon="Users"
            title="No hay usuarios que coincidan"
            description="Prueba con otra búsqueda o invita a un nuevo usuario."
        />

        <template v-else>
            <!-- Mobile: cards -->
            <ul class="grid gap-3 lg:hidden">
                <li
                    v-for="user in users.data"
                    :key="user.id"
                    class="glass-card p-4"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ user.name }}</p>
                            <p class="truncate text-sm text-muted-foreground">
                                {{ user.email }}
                            </p>
                        </div>
                        <Button
                            variant="ghost"
                            size="icon"
                            as-child
                            :aria-label="`Editar a ${user.name}`"
                        >
                            <Link :href="edit(user.id)"><Pencil /></Link>
                        </Button>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <Badge variant="secondary">{{ user.role_label }}</Badge>
                        <Badge v-if="!user.is_active" variant="destructive">
                            Desactivado
                        </Badge>
                        <Badge v-if="user.two_factor_enabled" variant="outline">
                            <ShieldCheck aria-hidden="true" /> 2FA
                        </Badge>
                    </div>
                    <p class="mt-2 text-xs text-muted-foreground">
                        Último acceso: {{ dateTime(user.last_login_at) }}
                    </p>
                </li>
            </ul>

            <!-- Desktop: table -->
            <div class="hidden overflow-x-auto glass-card lg:block">
                <table class="w-full text-sm">
                    <caption class="sr-only">
                        Lista de usuarios
                    </caption>
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">
                                Nombre
                            </th>
                            <th scope="col" class="px-4 py-3 font-medium">
                                Rol
                            </th>
                            <th scope="col" class="px-4 py-3 font-medium">
                                Estado
                            </th>
                            <th scope="col" class="px-4 py-3 font-medium">
                                2FA
                            </th>
                            <th scope="col" class="px-4 py-3 font-medium">
                                Último acceso
                            </th>
                            <th scope="col" class="px-4 py-3">
                                <span class="sr-only">Acciones</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="user in users.data"
                            :key="user.id"
                            class="border-t"
                        >
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ user.name }}</p>
                                <p class="text-muted-foreground">
                                    {{ user.email }}
                                </p>
                            </td>
                            <td class="px-4 py-3">{{ user.role_label }}</td>
                            <td class="px-4 py-3">
                                <Badge
                                    :variant="
                                        user.is_active
                                            ? 'secondary'
                                            : 'destructive'
                                    "
                                >
                                    {{
                                        user.is_active
                                            ? 'Activo'
                                            : 'Desactivado'
                                    }}
                                </Badge>
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    v-if="user.two_factor_enabled"
                                    class="inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-400"
                                >
                                    <ShieldCheck
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    Activa
                                </span>
                                <span
                                    v-else
                                    class="inline-flex items-center gap-1 text-muted-foreground"
                                >
                                    <ShieldAlert
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    No
                                </span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                {{ dateTime(user.last_login_at) }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Button variant="ghost" size="sm" as-child>
                                    <Link :href="edit(user.id)">
                                        <Pencil aria-hidden="true" />
                                        Editar
                                        <span class="sr-only">
                                            a {{ user.name }}</span
                                        >
                                    </Link>
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <Pagination :page="users" />
    </div>
</template>
