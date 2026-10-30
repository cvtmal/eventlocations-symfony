// Guests overlay (#11): a bubble fills the input instead of submitting, a second press empties it. aria-pressed
// follows the input on its form's input, change and reset, so clear and close leave no stale bubble.
(() => {
    const form = document.getElementById('filter-guests-form');
    const picks = document.querySelector('form[data-guests-bubbles]');
    if (!form || !picks) {
        return;
    }
    const input = form.elements.guests;
    const bubbles = [...picks.querySelectorAll('button[value]')];

    const sync = () => {
        bubbles.forEach((bubble) => bubble.setAttribute('aria-pressed', String(input.value === bubble.value)));
    };
    form.addEventListener('input', sync);
    form.addEventListener('change', sync);
    // reset fires before the rendered values are back.
    form.addEventListener('reset', () => setTimeout(sync));

    picks.addEventListener('click', (event) => {
        const bubble = event.target.closest('button[value]');
        if (!bubble) {
            return;
        }
        event.preventDefault();
        input.value = input.value === bubble.value ? '' : bubble.value;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    });

    // A phone keyboard would cover the bubbles, so only a fine pointer gets the focus.
    form.closest('[popover]')?.addEventListener('toggle', (event) => {
        if (event.newState === 'open' && !matchMedia('(pointer: coarse)').matches) {
            input.focus();
        }
    });
})();
