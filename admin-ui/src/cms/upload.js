import { RequestFailed, SessionGone, ValidationFailed } from '../api.js';

// One file as multipart/form-data, with progress. fetch() reports no upload bytes, so this is the one place an
// island uses XMLHttpRequest. The answers map as adminFetch's (api.js), so a 413, 500 or 504 never reports a lost
// session. XMLHttpRequest follows a redirect by itself, which shows only in responseURL, so unlike adminFetch a 2xx
// without JSON and without a redirect is no login page: it is a failed request, a PHP fatal under display_errors.
function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

function isJson(xhr) {
    return (xhr.getResponseHeader('Content-Type') ?? '').includes('json');
}

function wasRedirected(xhr, url) {
    return xhr.responseURL !== '' && xhr.responseURL !== new URL(url, document.baseURI).href;
}

function answerOf(xhr, url) {
    const { status } = xhr;

    if (wasRedirected(xhr, url) || (status >= 300 && status < 400) || status === 401) {
        throw new SessionGone();
    }
    if (status === 204) {
        return null;
    }
    if (status >= 200 && status < 300 && isJson(xhr)) {
        return JSON.parse(xhr.responseText);
    }
    if (status === 422 && isJson(xhr)) {
        throw new ValidationFailed(JSON.parse(xhr.responseText).errors);
    }

    throw new RequestFailed(status);
}

// onProgress gets {phase: 'sending', loaded, total}, then {phase: 'processing'} once the last byte has left and
// the server is rendering the variants.
export function uploadFile(url, file, { fields = {}, onProgress, signal } = {}) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        const body = new FormData();

        Object.entries(fields).forEach(([name, value]) => body.append(name, value));
        body.append('file', file);

        xhr.open('POST', url);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-Token', csrfToken());

        xhr.upload.onprogress = ({ loaded, total }) => onProgress?.({ phase: 'sending', loaded, total });
        xhr.upload.onload = () => onProgress?.({ phase: 'processing' });
        xhr.onload = () => {
            try {
                resolve(answerOf(xhr, url));
            } catch (error) {
                reject(error instanceof SyntaxError ? new RequestFailed(xhr.status) : error);
            }
        };
        xhr.onerror = () => reject(new RequestFailed(0)); // Status 0 is a network failure.
        xhr.onabort = () => reject(new DOMException('The upload was aborted.', 'AbortError'));

        signal?.addEventListener('abort', () => xhr.abort(), { once: true });
        xhr.send(body);
    });
}
