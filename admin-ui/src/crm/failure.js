import { RequestFailed, SessionGone, ValidationFailed } from '../api.js';

// What a failed inline save shows (D11). The twin of #25's failureOf in admin-ui/src/cms/text.js, repeated
// because Crm may not use Cms (P5). adminFetch drops the body of a 409 and a 404, so both come from labels.
// `reload` asks for a reload link: the page's values no longer hold, or the session is gone.
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

    return { message: labels.error, reload: false };
}
