// Region overlay (#10): tabs, canton combobox, map, place combobox and radius on top of a plain GET form. Only the active
// tab's fields are enabled, so the form submits what the tab shows; live-count.js picks up the `change` events.
(() => {
    const form = document.querySelector('form[data-region-filter]');
    if (!form) {
        return;
    }
    const overlay = form.closest('[popover]');
    const { placesUrl, noPlaces, radiusZero, radiusOther } = form.dataset;
    const get = (selector) => form.querySelector(selector);
    const all = (selector) => [...form.querySelectorAll(selector)];
    const canton = get('[data-region-value="canton"]');
    const place = get('[data-region-value="place"]');
    const cantonInput = get('#filter-region-canton');
    const placeInput = get('#filter-region-place');
    const placeList = get('#filter-region-place-list');
    const radius = get('#filter-region-radius');
    const output = get('output');
    const map = get('[data-region-map]');
    const tooltip = get('[data-region-tooltip]');
    const tabs = all('[role="tab"]');
    const paths = all('#cantons path');
    const options = all('#filter-region-canton-list [role="option"]');
    const labelOf = (code) => options.find((option) => option.dataset.value === code)?.textContent ?? '';
    const codeOf = (path) => path.id.slice(3);
    const changed = (control) => control.dispatchEvent(new Event('change', { bubbles: true }));
    const fold = (text) => text.toLowerCase().normalize('NFD').replace(/\p{M}/gu, '')
        .replace(/([aou])e/g, '$1').replace(/[^a-z0-9]/g, '');

    const combobox = (input, pick, stop) => {
        const list = get(`#${input.getAttribute('aria-controls')}`);
        const items = () => [...list.children].filter((item) => !item.hidden && !item.hasAttribute('aria-disabled'));
        let active = -1;
        const mark = (index) => {
            const shown = items();
            active = shown.length ? (index + shown.length) % shown.length : -1;
            shown.forEach((item, i) => item.setAttribute('aria-selected', i === active));
            if (active < 0) {
                input.removeAttribute('aria-activedescendant');
            } else {
                input.setAttribute('aria-activedescendant', shown[active].id);
                shown[active].scrollIntoView({ block: 'nearest' });
            }
        };
        const close = () => {
            stop?.();
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
        };
        const open = () => {
            if (document.activeElement !== input) {
                return;
            }
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            mark(0);
        };
        const choose = (item) => {
            pick(item);
            close();
        };

        input.addEventListener('blur', close);
        // Keeps focus in the input, so the blur above does not close the list before the click.
        list.addEventListener('mousedown', (event) => event.preventDefault());
        list.addEventListener('click', (event) => {
            const item = event.target.closest('[role="option"]');
            if (item && !item.hasAttribute('aria-disabled')) {
                choose(item);
            }
        });
        input.addEventListener('keydown', (event) => {
            if (list.hidden) {
                return;
            }
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                mark(active + (event.key === 'ArrowDown' ? 1 : -1));
            } else if (event.key === 'Enter') {
                event.preventDefault();
                if (active < 0) {
                    close();
                } else {
                    choose(items()[active]);
                }
            } else if (event.key === 'Escape') {
                // Closes the list only; the popover would otherwise close on the same key.
                event.preventDefault();
                close();
            }
        });

        return { open, close };
    };

    // Town pin and radius circle on the Ort tab. The map is an affine fit to the towns dataset, 2.29 units per km.
    const svg = map.querySelector('svg');
    const circle = (name) => {
        const element = document.createElementNS(svg.namespaceURI, 'circle');
        element.classList.add(`region-filter__${name}`);
        return element;
    };
    const area = circle('area');
    const pin = circle('pin');
    pin.setAttribute('r', 6);
    let spot = place.dataset.lat ? [+place.dataset.lat, +place.dataset.lng] : null;

    const drawPin = () => {
        if (!spot) {
            area.remove();
            pin.remove();
            return;
        }
        const [lat, lng] = spot;
        [area, pin].forEach((element) => {
            element.setAttribute('cx', 174.941 * lng - 2.695 * lat - 912.097);
            element.setAttribute('cy', -1.378 * lng - 253.482 * lat + 12136.239);
        });
        area.setAttribute('r', radius.value * 2.29);
        svg.append(area, pin);
    };

    const syncRadius = () => {
        output.textContent = radius.value === '0' ? radiusZero : radiusOther.replace('%n', radius.value);
        radius.disabled = form.dataset.tab !== 'place' || !place.value;
        drawPin();
    };

    const setCanton = (code) => {
        const previous = canton.value;
        canton.value = code;
        paths.forEach((path) => path.setAttribute('aria-pressed', codeOf(path) === code));
        rove(paths.find((path) => codeOf(path) === code));
        if (previous !== code) {
            changed(canton);
        }
    };

    const rove = (target = paths[0]) => {
        paths.forEach((path) => {
            path.tabIndex = path === target ? 0 : -1;
        });
    };

    const pickPath = (path) => {
        cantonInput.value = labelOf(codeOf(path));
        setCanton(codeOf(path));
    };

    const setPlace = (value, coordinates = null) => {
        place.value = value;
        spot = coordinates;
        syncRadius();
        changed(place);
    };

    const setTab = (tab, silent) => {
        const isPlace = tab === 'place';
        form.dataset.tab = tab;
        tabs.forEach((button) => {
            const selected = button.dataset.tab === tab;
            button.setAttribute('aria-selected', selected);
            button.tabIndex = selected ? 0 : -1;
        });
        all('[data-panel]').forEach((panel) => {
            panel.hidden = panel.dataset.panel !== tab;
        });
        canton.disabled = isPlace;
        place.disabled = !isPlace;
        map.inert = isPlace;
        tooltip.hidden = true;
        syncRadius();
        if (!silent) {
            changed(place);
        }
    };

    const activeInput = () => (form.dataset.tab === 'place' ? placeInput : cantonInput);
    const focusEmpty = () => {
        if (!activeInput().value) {
            activeInput().focus();
        }
    };

    // Init: the no-JS controls give way to the tabs, the hidden fields and the combobox.
    get('[data-region-tabs]').hidden = false;
    get('[data-region-js]').hidden = false;
    output.hidden = false;
    placeInput.removeAttribute('name');
    placeInput.value = placeInput.dataset.display || placeInput.value;
    paths.forEach((path) => {
        path.setAttribute('role', 'button');
        path.setAttribute('aria-label', labelOf(codeOf(path)));
    });

    const snapshot = {
        tab: place.value && !canton.value ? 'place' : 'canton',
        canton: canton.value,
        place: place.value,
        cantonText: cantonInput.value,
        placeText: placeInput.value,
        radius: radius.value,
        spot,
    };

    const restore = () => {
        canton.value = snapshot.canton;
        place.value = snapshot.place;
        cantonInput.value = snapshot.cantonText;
        placeInput.value = snapshot.placeText;
        radius.value = snapshot.radius;
        spot = snapshot.spot;
        paths.forEach((path) => path.setAttribute('aria-pressed', codeOf(path) === canton.value));
        rove(paths.find((path) => codeOf(path) === canton.value));
        setTab(snapshot.tab, true);
    };
    restore();

    // Tabs
    const switchTab = (tab) => {
        placeBox.close();
        setTab(tab);
    };
    tabs.forEach((button) => button.addEventListener('click', () => {
        switchTab(button.dataset.tab);
        focusEmpty();
    }));
    get('[data-region-tabs]').addEventListener('keydown', (event) => {
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            const next = tabs.find((button) => button.dataset.tab !== form.dataset.tab);
            switchTab(next.dataset.tab);
            next.focus();
        }
    });

    // Canton combobox: filters the server-rendered options. An exact name selects, anything else clears the canton.
    const cantonBox = combobox(cantonInput, (item) => {
        cantonInput.value = item.textContent;
        setCanton(item.dataset.value);
    });
    cantonInput.addEventListener('input', () => {
        const typed = fold(cantonInput.value);
        let shown = 0;
        options.forEach((option) => {
            option.hidden = typed.length < 2 || shown >= 6 || !fold(option.textContent).startsWith(typed);
            shown += option.hidden ? 0 : 1;
        });
        setCanton(options.find((option) => fold(option.textContent) === typed)?.dataset.value ?? '');
        if (shown) {
            cantonBox.open();
        } else {
            cantonBox.close();
        }
    });

    // Map: one Tab stop, arrows move between the cantons.
    map.addEventListener('click', (event) => {
        const path = event.target.closest('#cantons path');
        if (path) {
            pickPath(path);
        }
    });
    map.addEventListener('keydown', (event) => {
        const path = event.target.closest('#cantons path');
        if (!path) {
            return;
        }
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            pickPath(path);
        } else if (/^Arrow/.test(event.key)) {
            event.preventDefault();
            const step = event.key === 'ArrowRight' || event.key === 'ArrowDown' ? 1 : -1;
            const next = paths[(paths.indexOf(path) + step + paths.length) % paths.length];
            rove(next);
            next.focus();
        }
    });

    // Tooltip: towards the map's centre, with the pointer on the side of the canton.
    const showTip = (event) => {
        const path = event.target.closest('#cantons path');
        if (!path) {
            return;
        }
        tooltip.textContent = path.getAttribute('aria-label');
        tooltip.hidden = false;
        const box = map.getBoundingClientRect();
        const shape = path.getBoundingClientRect();
        const onRight = shape.left + shape.width / 2 > box.left + box.width / 2;
        const left = (onRight ? shape.left - tooltip.offsetWidth - 10 : shape.right + 10) - box.left;
        const top = shape.top + shape.height / 2 - box.top;
        tooltip.style.left = `${Math.max(0, Math.min(left, box.width - tooltip.offsetWidth))}px`;
        tooltip.style.top = `${Math.max(0, Math.min(top, box.height - tooltip.offsetHeight))}px`;
        tooltip.classList.toggle('region-filter__tooltip--right-top', onRight);
        tooltip.classList.toggle('region-filter__tooltip--left-top', !onRight);
    };
    const hideTip = () => {
        tooltip.hidden = true;
    };
    map.addEventListener('pointerover', showTip);
    map.addEventListener('focusin', showTip);
    map.addEventListener('pointerout', hideTip);
    map.addEventListener('focusout', hideTip);

    // Place combobox: four typed digits count as that ZIP; any other edit clears a chosen town. Closing it cancels the
    // pending lookup, so every way of moving on (choice, blur, Esc, clear, tab, overlay) drops a late answer.
    let placeTimer;
    let placeRequest;
    const stopPlaces = () => {
        clearTimeout(placeTimer);
        placeRequest?.abort();
    };
    const placeBox = combobox(placeInput, (item) => {
        placeInput.value = item.textContent;
        radius.value = radius.dataset.default;
        setPlace(item.dataset.id, [+item.dataset.lat, +item.dataset.lng]);
    }, stopPlaces);
    const showPlaces = (towns) => {
        placeList.replaceChildren(...(towns.length ? towns : [null]).map((town, index) => {
            const item = document.createElement('li');
            item.role = 'option';
            item.id = `filter-region-place-${index}`;
            if (town) {
                item.dataset.id = town.id;
                item.dataset.lat = town.lat;
                item.dataset.lng = town.lng;
                item.textContent = `${town.zip} ${town.name}`;
            } else {
                item.setAttribute('aria-disabled', 'true');
                item.textContent = noPlaces;
            }
            return item;
        }));
        placeBox.open();
    };
    placeInput.addEventListener('input', () => {
        const text = placeInput.value.trim();
        const digits = /^\d/.test(text);
        // A list older than the text must go: Enter would take its first row.
        placeBox.close();
        setPlace(/^\d{4}$/.test(text) ? text : '');
        if (text.length < (digits ? 1 : 2)) {
            return;
        }
        placeTimer = setTimeout(async () => {
            placeRequest = new AbortController();
            const { signal } = placeRequest;
            try {
                const response = await fetch(`${placesUrl}?q=${encodeURIComponent(text)}`, { signal });
                const { places } = await response.json();
                showPlaces(places.filter(({ type }) => type === 'town').slice(0, 6));
            } catch {
                // Aborted means a newer keystroke may have set a timer that close() would cancel.
                if (!signal.aborted) {
                    placeBox.close();
                }
            }
        }, 150);
    });

    radius.addEventListener('input', syncRadius);

    const closeLists = () => {
        cantonBox.close();
        placeBox.close();
    };

    // "Filter löschen": clears this overlay only; nothing applies until the button is pressed.
    overlay.querySelector('[data-region-clear]').addEventListener('click', (event) => {
        event.preventDefault();
        closeLists();
        cantonInput.value = '';
        placeInput.value = '';
        radius.value = radius.dataset.default;
        place.value = '';
        spot = null;
        setCanton('');
        syncRadius();
        changed(radius);
    });

    overlay.addEventListener('toggle', (event) => {
        if (event.newState === 'closed') {
            closeLists();
            tooltip.hidden = true;
            restore();
        } else if (!matchMedia('(pointer: coarse)').matches) {
            focusEmpty();
        }
    });

    // A canton with a landing page (#17) submits to its path (S5), no redirect; pageshow undoes it after Back.
    const landing = JSON.parse(form.dataset.landing || '{}');
    const base = form.action;
    form.addEventListener('submit', () => {
        const path = form.dataset.tab !== 'place' && landing[canton.value];
        form.action = path || base;
        canton.disabled = form.dataset.tab === 'place' || !!path;
    });
    addEventListener('pageshow', () => (canton.disabled = form.dataset.tab === 'place'));
})();
