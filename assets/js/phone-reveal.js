// Phone reveal (#8, DP-11): "Telefonnummer anzeigen" is a plain link to /hochzeitslocation/{slug}/telefon.
// With JS it fetches the number as JSON and swaps in a tel: link; on any failure it follows the link.
// Self-contained: no imports, no globals.
(() => {
    document.querySelectorAll('a[data-phone-reveal]').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            const fallback = () => {
                window.location.href = link.href;
            };
            fetch(link.href, {headers: {Accept: 'application/json'}, credentials: 'same-origin'})
                .then((response) => (response.ok ? response.json() : Promise.reject(response.status)))
                .then((data) => {
                    if (!data || typeof data.phone !== 'string' || typeof data.display !== 'string') {
                        fallback();
                        return;
                    }
                    const tel = document.createElement('a');
                    tel.className = link.className;
                    tel.href = 'tel:' + data.phone;
                    const icon = link.querySelector('svg');
                    if (icon) {
                        tel.append(icon.cloneNode(true));
                    }
                    const text = document.createElement('span');
                    text.textContent = data.display;
                    tel.append(text);
                    link.replaceWith(tel);
                    tel.focus();
                })
                .catch(fallback);
        });
    });
})();
