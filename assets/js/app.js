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

        // Only the delete action confirms. A blanket data-confirm on this form
        // would make marking six leads "contacted" a two-step job, which is
        // how people learn to click through warnings without reading them.
        bulkForm.addEventListener('submit', function (event) {
            var action = bulkForm.querySelector('[name="bulk_action"]');
            if (!action || action.value !== 'delete') return;

            var count = boxes.filter(function (b) { return b.checked; }).length;
            var message = 'Delete ' + count + (count === 1 ? ' lead' : ' leads')
                + ' for good? This cannot be undone, and their notes and history go too.'
                + ' Archive instead if you only want them out of the working list.';

            if (!window.confirm(message)) event.preventDefault();
        });
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
        // data-busy is read off the button first so one form can have a slow
        // action and a fast one side by side.
        var pressed = event.submitter;
        var busyLabel = (pressed && pressed.getAttribute('data-busy')) || form.getAttribute('data-busy');
        if (busyLabel) {
            // A disabled control is not submitted, so disabling the button that
            // was just pressed drops its name and value from the payload. Forms
            // that decide what to do by which button was clicked would then
            // silently take the default branch — which is how "draft one email"
            // became "draft all six". Carry the value in a hidden field first.
            var submitter = pressed;
            if (submitter && submitter.name && !form.querySelector('[data-carried]')) {
                var carry = document.createElement('input');
                carry.type = 'hidden';
                carry.name = submitter.name;
                carry.value = submitter.value;
                carry.setAttribute('data-carried', '');
                form.appendChild(carry);
            }

            // Disable the button that was actually pressed, not the first one in
            // the form — otherwise a second button stays live and clickable.
            var button = submitter || form.querySelector('[type="submit"]');
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

    // ---- pipeline board: drag a card to another stage -------------------
    // The card moves in the DOM first and is put back if GoHighLevel refuses,
    // so a normal move feels instant and a failed one is obvious.
    var board = document.querySelector('[data-board]');
    if (board) {
        var dragging = null;
        var origin = null;

        var recount = function () {
            board.querySelectorAll('.board-col').forEach(function (col) {
                var badge = col.querySelector('[data-count]');
                if (badge) badge.textContent = col.querySelectorAll('.board-card').length;
            });
        };

        board.addEventListener('dragstart', function (event) {
            var card = event.target.closest('.board-card');
            if (!card) return;
            dragging = card;
            origin = card.parentElement;
            card.classList.add('is-dragging');
            // Firefox will not start a drag without data on the transfer.
            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', card.getAttribute('data-card') || '');
            }
        });

        board.addEventListener('dragend', function () {
            if (dragging) dragging.classList.remove('is-dragging');
            board.querySelectorAll('[data-drop]').forEach(function (d) { d.classList.remove('is-over'); });
            dragging = null;
        });

        board.addEventListener('dragover', function (event) {
            var drop = event.target.closest('[data-drop]');
            if (!drop || !dragging) return;
            event.preventDefault();
            drop.classList.add('is-over');
        });

        board.addEventListener('dragleave', function (event) {
            var drop = event.target.closest('[data-drop]');
            if (drop && !drop.contains(event.relatedTarget)) drop.classList.remove('is-over');
        });

        board.addEventListener('drop', function (event) {
            var drop = event.target.closest('[data-drop]');
            if (!drop || !dragging) return;
            event.preventDefault();
            drop.classList.remove('is-over');

            var card = dragging;
            var from = origin;
            var column = drop.closest('.board-col');
            if (!column || from === drop) return;

            drop.appendChild(card);
            card.classList.add('is-saving');
            recount();

            var body = new URLSearchParams();
            body.set('csrf', board.getAttribute('data-csrf') || '');
            body.set('opportunity_id', card.getAttribute('data-card') || '');
            body.set('pipeline_id', board.getAttribute('data-pipeline') || '');
            body.set('stage_id', column.getAttribute('data-stage') || '');

            fetch(board.getAttribute('data-endpoint'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: body.toString(),
                credentials: 'same-origin'
            }).then(function (response) {
                return response.json().catch(function () { return { ok: false, message: 'Unexpected reply.' }; });
            }).then(function (result) {
                card.classList.remove('is-saving');
                if (result && result.ok) return;
                if (from) from.appendChild(card);
                recount();
                window.alert((result && result.message) || 'GoHighLevel would not move that deal.');
            }).catch(function () {
                card.classList.remove('is-saving');
                if (from) from.appendChild(card);
                recount();
                window.alert('Could not reach the server. The card was put back.');
            });
        });
    }

    // ---- sending to a real prospect needs an explicit confirmation ------
    var sendForm = document.querySelector('[data-send-form]');
    if (sendForm) {
        var typeSelect = sendForm.querySelector('[data-send-type]');
        var subjectField = sendForm.querySelector('[data-subject-field]');

        var syncType = function () {
            if (!typeSelect || !subjectField) return;
            subjectField.style.display = typeSelect.value === 'Email' ? '' : 'none';
        };

        if (typeSelect) typeSelect.addEventListener('change', syncType);
        syncType();

        sendForm.addEventListener('submit', function (event) {
            var confirmField = sendForm.querySelector('[data-confirm]');
            var bodyField = sendForm.querySelector('[name="body"]');
            var kind = typeSelect && typeSelect.value === 'Email' ? 'email' : 'text message';

            if (!bodyField || bodyField.value.trim() === '') {
                event.preventDefault();
                window.alert('Write the message first.');
                return;
            }

            if (!window.confirm('Send this ' + kind + ' to the contact now? It goes out immediately.')) {
                event.preventDefault();
                return;
            }

            if (confirmField) confirmField.value = '1';
        });
    }

    // ---- digging takes 20-40s, so say so rather than looking dead --------
    var digForm = document.querySelector('[data-dig-form]');
    if (digForm) {
        digForm.addEventListener('submit', function () {
            var button = digForm.querySelector('[data-dig-button]');
            if (!button) return;
            button.classList.add('is-digging');
            button.textContent = 'Digging — this takes up to a minute…';
        });
    }
})();
