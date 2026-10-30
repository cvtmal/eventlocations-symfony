// Teilen (#8, DP-4): the share button opens a popover menu without JS (copy link, WhatsApp, E-Mail).
// With the Web Share API the click shares natively instead; "Link kopieren" needs JS and is shown here.
// Self-contained: no imports, no globals.
(() => {
    document.querySelectorAll('[data-share-url]').forEach((button) => {
        const url = button.dataset.shareUrl;
        const title = button.dataset.shareTitle || document.title;
        const menu = document.getElementById(button.getAttribute('popovertarget'));

        if (typeof navigator.share === 'function') {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                navigator.share({title, url}).catch(() => {});
            });
        }

        const copy = menu ? menu.querySelector('[data-share-copy]') : null;
        const status = menu ? menu.querySelector('[data-share-status]') : null;
        if (!copy || !navigator.clipboard) {
            return;
        }
        copy.hidden = false;
        copy.addEventListener('click', () => {
            navigator.clipboard.writeText(url).then(() => {
                if (status) {
                    status.textContent = copy.dataset.copiedLabel || '';
                }
            }, () => {});
        });
    });
})();
