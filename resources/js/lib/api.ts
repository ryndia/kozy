const getCsrfToken = () => {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
};

/**
 * Pull a human readable message out of whatever a failed request threw:
 * a Laravel validation payload, a JSON error body, or a plain string.
 */
export function extractErrorMessage(
    error: unknown,
    fallback = 'Something went wrong. Please try again.',
) {
    if (typeof error === 'string') {
        return error;
    }

    if (error && typeof error === 'object') {
        const payload = error as {
            message?: string;
            errors?: Record<string, string[] | string>;
        };

        const firstError = Object.values(payload.errors ?? {})[0];

        if (Array.isArray(firstError)) {
            return firstError[0];
        }

        if (typeof firstError === 'string') {
            return firstError;
        }

        if (payload.message) {
            return payload.message;
        }
    }

    return fallback;
}

async function parseResponse(res: Response) {
    const contentType = res.headers.get('content-type') ?? '';
    const payload = contentType.includes('application/json')
        ? await res.json()
        : await res.text();

    if (!res.ok) {
        throw payload;
    }

    return payload;
}

export async function apiGet(
    path: string,
    params: Record<string, string | number | boolean> = {},
) {
    const url = new URL(path, window.location.origin);
    Object.entries(params).forEach(([k, v]) => {
        url.searchParams.append(k, String(v));
    });
    const res = await fetch(url.toString(), {
        credentials: 'include',
        headers: {
            Accept: 'application/json',
        },
    });

    return parseResponse(res);
}

export async function apiPost(path: string, body = {}) {
    const url = new URL(path, window.location.origin);
    const res = await fetch(url.toString(), {
        method: 'POST',
        credentials: 'include',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': getCsrfToken(),
            'X-XSRF-TOKEN': getCsrfToken(),
        },
        body: JSON.stringify(body),
    });

    return parseResponse(res);
}

export async function apiPatch(path: string, body = {}) {
    const url = new URL(path, window.location.origin);
    const res = await fetch(url.toString(), {
        method: 'PATCH',
        credentials: 'include',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': getCsrfToken(),
            'X-XSRF-TOKEN': getCsrfToken(),
        },
        body: JSON.stringify(body),
    });

    return parseResponse(res);
}
