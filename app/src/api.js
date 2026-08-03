const config = window.rcmiTickets || {};

/**
 * Build a full REST URL from the apiBase + a path like "/tickets" with optional
 * query string. Works with both pretty permalinks (apiBase ends in /rcmi/v1)
 * and default permalinks (apiBase ends in ?rest_route=/rcmi/v1).
 */
function buildUrl(path, params) {
    // Strip a leading slash so apiBase + path joins cleanly.
    const cleanPath = path.replace(/^\//, '');
    let url = `${config.apiBase}/${cleanPath}`;

    // If the apiBase already contains a query string (default permalinks use
    // ?rest_route=...), append params with & instead of ?.
    const sep = url.includes('?') ? '&' : '?';

    if (params) {
        const qs = params.toString();
        if (qs) {
            url += sep + qs;
        }
    }
    return url;
}

export async function api(path, { method = 'GET', body, headers = {}, params } = {}) {
    const url = buildUrl(path, params);
    const res = await fetch(url, {
        method,
        headers: {
            'X-WP-Nonce': config.nonce,
            ...(body ? { 'Content-Type': 'application/json' } : {}),
            ...headers,
        },
        credentials: 'same-origin',
        ...(body ? { body: JSON.stringify(body) } : {}),
    });

    if (res.status === 401) {
        window.location.href = config.loginUrl;
        throw new Error('Unauthorized');
    }

    const data = await res.json().catch(() => null);

    if (!res.ok) {
        const message = data?.message || `Request failed (${res.status})`;
        const error = new Error(message);
        error.status = res.status;
        error.data = data;
        throw error;
    }

    return data;
}
