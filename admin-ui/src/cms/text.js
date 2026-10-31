import { RequestFailed, SessionGone } from '../api.js';

// Labels arrive from Twig with %name tokens (as UiSortableList's movedLabel). A replacer function, because a venue
// name may hold a `$` sequence that String.replace would interpret.
export function fill(label, tokens) {
    return Object.entries(tokens).reduce((text, [name, value]) => text.replace(`%${name}`, () => String(value)), label);
}

// What the server's Length rule counts: trimmed text in code points, not UTF-16 units. The islands send it trimmed.
export function charCount(value) {
    return [...(value ?? '').trim()].length;
}

// Only a reload helps: a lost session, or a 403, because a new login replaces the CSRF token in this page's <meta>.
export function needsNewPage(error) {
    return error instanceof SessionGone || (error instanceof RequestFailed && error.status === 403);
}

// A request failure that is not a field error, as {message, reload}. A 409 or 404 body holds nothing the labels
// do not, and adminFetch keeps only the status.
export function failureOf(error, { sessionGone, stale, notFound, failed }) {
    if (needsNewPage(error)) {
        return { message: sessionGone, reload: true };
    }
    if (error instanceof RequestFailed && error.status === 409) {
        return { message: stale, reload: true };
    }
    if (error instanceof RequestFailed && error.status === 404) {
        return { message: notFound, reload: true };
    }

    return { message: failed, reload: false };
}
