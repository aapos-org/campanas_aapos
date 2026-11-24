<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CalendarClock,
    Info,
    Megaphone,
    Users,
    Workflow,
} from '@lucide/vue';
import CampaignStatusBadge from '@/components/CampaignStatusBadge.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useFormat } from '@/composables/useFormat';
import { dashboard, usage as usagePage } from '@/routes';
import { index as usersIndex } from '@/routes/admin/users';
import { show as audienceShow } from '@/routes/audiences';
import { index as automationsIndex } from '@/routes/automations';
import { show as campaignShow } from '@/routes/campaigns';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Panel', href: dashboard() }],
    },
});

type Meter = { used: number; limit: number | null };

defineProps<{
    alerts: { level: 'warning' | 'info'; title: string; message: string }[];
    audiences: { id: number; name: string; subscribed: number }[];
    lastCampaign: {
        id: number;
        name: string;
        finished_at: string | null;
        sent: number;
        open_rate: number | null;
        click_rate: number | null;
    } | null;
    upcoming: {
        id: number;
        name: string;
        status: string;
        status_label: string;
        scheduled_at: string | null;
    }[];
    automations: number;
    usage: { contacts: Meter; monthly: Meter };
    stats: { users: number | null };
}>();

const page = usePage();
const { number, dateTime, date } = useFormat();
const can = (permission: string) =>
    (page.props.auth.permissions as string[] | undefined)?.includes(permission);
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
            <Card>
                <CardHeader>
                    <CardDescription>Contactos</CardDescription>
                    <CardTitle class="text-3xl tabular-nums">
                        {{ number(usage.contacts.used) }}
                    </CardTitle>
                </CardHeader>
                <CardContent class="text-sm text-muted-foreground">
                    <template v-if="usage.contacts.limit"
                        >de
                        {{ number(usage.contacts.limit) }} permitidos</template
                    >
                    <template v-else>Sin límite</template>
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>Envíos este mes</CardDescription>
                    <CardTitle class="text-3xl tabular-nums">
                        {{ number(usage.monthly.used) }}
                    </CardTitle>
                </CardHeader>
                <CardContent class="text-sm">
                    <Link
                        v-if="can('reports.view')"
                        :href="usagePage()"
                        class="text-primary underline-offset-4 hover:underline"
                        >{{
                            usage.monthly.limit
                                ? `de ${number(usage.monthly.limit)} · Ver uso`
                                : 'Ver uso'
                        }}</Link
                    >
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>Automatizaciones activas</CardDescription>
                    <CardTitle class="text-3xl tabular-nums">
                        {{ number(automations) }}
                    </CardTitle>
                </CardHeader>
                <CardContent class="text-sm">
                    <Link
                        v-if="can('campaigns.view')"
                        :href="automationsIndex()"
                        class="text-primary underline-offset-4 hover:underline"
                        ><Workflow
                            class="mr-1 inline size-4"
                            aria-hidden="true"
                        />Ver automatizaciones</Link
                    >
                </CardContent>
            </Card>
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

        <div class="grid gap-4 lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-base"
                        ><Megaphone
                            class="size-4 text-muted-foreground"
                            aria-hidden="true"
                        />Última campaña</CardTitle
                    >
                </CardHeader>
                <CardContent>
                    <div v-if="lastCampaign" class="space-y-2">
                        <Link
                            :href="campaignShow(lastCampaign.id)"
                            class="font-medium underline-offset-2 hover:underline"
                            >{{ lastCampaign.name }}</Link
                        >
                        <p class="text-sm text-muted-foreground">
                            {{ date(lastCampaign.finished_at) }} ·
                            {{ number(lastCampaign.sent) }} enviados
                        </p>
                        <dl class="grid grid-cols-2 gap-2 text-sm">
                            <div>
                                <dt class="text-muted-foreground">Apertura</dt>
                                <dd class="text-lg font-semibold tabular-nums">
                                    {{ lastCampaign.open_rate ?? '—' }} %
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Clics</dt>
                                <dd class="text-lg font-semibold tabular-nums">
                                    {{ lastCampaign.click_rate ?? '—' }} %
                                </dd>
                            </div>
                        </dl>
                    </div>
                    <p v-else class="text-sm text-muted-foreground">
                        Aún no se envía ninguna campaña desde la plataforma.
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-base"
                        ><CalendarClock
                            class="size-4 text-muted-foreground"
                            aria-hidden="true"
                        />Envíos en curso y programados</CardTitle
                    >
                </CardHeader>
                <CardContent>
                    <ul v-if="upcoming.length" class="divide-y text-sm">
                        <li
                            v-for="c in upcoming"
                            :key="c.id"
                            class="flex items-center justify-between gap-2 py-2"
                        >
                            <Link
                                :href="campaignShow(c.id)"
                                class="truncate underline-offset-2 hover:underline"
                                >{{ c.name }}</Link
                            >
                            <span class="flex shrink-0 items-center gap-2">
                                <span
                                    v-if="c.scheduled_at"
                                    class="text-muted-foreground"
                                    >{{ dateTime(c.scheduled_at) }}</span
                                >
                                <CampaignStatusBadge
                                    :status="c.status"
                                    :label="c.status_label"
                                />
                            </span>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">
                        No hay envíos programados.
                    </p>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-base"
                    ><Users
                        class="size-4 text-muted-foreground"
                        aria-hidden="true"
                    />Audiencias</CardTitle
                >
            </CardHeader>
            <CardContent>
                <ul
                    v-if="audiences.length"
                    class="grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-3"
                >
                    <li
                        v-for="a in audiences"
                        :key="a.id"
                        class="flex justify-between gap-2 rounded-lg bg-muted/40 px-3 py-2"
                    >
                        <Link
                            :href="audienceShow(a.id)"
                            class="truncate underline-offset-2 hover:underline"
                            >{{ a.name }}</Link
                        >
                        <span class="shrink-0 tabular-nums"
                            >{{ number(a.subscribed) }} suscritos</span
                        >
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">
                    Aún no hay audiencias.
                </p>
            </CardContent>
        </Card>
    </div>
</template>
