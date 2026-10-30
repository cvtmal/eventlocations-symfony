// Standort map (#15, DP-10): the Maps Embed iframe waits inert in a <template> until "Karte laden" is clicked,
// so nothing reaches Google before the click. The button is hidden without JS; the Maps link works everywhere.
// Self-contained: no imports, no globals.
(() => {
    document.querySelectorAll('[data-venue-map]').forEach((map) => {
        const loader = map.querySelector('[data-venue-map-loader]');
        const template = map.querySelector('template[data-venue-map-frame]');
        if (!loader || !template) {
            return;
        }
        loader.hidden = false;
        loader.querySelector('[data-venue-map-load]').addEventListener('click', () => {
            const frame = template.content.firstElementChild.cloneNode(true);
            map.querySelector('[data-venue-map-facade]').replaceWith(frame);
            frame.focus();
        }, {once: true});
    });
})();
