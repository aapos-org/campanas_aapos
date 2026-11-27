/**
 * Small JSON POST helper for endpoints that are not Inertia visits (e.g. live previews).
 * Sends Laravel's XSRF token from its cookie.
 */
export function postJson<T>(url: string, body: unknown): Promise<T> {
    return requestJson<T>('POST', url, body);
}

export async function requestJson<T>(
    method: 'POST' | 'PUT',
    url: string,
    body: unknown,
    signal?: AbortSignal,
): Promise<T> {
    const token = decodeURIComponent(
        document.cookie
            .split('; ')
            .find((c) => c.startsWith('XSRF-TOKEN='))
            ?.slice('XSRF-TOKEN='.length) ?? '',
    );

    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': token,
        },
        body: JSON.stringify(body),
        signal,
    });
    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        if (response.status === 429) {
            throw new HttpError(
                'Demasiadas solicitudes seguidas. Espera un momento.',
                429,
            );
        }

        const errors = (data as { errors?: Record<string, string[]> }).errors;
        const first = errors ? Object.values(errors)[0]?.[0] : undefined;

        throw new HttpError(
            first ??
                (data as { message?: string }).message ??
                'No se pudo completar la solicitud.',
            response.status,
        );
    }

    return data as T;
}

/** Error with the HTTP status, so callers can react to 429 and friends. */
export class HttpError extends Error {
    constructor(
        message: string,
        public readonly status: number,
    ) {
        super(message);
    }
}
