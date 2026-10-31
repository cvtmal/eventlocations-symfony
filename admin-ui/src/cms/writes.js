import { adminFetch } from '../api.js';

// An island's writes, one at a time, uploads included. Two quick reorders on two connections could reach PHP in
// either order, and a write beside an upload waits for the upload's session lock, then fails the set check with an
// id list that lacks the new photo. So nothing leaves before the last answer is in, and answers arrive in the order
// the server wrote them: the newest list is simply the last one. Every answer holds the full list (PF10); it is
// applied once no write is left, so an answer between two quick moves cannot undo the second one on screen. A failure
// applies null: the island re-renders what it has, which puts an optimistic reorder back.
export function createWrites(key, apply) {
    let last = Promise.resolve();
    let pending = 0;
    let newest = null;

    function flush() {
        if (pending === 0) {
            const list = newest;

            newest = null;
            apply(list);
        }
    }

    function run(work) {
        pending += 1;

        const answer = last.then(work).then((result) => {
            pending -= 1;
            newest = result[key];
            flush();

            return result;
        }, (error) => {
            pending -= 1;
            flush();

            throw error;
        });

        last = answer.catch(() => {});

        return answer;
    }

    // `options` may be a function, called when the request leaves with the list held for later, or null when the
    // island is up to date. Ids and a row token have to be read then, not when the key was pressed; what staff entered
    // is read at the press, because the form may hold another row by the time the request leaves.
    function send(url, options) {
        return run(() => adminFetch(url, typeof options === 'function' ? options(newest) : options));
    }

    return { send, run };
}

// A row's token as of the freshest list: every write that moves a row bumps it (PF2).
export function freshToken(list, row) {
    return list.find(({ id }) => id === row.id)?.token ?? row.token;
}
