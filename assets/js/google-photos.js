// Google Places photos on the detail page of a venue without its own (D12, places-photos). The server rendered an
// empty box with the URL of our fragment; this fetches it after the page's load event (our own server, never Google)
// and swaps F6's placeholder for the strip. A 204 (no key, daily cap used up, nothing from Google), an error or a
// strip whose photos all fail to load leave the placeholder. Self-contained, no imports, no globals.
(() => {
    const box = document.querySelector('[data-google-photos]');
    if (!box || !window.fetch) {
        return;
    }
    const holder = box.parentElement;

    const restore = () => {
        box.replaceChildren();
        holder.classList.remove('venue-placeholder--google');
    };

    const load = async () => {
        const response = await fetch(box.dataset.googlePhotos, { headers: { Accept: 'text/html' }, credentials: 'omit' });
        if (200 !== response.status) {
            return;
        }
        box.innerHTML = await response.text();
        const items = [...box.querySelectorAll('.google-photos__item')];
        if (0 === items.length) {
            restore();

            return;
        }
        let left = items.length;
        items.forEach((item) => {
            item.querySelector('img')?.addEventListener('error', () => {
                item.remove();
                left -= 1;
                if (0 === left) {
                    restore();
                }
            });
        });
        holder.classList.add('venue-placeholder--google');
    };

    const start = () => load().catch(restore);
    if ('complete' === document.readyState) {
        start();
    } else {
        window.addEventListener('load', start, { once: true });
    }
})();
