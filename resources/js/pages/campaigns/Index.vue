<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Megaphone, Plus } from '@lucide/vue';
import { ref } from 'vue';
import CampaignController from '@/actions/App/Http/Controllers/Campaigns/CampaignController';
import CampaignStatusBadge from '@/components/CampaignStatusBadge.vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useFormat } from '@/composables/useFormat';
import { usePermissions } from '@/composables/usePermissions';
import { edit, index, show } from '@/routes/campaigns';
import type { CampaignSummary, Paginated } from '@/types';

defineProps<{
    campaigns: Paginated<CampaignSummary>;
    historic: boolean;
    historicCount: number;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Campañas', href: index() }] },
});

const { dateTime, number } = useFormat();
const { can } = usePermissions();
const creating = ref(false);

const link = (c: CampaignSummary) =>
    ['draft', 'scheduled'].includes(c.status) &&
    !c.is_historic &&
    can('campaigns.manage')
        ? edit(c.id)
        : show(c.id);
</script>

<template>
    <Head title="Campañas" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                title="Campañas"
                description="Crea, envía y sigue tus campañas de correo."
            />
            <Button
                v-if="can('campaigns.manage')"
                class="w-full sm:w-auto"
                @click="creating = true"
                ><Plus aria-hidden="true" />Nueva campaña</Button
            >
        </div>

        <nav class="flex gap-2" aria-label="Tipo de campañas">
            <Button
                as-child
                size="sm"
                :variant="historic ? 'ghost' : 'secondary'"
                ><Link :href="index()">Campañas</Link></Button
            >
            <Button
                v-if="historicCount > 0"
                as-child
                size="sm"
                :variant="historic ? 'secondary' : 'ghost'"
            >
                <Link :href="index({ query: { historico: 1 } })"
                    >Historial de Mailchimp ({{ number(historicCount) }})</Link
                >
            </Button>
        </nav>

        <EmptyState
            v-if="campaigns.data.length === 0"
            :icon="Megaphone"
            title="Aún no hay campañas"
            description="Crea una, elige la audiencia, diseña el contenido y envíala."
        />

        <ul v-else class="grid gap-2">
            <li v-for="c in campaigns.data" :key="c.id">
                <Link
                    :href="link(c)"
                    class="flex flex-col gap-2 rounded-xl border p-4 hover:bg-muted/40 sm:flex-row sm:items-center sm:gap-4"
                >
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium">{{ c.name }}</p>
                        <p class="truncate text-sm text-muted-foreground">
                            {{ c.subject ?? 'Sin asunto' }} ·
                            {{ c.audience ?? 'Sin audiencia' }}
                        </p>
                    </div>
                    <p class="text-sm text-muted-foreground tabular-nums">
                        <template v-if="c.status === 'scheduled'"
                            >Programada:
                            {{ dateTime(c.scheduled_at) }}</template
                        >
                        <template v-else-if="c.recipients_count"
                            >{{ number(c.sent_count) }} /
                            {{ number(c.recipients_count) }} enviados</template
                        >
                        <template v-else>{{
                            dateTime(c.finished_at ?? c.updated_at)
                        }}</template>
                    </p>
                    <CampaignStatusBadge
                        :status="c.status"
                        :label="c.status_label"
                    />
                </Link>
            </li>
        </ul>

        <Pagination :page="campaigns" />
    </div>

    <Dialog v-model:open="creating">
        <DialogContent class="sm:max-w-md">
            <DialogHeader
                ><DialogTitle>Nueva campaña</DialogTitle></DialogHeader
            >
            <Form
                v-bind="CampaignController.store.form()"
                class="space-y-4"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-1.5">
                    <Label for="c-name">Nombre interno</Label>
                    <Input
                        id="c-name"
                        name="name"
                        placeholder="Ej.: Boletín diciembre"
                        required
                        v-focus
                    />
                    <InputError :message="errors.name" />
                </div>
                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        @click="creating = false"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="processing"
                        ><Spinner v-if="processing" />Crear</Button
                    >
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
