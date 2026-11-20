import { usePage } from '@inertiajs/vue3';

/**
 * Date/number formatting in the organization's timezone and Spanish (Guatemala) locale.
 * The server always sends ISO-8601 dates in UTC.
 */
export function useFormat() {
    const page = usePage();
    const timeZone = page.props.app?.timezone ?? 'America/Guatemala';
    const locale = page.props.app?.locale ?? 'es-GT';

    const dateTime = (iso: string | null | undefined): string => {
        if (!iso) {
            return '—';
        }

        return new Intl.DateTimeFormat(locale, {
            timeZone,
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        }).format(new Date(iso));
    };

    const date = (iso: string | null | undefined): string => {
        if (!iso) {
            return '—';
        }

        return new Intl.DateTimeFormat(locale, {
            timeZone,
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
        }).format(new Date(iso));
    };

    const number = (value: number | null | undefined): string =>
        value === null || value === undefined
            ? '—'
            : new Intl.NumberFormat(locale).format(value);

    return { dateTime, date, number, timeZone };
}
