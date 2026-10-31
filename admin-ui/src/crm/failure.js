import { RequestFailed, SessionGone, ValidationFailed } from '../api.js';

// What a failed inline save shows (D11). The twin of #25's failureOf in admin-ui/src/cms/text.js, repeated
// because Crm may not use Cms (P5). adminFetch drops the body of a 403, a 404 and a 409, so they come from labels.
// `reload` asks for a reload link: the page's values no longer hold, or the session is gone. `labels.forbidden` is for
// an island whose 403s all mean that: a token from an older session, or an entry that is no longer the viewer's.
export function failureOf(error, labels) {
    if (error instanceof SessionGone) {
        return { message: labels.sessionGone, reload: true };
    }
    if (error instanceof ValidationFailed) {
        const messages = error.errors?.value ?? Object.values(error.errors ?? {}).flat();

        return { message: messages.length > 0 ? messages.join(' ') : labels.error, reload: false };
    }
    if (error instanceof RequestFailed && error.status === 409) {
        return { message: labels.stale, reload: true };
    }
    if (error instanceof RequestFailed && error.status === 404) {
        return { message: labels.notFound, reload: true };
    }
    if (error instanceof RequestFailed && error.status === 403 && labels.forbidden) {
        return { message: labels.forbidden, reload: true };
    }

    return { message: labels.error, reload: false };
}

// The same for a form with several inputs: `fields` holds a 422's messages for the inputs listed, each shown
// under its input; a message on any other field lands in `message`, so none is ever dropped.
export function formFailure(error, labels, inputs) {
    if (!(error instanceof ValidationFailed)) {
        return { fields: {}, ...failureOf(error, labels) };
    }

    const errors = Object.entries(error.errors ?? {});
    const fields = Object.fromEntries(errors.filter(([field]) => inputs.includes(field)).map(([field, messages]) => [field, messages.join(' ')]));
    const others = errors.filter(([field]) => !inputs.includes(field)).flatMap(([, messages]) => messages);

    return { fields, message: others.length > 0 ? others.join(' ') : (errors.length === 0 ? labels.error : null), reload: false };
}
