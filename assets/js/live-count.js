// Live result count (#10) for every form[data-live-count]: recounts on input, change and overlay open, and writes the
// button's label. Contract for filter overlays: .claude/rules/frontend.md. Self-contained: no imports, no globals.
(() => {
    document.querySelectorAll('form[data-live-count]').forEach((form) => {
        const button = [...form.elements].find((el) => el.hasAttribute('data-live-count-label'));
        const text = button?.querySelector('span');
        if (!text) {
            return;
        }
        const plain = text.textContent;
        const { countZero, countOne, countOther } = button.dataset;
        let timer;
        let controller;
        // The combobox inputs have no name, so most keystrokes leave the query unchanged.
        let lastQuery = null;

        const label = (count) => {
            if (count === 0) {
                return countZero;
            }
            // A regex, not Intl: a browser's de-CH data may use U+2019, swiss_number a straight apostrophe.
            return count === 1 ? countOne : countOther.replace('%n', String(count).replace(/\B(?=(\d{3})+$)/g, "'"));
        };

        const recount = async () => {
            controller?.abort();
            const query = new URLSearchParams(new FormData(form)).toString();
            if (query === lastQuery) {
                return;
            }
            controller = new AbortController();
            try {
                const response = await fetch(form.dataset.liveCountUrl + query, { signal: controller.signal });
                if (!response.ok) {
                    throw new Error(response.statusText);
                }
                text.textContent = label((await response.json()).count);
                lastQuery = query;
            } catch (error) {
                if (error.name !== 'AbortError') {
                    text.textContent = plain;
                    lastQuery = null;
                }
            }
        };

        const schedule = () => {
            clearTimeout(timer);
            timer = setTimeout(recount, 150);
        };

        // Also runs on submit: an empty value stays out of the URL as well as out of the count.
        form.addEventListener('formdata', ({ formData }) => {
            const entries = [...formData];
            entries.forEach(([key]) => formData.delete(key));
            entries.filter(([, value]) => value !== '').forEach(([key, value]) => formData.append(key, value));
        });
        form.addEventListener('input', schedule);
        form.addEventListener('change', schedule);
        form.closest('[popover]')?.addEventListener('toggle', (event) => {
            if (event.newState === 'open') {
                clearTimeout(timer);
                recount();
            }
        });
    });
})();
