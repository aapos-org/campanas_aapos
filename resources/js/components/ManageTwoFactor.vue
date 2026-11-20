<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import { computed, onUnmounted, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import TwoFactorRecoveryCodes from '@/components/TwoFactorRecoveryCodes.vue';
import TwoFactorSetupModal from '@/components/TwoFactorSetupModal.vue';
import { Button } from '@/components/ui/button';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import { disable, enable } from '@/routes/two-factor';

export type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
};

withDefaults(defineProps<Props>(), {
    canManageTwoFactor: false,
    requiresConfirmation: false,
    twoFactorEnabled: false,
});

const { hasSetupData, clearTwoFactorAuthData } = useTwoFactorAuth();
const showSetupModal = ref<boolean>(false);
const page = usePage();
const isAdmin = computed(() => page.props.auth.user?.role === 'admin');

onUnmounted(() => clearTwoFactorAuthData());
</script>

<template>
    <div v-if="canManageTwoFactor" class="space-y-6">
        <Heading
            variant="small"
            title="Verificación en dos pasos (2FA)"
            description="Protege tu cuenta con un código temporal además de tu contraseña"
        />

        <div
            v-if="!twoFactorEnabled"
            class="flex flex-col items-start justify-start space-y-4"
        >
            <div
                v-if="page.props.auth.user?.must_enable_two_factor"
                role="alert"
                class="w-full rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100"
            >
                Tu rol de administrador requiere la verificación en dos pasos.
                Actívala para poder usar el panel.
            </div>
            <p class="text-sm text-muted-foreground">
                Al activarla, cada vez que inicies sesión se te pedirá un código
                de 6 dígitos generado por una aplicación de autenticación en tu
                teléfono (Google Authenticator, Microsoft Authenticator,
                1Password…).
            </p>

            <div>
                <Button v-if="hasSetupData" @click="showSetupModal = true">
                    <ShieldCheck />Continuar configuración
                </Button>
                <Form
                    v-else
                    v-bind="enable.form()"
                    @success="showSetupModal = true"
                    #default="{ processing }"
                >
                    <Button type="submit" :disabled="processing">
                        Activar 2FA
                    </Button>
                </Form>
            </div>
        </div>

        <div v-else class="flex flex-col items-start justify-start space-y-4">
            <p class="text-sm text-muted-foreground">
                La verificación en dos pasos está activa. Al iniciar sesión se
                te pedirá el código de tu aplicación de autenticación.
            </p>

            <p v-if="isAdmin" class="text-sm text-muted-foreground">
                Como administrador, la verificación en dos pasos es obligatoria
                y no se puede desactivar.
            </p>

            <div v-else class="relative inline">
                <Form v-bind="disable.form()" #default="{ processing }">
                    <Button
                        variant="destructive"
                        type="submit"
                        :disabled="processing"
                    >
                        Desactivar 2FA
                    </Button>
                </Form>
            </div>

            <TwoFactorRecoveryCodes />
        </div>

        <TwoFactorSetupModal
            v-model:isOpen="showSetupModal"
            :requiresConfirmation="requiresConfirmation"
            :twoFactorEnabled="twoFactorEnabled"
        />
    </div>
</template>
