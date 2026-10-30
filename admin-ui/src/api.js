// The one way an island talks to /admin/api. Only a redirect, a 401 or a successful non-JSON answer mean the
// session is gone: a JSON route answers 302 during the 2FA step, and a login page can arrive as a 200. Any other
// status is an error of this request and leaves the session alone, so a 413, 500 or 504 never logs staff out.
export class SessionGone extends Error {
    constructor() {
        super('The session is gone.');
        this.name = 'SessionGone';
    }
}

export class ValidationFailed extends Error {
    constructor(errors) {
        super('Validation failed.');
        this.name = 'ValidationFailed';
        this.errors = errors;
    }
}

export class RequestFailed extends Error {
    constructor(status) {
        super(`The request failed with status ${status}.`);
        this.name = 'RequestFailed';
        this.status = status;
    }
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

function isJson(response) {
    return (response.headers.get('Content-Type') ?? '').includes('json');
}

function request(method, body, signal) {
    const headers = { Accept: 'application/json', 'X-CSRF-Token': csrfToken() };
    const init = { method, headers, redirect: 'manual' };

    if (signal) {
        init.signal = signal;
    }
    if (body instanceof FormData) {
        init.body = body;
    } else if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(body);
    }

    return init;
}

// Status 0 is a network failure.
async function send(url, init) {
    try {
        return await fetch(url, init);
    } catch (error) {
        if (error.name === 'AbortError') {
            throw error;
        }

        throw new RequestFailed(0);
    }
}

export async function adminFetch(url, { method = 'GET', body, signal } = {}) {
    const response = await send(url, request(method, body, signal));

    if (response.type === 'opaqueredirect' || (response.status >= 300 && response.status < 400) || response.status === 401) {
        throw new SessionGone();
    }
    if (response.status === 204) {
        return null;
    }
    if (response.ok) {
        if (!isJson(response)) {
            throw new SessionGone();
        }

        return response.json();
    }
    if (response.status === 422 && isJson(response)) {
        const { errors } = await response.json();
        throw new ValidationFailed(errors);
    }

    throw new RequestFailed(response.status);
}
