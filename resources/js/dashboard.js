// Dashboard behaviour (R-51). Progressive: without this script every row is still a link to
// its request and the pane shows the first one. It only adds the selection of a queue row into
// the pane beside it, and arrow-key movement along the queue. The row facts were already loaded
// with the page (data-* on the row); nothing is fetched. Personal details settle by fade only.

const queue = document.querySelector('[data-queue]');
const pane = document.querySelector('[data-queue-pane]');

if (queue && pane) {
    const rows = [...queue.querySelectorAll('[role="option"]')];
    const wide = window.matchMedia('(min-width: 761px)');
    const body = pane.querySelector('.dash-pane-body');
    const fields = {};
    pane.querySelectorAll('[data-fill]').forEach((el) => { fields[el.dataset.fill] = el; });
    const open = pane.querySelector('[data-fill-href]');
    const reasonRow = pane.querySelector('[data-reason-row]');

    const select = (row, { focus = false } = {}) => {
        rows.forEach((r) => {
            const on = r === row;
            r.setAttribute('aria-selected', on ? 'true' : 'false');
            const link = r.querySelector('a.go');
            if (link) link.tabIndex = on ? 0 : -1;
        });
        const d = row.dataset;
        if (fields.name) fields.name.textContent = d.name || '';
        if (fields.type) fields.type.textContent = d.type || '';
        if (fields.dates) fields.dates.textContent = d.dates || '';
        if (fields.days) fields.days.textContent = d.days || '';
        if (fields.asked) fields.asked.textContent = d.asked || '';
        if (fields.status) fields.status.textContent = d.status || '';
        if (fields.reason) fields.reason.textContent = d.reason || '';
        if (reasonRow) reasonRow.hidden = !('reason' in d);
        if (open && d.href) open.setAttribute('href', d.href);
        if (body) {
            body.classList.remove('is-settling');
            void body.offsetWidth; // restart the fade
            body.classList.add('is-settling');
        }
        if (focus) row.querySelector('a.go')?.focus();
    };

    queue.addEventListener('click', (event) => {
        const row = event.target instanceof Element ? event.target.closest('[role="option"]') : null;
        if (!row || !wide.matches) return;
        // A keyboard Enter arrives as a click with detail 0 and a second quick click as detail 2:
        // both open the request. A single mouse click selects it. On a phone the pane sits below
        // the list, so a tap always opens the request.
        if (event.detail === 1) {
            event.preventDefault();
            select(row);
        }
    });

    queue.addEventListener('focusin', (event) => {
        const row = event.target instanceof Element ? event.target.closest('[role="option"]') : null;
        if (row && row.getAttribute('aria-selected') !== 'true') select(row);
    });

    queue.addEventListener('keydown', (event) => {
        const index = rows.findIndex((r) => r.contains(event.target));
        if (index < 0) return;
        let next = null;
        if (event.key === 'ArrowDown') next = rows[Math.min(rows.length - 1, index + 1)];
        else if (event.key === 'ArrowUp') next = rows[Math.max(0, index - 1)];
        else if (event.key === 'Home') next = rows[0];
        else if (event.key === 'End') next = rows[rows.length - 1];
        if (next) {
            event.preventDefault();
            select(next, { focus: true });
        }
    });

    // Roving focus: one stop in the queue, the selected row.
    const current = rows.find((r) => r.getAttribute('aria-selected') === 'true') || rows[0];
    rows.forEach((r) => {
        const link = r.querySelector('a.go');
        if (link) link.tabIndex = r === current ? 0 : -1;
    });
}
