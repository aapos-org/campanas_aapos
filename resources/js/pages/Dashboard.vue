<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { AlertTriangle, Info, Mail, Megaphone, Send, Users } from '@lucide/vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useFormat } from '@/composables/useFormat';
import { dashboard } from '@/routes';
import { index as usersIndex } from '@/routes/admin/users';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Panel', href: dashboard() }],
    },
});

type DashboardAlert = {
    level: 'warning' | 'info';
    title: string;
    message: string;
};

defineProps<{
    alerts: DashboardAlert[];
    stats: { users: number | null };
}>();

const page = usePage();
const { number } = useFormat();

// Modules that will populate this panel in upcoming phases.
const upcoming = [
    {
        icon: Users,
        title: 'Audiencias y contactos',
        text: 'Importación, etiquetas y supresiones.',
    },
    {
        icon: Send,
        title: 'Conexiones de envío',
        text: 'Gmail para pruebas y ElasticEmail para envíos reales.',
    },
    {
        icon: Megaphone,
        title: 'Campañas',
        text: 'Editor, envío por lotes y reportes.',
    },
    {
        icon: Mail,
        title: 'Migración desde Mailchimp',
        text: 'Contactos, historial y verificación.',
    },
];
</script>

<template>
    <Head title="Panel" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">
                Hola, {{ page.props.auth.user.name.split(' ')[0] }}
            </h1>
            <p class="text-sm text-muted-foreground">
                {{ page.props.organization?.name }} ·
                {{ page.props.auth.user.role_label }}
            </p>
        </div>

        <section v-if="alerts.length" aria-label="Alertas" class="grid gap-3">
            <Alert
                v-for="alert in alerts"
                :key="alert.title"
                :class="
                    alert.level === 'warning'
                        ? 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100'
                        : ''
                "
            >
                <AlertTriangle v-if="alert.level === 'warning'" />
                <Info v-else />
                <AlertTitle>{{ alert.title }}</AlertTitle>
                <AlertDescription>{{ alert.message }}</AlertDescription>
            </Alert>
        </section>

        <section
            aria-label="Resumen"
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4"
        >
            <Card v-if="stats.users !== null">
                <CardHeader>
                    <CardDescription>Usuarios activos</CardDescription>
                    <CardTitle class="text-3xl tabular-nums">
                        {{ number(stats.users) }}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <Link
                        :href="usersIndex()"
                        class="text-sm text-primary underline-offset-4 hover:underline"
                    >
                        Administrar usuarios
                    </Link>
                </CardContent>
            </Card>
        </section>

        <section aria-labelledby="upcoming-title" class="space-y-3">
            <h2 id="upcoming-title" class="text-lg font-medium">
                Próximamente
            </h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Card
                    v-for="item in upcoming"
                    :key="item.title"
                    class="border-dashed"
                >
                    <CardHeader>
                        <component
                            :is="item.icon"
                            class="size-5 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <CardTitle class="text-base">{{
                            item.title
                        }}</CardTitle>
                        <CardDescription>{{ item.text }}</CardDescription>
                    </CardHeader>
                </Card>
            </div>
        </section>
    </div>
</template>
