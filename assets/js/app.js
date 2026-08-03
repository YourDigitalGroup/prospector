/* Prospector — progressive enhancement only. Every screen works without JS. */
(function () {
    'use strict';

    // ---- theme -----------------------------------------------------------
    var root = document.documentElement;
    var STORAGE_KEY = 'prospector-theme';

    function applyTheme(theme) {
        if (theme === 'light' || theme === 'dark') {
            root.setAttribute('data-theme', theme);
        } else {
            root.removeAttribute('data-theme');
        }
    }

    try {
        applyTheme(localStorage.getItem(STORAGE_KEY));
    } catch (e) { /* private mode */ }

    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-theme-toggle]');
        if (!toggle) return;

        var current = root.getAttribute('data-theme');
        if (!current) {
            current = window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
        }
        var next = current === 'dark' ? 'light' : 'dark';

        applyTheme(next);
        try { localStorage.setItem(STORAGE_KEY, next); } catch (e) { /* ignore */ }
    });

    // ---- sidebar on small screens ---------------------------------------
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-sidebar-toggle]');
        if (!button) return;

        var sidebar = document.querySelector('.sidebar');
        if (!sidebar) return;

        var collapsed = sidebar.getAttribute('data-collapsed') === 'true';
        sidebar.setAttribute('data-collapsed', collapsed ? 'false' : 'true');
        button.setAttribute('aria-expanded', collapsed ? 'true' : 'false');
    });

    // ---- bulk selection --------------------------------------------------
    var bulkForm = document.querySelector('[data-bulk-form]');
    if (bulkForm) {
        var master = bulkForm.querySelector('[data-check-all]');
        var boxes = Array.prototype.slice.call(bulkForm.querySelectorAll('[data-check-row]'));
        var bar = bulkForm.querySelector('[data-bulk-bar]');
        var countEl = bulkForm.querySelector('[data-bulk-count]');

        function refresh() {
            var selected = boxes.filter(function (b) { return b.checked; });

            if (bar) bar.classList.toggle('hidden', selected.length === 0);
            if (countEl) {
                countEl.textContent = selected.length + (selected.length === 1 ? ' lead' : ' leads') + ' selected';
            }
            if (master) {
                master.checked = selected.length > 0 && selected.length === boxes.length;
                master.indeterminate = selected.length > 0 && selected.length < boxes.length;
            }
        }

        if (master) {
            master.addEventListener('change', function () {
                boxes.forEach(function (box) { box.checked = master.checked; });
                refresh();
            });
        }

        boxes.forEach(function (box) { box.addEventListener('change', refresh); });
        refresh();
    }

    // ---- auto-submit filters --------------------------------------------
    document.querySelectorAll('[data-autosubmit]').forEach(function (field) {
        field.addEventListener('change', function () {
            var form = field.form;
            if (form) form.submit();
        });
    });

    // ---- confirmations ---------------------------------------------------
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) return;

        var message = form.getAttribute('data-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
            return;
        }

        // Long-running submits: show progress so nobody double-clicks a batch.
        var busyLabel = form.getAttribute('data-busy');
        if (busyLabel) {
            var button = form.querySelector('[type="submit"]');
            if (button) {
                button.disabled = true;
                button.dataset.originalText = button.textContent;
                button.textContent = busyLabel;
            }
        }
    });

    // ---- copy to clipboard ----------------------------------------------
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-copy]');
        if (!trigger) return;

        event.preventDefault();
        var value = trigger.getAttribute('data-copy');
        if (!value || !navigator.clipboard) return;

        navigator.clipboard.writeText(value).then(function () {
            var original = trigger.getAttribute('title') || '';
            trigger.setAttribute('title', 'Copied');
            trigger.classList.add('is-copied');
            setTimeout(function () {
                trigger.setAttribute('title', original);
                trigger.classList.remove('is-copied');
            }, 1400);
        }).catch(function () { /* clipboard blocked */ });
    });

    // ---- reload a page that is waiting on a running batch ---------------
    var waiting = document.querySelector('[data-poll-seconds]');
    if (waiting) {
        var seconds = parseInt(waiting.getAttribute('data-poll-seconds'), 10);
        if (seconds > 0) {
            setTimeout(function () { window.location.reload(); }, seconds * 1000);
        }
    }
})();
