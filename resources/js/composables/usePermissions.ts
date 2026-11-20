import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Permission checks for showing/hiding UI. The server always re-checks authorization.
 */
export function usePermissions() {
    const page = usePage();
    const permissions = computed(
        () => new Set(page.props.auth.permissions ?? []),
    );

    const can = (permission?: string): boolean =>
        permission === undefined || permissions.value.has(permission);

    return { can };
}
