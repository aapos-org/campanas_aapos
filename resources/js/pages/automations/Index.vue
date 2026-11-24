<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus, Workflow } from '@lucide/vue';
import { ref } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { useFormat } from '@/composables/useFormat';
import { edit, index, show, store } from '@/routes/automations';

defineProps<{
    automations: {
        id: number;
        name: string;
        audience: string | null;
        trigger: string;
        status: 'draft' | 'active' | 'paused';
        steps: number;
        runs: Record<string, number>;
    }[];
    audiences: { id: number; name: string }[];
    triggers: { value: string; label: string }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Automatizaciones', href: index() }] },
});

const { number } = useFormat();
const creating = ref(false);
const form = useForm({
    name: '',
    audience_id: null as number | null,
    trigger_type: 'subscribed',
});

const statusLabel = { draft: 'Borrador', active: 'Activa', paused: 'En pausa' };
</script>

<template>
    <Head title="Automatizaciones" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
        >
            <Heading
                title="Automatizaciones"
                description="Correos y acciones que se ejecutan solos cuando alguien se suscribe, recibe una etiqueta o cumple años."
            />
            <Button @click="creating = true"
                ><Plus aria-hidden="true" />Nueva automatización</Button
            >
        </div>

        <EmptyState
            v-if="!automations.length"
            :icon="Workflow"
            title="Aún no hay automatizaciones"
            description="Empieza con una serie de bienvenida para quienes se suscriben."
        />

        <ul v-else class="grid gap-3">
            <li
                v-for="a in automations"
                :key="a.id"
                class="flex flex-col gap-3 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="min-w-0 space-y-1">
                    <p class="flex items-center gap-2 font-medium">
                        <Link
                            :href="show(a.id)"
                            class="underline-offset-2 hover:underline"
                            >{{ a.name }}</Link
                        >
                        <Badge
                            :variant="
                                a.status === 'active' ? 'default' : 'outline'
                            "
                            >{{ statusLabel[a.status] }}</Badge
                        >
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ a.audience }} · {{ a.trigger }} · {{ a.steps }} pasos
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-4 text-sm">
                    <span
                        ><strong class="tabular-nums">{{
                            number(a.runs.active ?? 0)
                        }}</strong>
                        en curso</span
                    >
                    <span
                        ><strong class="tabular-nums">{{
                            number(a.runs.completed ?? 0)
                        }}</strong>
                        completados</span
                    >
                    <Button variant="secondary" size="sm" as-child
                        ><Link :href="edit(a.id)">Editar</Link></Button
                    >
                </div>
            </li>
        </ul>
    </div>

    <Dialog v-model:open="creating">
        <DialogContent>
            <form class="space-y-4" @submit.prevent="form.post(store.url())">
                <DialogHeader>
                    <DialogTitle>Nueva automatización</DialogTitle>
                    <DialogDescription
                        >Elige a quién se dirige y qué la inicia. Los pasos se
                        configuran después.</DialogDescription
                    >
                </DialogHeader>
                <div class="grid gap-1.5">
                    <Label for="a-name">Nombre</Label>
                    <Input id="a-name" v-model="form.name" required />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="a-audience">Audiencia</Label>
                    <NativeSelect
                        id="a-audience"
                        v-model="form.audience_id"
                        required
                    >
                        <option :value="null" disabled>Elige…</option>
                        <option
                            v-for="au in audiences"
                            :key="au.id"
                            :value="au.id"
                        >
                            {{ au.name }}
                        </option>
                    </NativeSelect>
                    <InputError :message="form.errors.audience_id" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="a-trigger">Se inicia cuando…</Label>
                    <NativeSelect id="a-trigger" v-model="form.trigger_type">
                        <option
                            v-for="t in triggers"
                            :key="t.value"
                            :value="t.value"
                        >
                            {{ t.label }}
                        </option>
                    </NativeSelect>
                </div>
                <DialogFooter>
                    <Button type="submit" :disabled="form.processing"
                        >Crear y configurar</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
