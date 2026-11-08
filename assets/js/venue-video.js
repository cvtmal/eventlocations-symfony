// Videos (G15): each player iframe waits inert in a <template> until its "Video laden (YouTube|Vimeo)" is clicked, so nothing reaches
// YouTube or Vimeo before the click. The button is hidden without JS; the provider link works everywhere.
// Self-contained: no imports, no globals. Loaded only on a detail page that shows videos.
(() => {
    document.querySelectorAll('[data-venue-video]').forEach((video) => {
        const loader = video.querySelector('[data-venue-video-loader]');
        const template = video.querySelector('template[data-venue-video-frame]');
        if (!loader || !template) {
            return;
        }
        loader.hidden = false;
        loader.querySelector('[data-venue-video-load]').addEventListener('click', () => {
            const frame = template.content.firstElementChild.cloneNode(true);
            video.querySelector('[data-venue-video-facade]').replaceWith(frame);
            frame.focus();
        }, {once: true});
    });
})();
