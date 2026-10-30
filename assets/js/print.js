// Drucken (#8, DP-4): the print button is hidden without JS; this shows it and prints the page.
// Self-contained: no imports, no globals.
(() => {
    document.querySelectorAll('[data-print]').forEach((button) => {
        button.hidden = false;
        button.addEventListener('click', () => window.print());
    });
})();
