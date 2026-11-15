// The few behaviours the views used to write as onsubmit= / onclick= / onchange= attributes.
// The enforced Content-Security-Policy sets script-src-attr 'none', so inline handlers no
// longer run; the views mark elements with data attributes and these listeners act on them.

// <form data-confirm="..."> or <button data-confirm="...">: ask before going ahead.
document.addEventListener('submit', (event) => {
    const form = event.target;
    const message = form instanceof HTMLFormElement ? form.dataset.confirm : null;
    if (message && !window.confirm(message)) {
        event.preventDefault();
        event.stopImmediatePropagation();
    }
}, true);

document.addEventListener('click', (event) => {
    const trigger = event.target instanceof Element ? event.target.closest('button[data-confirm], a[data-confirm]') : null;
    if (trigger && !window.confirm(trigger.dataset.confirm)) {
        event.preventDefault();
        event.stopImmediatePropagation();
        return;
    }

    // <button data-check-all="group">: tick every [data-check-group="group"] box, or untick them
    // all when they are already ticked (bulk moves in a hiring pipeline).
    const checkAll = event.target instanceof Element ? event.target.closest('[data-check-all]') : null;
    if (checkAll) {
        const boxes = [...document.querySelectorAll(`input[type="checkbox"][data-check-group="${CSS.escape(checkAll.dataset.checkAll)}"]`)];
        const tick = boxes.some((box) => !box.checked);
        boxes.forEach((box) => { box.checked = tick; });
        checkAll.textContent = tick ? 'Clear' : 'Select all';
    }

    // <button data-print>: print the page (documents such as the certificate of service).
    if (event.target instanceof Element && event.target.closest('[data-print]')) {
        window.print();
    }
}, true);

// <select data-autosubmit>: send its form as soon as the choice changes.
document.addEventListener('change', (event) => {
    const select = event.target;
    if (select instanceof HTMLSelectElement && 'autosubmit' in select.dataset && select.form) {
        select.form.requestSubmit();
    }
});

// Icons: the self-hosted subset font normally loads at once. If it never does (blocked, offline), hide the ligature names
// rather than print them as words (html.rg-noicons, see register.css).
if (document.fonts?.load) {
    document.fonts.load('20px "Material Symbols Rounded"', 'add').then((faces) => {
        if (!faces.length) document.documentElement.classList.add('rg-noicons');
    }).catch(() => document.documentElement.classList.add('rg-noicons'));
}

// The notification bell (a Livewire component around dot-ui's panel bell): a re-render (mark as read) rewrites the panel's
// attributes, which would close it under the person's finger. Remember that it is open and put the open attributes back.
let bellOpen = false;
document.addEventListener('dot:menu-open', (event) => { if (event.target.closest?.('[data-dh-bell]')) bellOpen = true; });
document.addEventListener('dot:menu-close', (event) => { if (event.target.closest?.('[data-dh-bell]')) bellOpen = false; });
const hookBell = () => window.Livewire.hook('morphed', ({ el, component }) => {
    if (component.name !== 'notification-bell' || !bellOpen) return;
    const root = el.querySelector('[data-dh-bell]');
    if (!root) return;
    root.setAttribute('data-open', '');
    root.querySelector('[data-dh-menu-panel]')?.removeAttribute('hidden');
    root.querySelector('[data-dh-menu-trigger]')?.setAttribute('aria-expanded', 'true');
});
if (window.Livewire) hookBell(); else document.addEventListener('livewire:init', hookBell);
