// Carousel (#1): adds arrows, a counter and arrow-key paging to every [data-carousel] scroll-snap track.
// Without JS the track still scrolls by touch and keyboard. Self-contained: no imports, no globals.
(() => {
    const reduced = matchMedia('(prefers-reduced-motion: reduce)');
    const wide = matchMedia('(min-width: 768px)');

    document.querySelectorAll('[data-carousel]').forEach((carousel) => {
        const track = carousel.querySelector('.carousel__track');
        const slides = track ? track.querySelectorAll('.carousel__slide') : [];
        const controls = carousel.querySelector('[data-carousel-controls]');
        if (!track || !controls || slides.length < 2) {
            return;
        }

        const prev = controls.querySelector('[data-carousel-prev]');
        const next = controls.querySelector('[data-carousel-next]');
        const counter = controls.querySelector('[data-carousel-counter]');
        const format = carousel.dataset.counterFormat || '{current} / {total}';
        const total = slides.length;
        let frame = 0;

        // Below md a slide is narrower than the track (the next one peeks in): a track's width would skip it.
        const page = (dir) => track.scrollBy({
            left: dir * (wide.matches ? track.clientWidth : slides[1].offsetLeft - slides[0].offsetLeft || track.clientWidth),
            behavior: reduced.matches ? 'auto' : 'smooth',
        });

        const update = () => {
            frame = 0;
            const {scrollLeft, clientWidth} = track;
            // A closed gallery has no layout yet.
            const atEnd = clientWidth > 0 && scrollLeft + clientWidth >= track.scrollWidth - 1;
            const step = slides[1].offsetLeft - slides[0].offsetLeft || 1;
            const index = atEnd ? total - 1 : Math.max(0, Math.round(scrollLeft / step));
            if (counter) {
                counter.textContent = format.replace('{current}', String(index + 1)).replace('{total}', String(total));
            }
            prev.disabled = scrollLeft < 1;
            next.disabled = atEnd;
        };

        prev.addEventListener('click', () => page(-1));
        next.addEventListener('click', () => page(1));
        track.addEventListener('scroll', () => {
            frame ||= requestAnimationFrame(update);
        }, {passive: true});
        track.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
                event.preventDefault();
                page(event.key === 'ArrowLeft' ? -1 : 1);
            }
        });

        controls.hidden = false;
        carousel.dataset.enhanced = '';
        update();
    });
})();
