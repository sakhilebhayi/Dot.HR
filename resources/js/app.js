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

    // <button data-print>: print the page (documents such as the certificate of service).
    if (event.target instanceof Element && event.target.closest('[data-print]')) {
        window.print();
    }

    // <button data-theme-toggle>: flip The Register between paper and ink, and remember it.
    if (event.target instanceof Element && event.target.closest('[data-theme-toggle]')) {
        const dark = document.documentElement.classList.toggle('dark');
        try { localStorage.setItem('dot-theme', dark ? 'dark' : 'light'); } catch (e) { /* private mode */ }
    }
}, true);

// <select data-autosubmit>: send its form as soon as the choice changes.
document.addEventListener('change', (event) => {
    const select = event.target;
    if (select instanceof HTMLSelectElement && 'autosubmit' in select.dataset && select.form) {
        select.form.requestSubmit();
    }
});
