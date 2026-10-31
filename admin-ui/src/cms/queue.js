import { RequestFailed, ValidationFailed } from '../api.js';
import { needsNewPage } from './text.js';

// The upload queue (S12: one file per request), free of Vue. States: waiting, sending, processing, done, failed
// (retry offered), refused (the file itself is the problem). A lost session or a dead CSRF token halts the queue for
// good: this page's token cannot work again, so what still waits has to be added again after a reload. A reload then
// costs nothing the banner has not said, so a halted queue no longer counts as running.
const HIDE_DONE_AFTER_MS = 4000;

const OPEN = ['waiting', 'sending', 'processing'];

export function createQueue({ upload, maxBytes, onChange, hideDoneAfterMs = HIDE_DONE_AFTER_MS }) {
    let nextId = 1;
    let items = [];
    let active = false;
    let halted = false;

    function publish() {
        onChange({ items: items.map((item) => ({ ...item })), halted });
    }

    function update(item, changes) {
        Object.assign(item, changes);
        publish();
    }

    function dismiss(id) {
        items = items.filter((item) => item.id !== id);
        publish();
    }

    function settle(item, error) {
        if (needsNewPage(error)) {
            halted = true;
            update(item, { state: 'waiting', loaded: 0 });
        } else if (error instanceof ValidationFailed) {
            update(item, { state: 'refused', message: Object.values(error.errors).flat()[0] ?? null });
        } else if (error instanceof RequestFailed && error.status === 413) {
            update(item, { state: 'refused', reason: 'tooLarge' });
        } else {
            update(item, { state: 'failed' });
        }
    }

    async function run(item) {
        active = true;
        update(item, { state: 'sending', loaded: 0, total: item.file.size });

        try {
            const answer = await upload(item.file, ({ phase, loaded, total }) => {
                update(item, phase === 'processing' ? { state: phase } : { state: phase, loaded, total });
            });

            update(item, { state: 'done', duplicate: answer?.duplicate === true });
            setTimeout(() => dismiss(item.id), hideDoneAfterMs);
        } catch (error) {
            settle(item, error);
        }

        active = false;
        pump();
    }

    function pump() {
        const next = items.find(({ state }) => state === 'waiting');

        if (!active && !halted && next) {
            run(next);
        }
    }

    function add(files) {
        files.forEach((file) => {
            const refused = file.size > maxBytes;

            items.push({
                id: nextId++,
                file,
                name: file.name,
                state: refused ? 'refused' : 'waiting',
                reason: refused ? 'tooLarge' : null,
                message: null,
                duplicate: false,
                loaded: 0,
                total: file.size,
            });
        });
        publish();
        pump();
    }

    function retry(id) {
        const item = items.find((candidate) => candidate.id === id);

        if (item?.state === 'failed') {
            update(item, { state: 'waiting', loaded: 0 });
            pump();
        }
    }

    const running = () => !halted && items.some(({ state }) => OPEN.includes(state));

    return { add, retry, dismiss, running };
}
