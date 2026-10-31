// Tablists on server pages (locale tabs, Markdown): arrow keys, Home, End and a roving tabindex. Without
// JS every panel shows; this hides the inactive ones on load. Activation is a click, so a listener such as
// markdown-preview.js sees the keyboard too.
const tabsOf = (tablist) => [...tablist.querySelectorAll('[role="tab"]')];

const select = (selected) => {
    tabsOf(selected.closest('[role="tablist"]')).forEach((tab) => {
        const isSelected = tab === selected;
        tab.setAttribute('aria-selected', String(isSelected));
        tab.tabIndex = isSelected ? 0 : -1;

        const panel = document.getElementById(tab.getAttribute('aria-controls'));
        if (panel) {
            panel.hidden = !isSelected;
        }
    });
};

document.querySelectorAll('[role="tablist"]').forEach((tablist) => {
    const tabs = tabsOf(tablist);
    const selected = tabs.find((tab) => tab.getAttribute('aria-selected') === 'true') ?? tabs[0];
    if (selected) {
        select(selected);
    }
});

document.addEventListener('click', (event) => {
    const tab = event.target instanceof Element ? event.target.closest('[role="tab"]') : null;
    if (tab) {
        select(tab);
    }
});

// Record tabs are links that scroll sideways on a phone: the current one starts in view, and Tab brings each one in.
// Through scrollLeft: scrollIntoView() would also move the page, away from the #anchor of a card just saved.
const reveal = (tab) => {
    const nav = tab.parentElement.getBoundingClientRect();
    const box = tab.getBoundingClientRect();

    tab.parentElement.scrollLeft += Math.max(box.right - nav.right, 0) + Math.min(box.left - nav.left, 0);
};

const currentTab = document.querySelector('.tabs--record .tab--active');
if (currentTab) {
    reveal(currentTab);
}

document.addEventListener('focusin', (event) => {
    const tab = event.target instanceof Element ? event.target.closest('.tabs--record .tab') : null;
    if (tab) {
        reveal(tab);
    }
});

document.addEventListener('keydown', (event) => {
    const tab = event.target instanceof Element ? event.target.closest('[role="tab"]') : null;
    if (!tab) {
        return;
    }

    const tabs = tabsOf(tab.closest('[role="tablist"]'));
    const index = tabs.indexOf(tab);
    const target = {
        ArrowRight: tabs[(index + 1) % tabs.length],
        ArrowLeft: tabs[(index - 1 + tabs.length) % tabs.length],
        Home: tabs[0],
        End: tabs[tabs.length - 1],
    }[event.key];

    if (target) {
        event.preventDefault();
        target.focus();
        target.click();
    }
});
