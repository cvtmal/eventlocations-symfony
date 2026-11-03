// Stats beacon (#47, C1): views, card impressions, contact clicks, Merkliste adds to POST /_stats.
// No cookie, no storage, nothing under Global Privacy Control. Self-contained.
(() => {
    const nav = navigator;
    const tag = document.querySelector('script[data-stats-page]');
    if (nav.globalPrivacyControl || !nav.sendBeacon || !tag) {
        return;
    }
    const data = tag.dataset;
    const seen = new Set();
    const queue = ['view'];
    const cards = [];

    // At most 50 events per request and 100 numbers per impression (C1).
    const flush = () => {
        while (cards.length) {
            queue.push('imp:' + cards.splice(0, 100).join('.'));
        }
        while (queue.length) {
            const body = new URLSearchParams({p: data.statsPage, v: data.statsVenue, u: location.pathname, r: document.referrer});
            nav.webdriver && body.set('w', '1');
            queue.splice(0, 50).forEach((event) => body.append('e[]', event));
            nav.sendBeacon(data.statsUrl, body);
        }
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(({isIntersecting, target}) => {
            const number = target.dataset.statsVenue;
            if (isIntersecting && !seen.has(number)) {
                seen.add(number);
                cards.push(number);
                observer.unobserve(target);
            }
        });
    }, {threshold: 0.5});
    document.querySelectorAll('[data-stats-venue]:not(script)').forEach((card) => observer.observe(card));

    // Capture: runs before phone-reveal.js swaps the link for an uncounted tel: one.
    document.addEventListener('click', (event) => {
        const link = event.target.closest?.('[data-stats-click]');
        if (link) {
            queue.push('click:' + link.dataset.statsClick);
            flush();
        }
    }, true);
    document.addEventListener('merkliste:add', (event) => queue.push('add:' + event.detail));
    document.addEventListener('visibilitychange', () => document.visibilityState === 'hidden' && flush());
    addEventListener('pagehide', flush);

    const start = () => setTimeout(flush);
    document.readyState === 'complete' ? start() : addEventListener('load', start);
})();
