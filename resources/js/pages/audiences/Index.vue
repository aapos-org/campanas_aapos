<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus, Users } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useFormat } from '@/composables/useFormat';
import { usePermissions } from '@/composables/usePermissions';
import { create, index, show } from '@/routes/audiences';

type AudienceRow = {
    id: number;
    name: string;
    description: string | null;
    double_opt_in: boolean;
    counts: Record<string, number>;
    total: number;
};

defineProps<{ audiences: AudienceRow[] }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Audiencias', href: index() }] },
});

const { number } = useFormat();
const { can } = usePermissions();
</script>

<template>
    <Head title="Audiencias" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                title="Audiencias"
                description="Listas de contactos a las que envías campañas."
            />
            <Button
                v-if="can('contacts.manage')"
                as-child
                class="w-full sm:w-auto"
            >
                <Link :href="create()">
                    <Plus aria-hidden="true" />
                    Nueva audiencia
                </Link>
            </Button>
        </div>

        <EmptyState
            v-if="audiences.length === 0"
            :icon="Users"
            title="Aún no hay audiencias"
            description="Crea una audiencia y luego agrega o importa contactos."
        />

        <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <Link
                v-for="audience in audiences"
                :key="audience.id"
                :href="show(audience.id)"
                class="rounded-xl focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
            >
                <Card class="h-full transition-colors hover:bg-muted/40">
                    <CardHeader>
                        <CardTitle>{{ audience.name }}</CardTitle>
                        <CardDescription v-if="audience.description">
                            {{ audience.description }}
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <p class="text-3xl font-semibold tabular-nums">
                            {{ number(audience.counts.subscribed ?? 0) }}
                            <span
                                class="text-sm font-normal text-muted-foreground"
                                >suscritos</span
                            >
                        </p>
                        <div class="flex flex-wrap gap-2 text-xs">
                            <Badge variant="outline"
                                >{{ number(audience.total) }} en total</Badge
                            >
                            <Badge
                                v-if="audience.counts.unsubscribed"
                                variant="outline"
                                >{{
                                    number(audience.counts.unsubscribed)
                                }}
                                bajas</Badge
                            >
                            <Badge
                                v-if="audience.counts.cleaned"
                                variant="outline"
                                >{{
                                    number(audience.counts.cleaned)
                                }}
                                rebotados</Badge
                            >
                            <Badge
                                v-if="audience.double_opt_in"
                                variant="secondary"
                                >Doble opt-in</Badge
                            >
                        </div>
                    </CardContent>
                </Card>
            </Link>
        </div>
    </div>
</template>
