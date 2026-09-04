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
        // Two of these now: the one in the table header, and the one that
        // replaces it on a phone where the header row is hidden. They have to
        // move together or ticking one leaves the other saying otherwise.
        var masters = Array.prototype.slice.call(bulkForm.querySelectorAll('[data-check-all]'));
        var master = masters[0] || null;
        var boxes = Array.prototype.slice.call(bulkForm.querySelectorAll('[data-check-row]'));
        var bar = bulkForm.querySelector('[data-bulk-bar]');
        var countEl = bulkForm.querySelector('[data-bulk-count]');

        function refresh() {
            var selected = boxes.filter(function (b) { return b.checked; });

            if (bar) bar.classList.toggle('hidden', selected.length === 0);
            if (countEl) {
                countEl.textContent = selected.length + (selected.length === 1 ? ' lead' : ' leads') + ' selected';
            }
            masters.forEach(function (box) {
                box.checked = selected.length > 0 && selected.length === boxes.length;
                box.indeterminate = selected.length > 0 && selected.length < boxes.length;
            });
        }

        masters.forEach(function (control) {
            control.addEventListener('change', function () {
                boxes.forEach(function (box) { box.checked = control.checked; });
                refresh();
            });
        });

        boxes.forEach(function (box) { box.addEventListener('change', refresh); });
        refresh();

        // Deleting confirms; the rest do not. Marking six leads "contacted"
        // being a two-step job is how people learn to click through warnings
        // without reading them. The dialog itself is set up further down —
        // window.confirm used to do this, and could not be styled, read as a
        // browser malfunction on a phone, and was easy to dismiss by reflex.
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

    // ---- tables become cards on a phone ---------------------------------
    // Below 720px a nine-column table is unreadable: it either scrolls
    // sideways or wraps company names one word per line. The stylesheet
    // restacks each row as a card, and a card needs its own labels because the
    // header row is gone.
    //
    // Copied from the <th> at load rather than written into each <td> by hand,
    // for two reasons. Every table in the app gets it without touching sixteen
    // templates, and a column that only some people see — the admin-only Owner
    // column on the leads list — cannot fall out of step with its label, which
    // is exactly what a hand-written data-label would eventually do.
    document.querySelectorAll('table.data').forEach(function (table) {
        var headers = Array.prototype.slice.call(table.querySelectorAll('thead th'))
            .map(function (th) { return th.textContent.trim(); });

        if (!headers.length) return;

        table.querySelectorAll('tbody tr').forEach(function (row) {
            Array.prototype.slice.call(row.children).forEach(function (cell, index) {
                var label = headers[index];
                // A blank header means the column is a control rather than a
                // fact — the select-all checkbox — and labelling it would read
                // as a field name that is not one.
                if (label) cell.setAttribute('data-label', label);
            });

            // A table cell that has nothing to say still has to hold the column
            // open, which is why so many of them print an em dash. A card has
            // no column to hold open, so the dash becomes a caption with a
            // shrug under it. Mark those so the stylesheet can drop them on a
            // phone; the desktop table keeps its dashes.
            row.querySelectorAll('td, .cell-sub').forEach(function (el) {
                // Text only. A cell holding a checkbox has no text either, and
                // marking that one blank hides it — which quietly removes bulk
                // selection on every phone.
                if (el.querySelector('input, button, a, select, textarea, svg, img')) return;

                var text = (el.textContent || '').replace(/[\s—–-]/g, '');
                if (text === '') el.setAttribute('data-blank', '');
            });
        });
    });

    // ---- the filter panel folds away on a phone -------------------------
    // Eight filters stacked two-up fill a phone screen before a single lead is
    // visible. Shut by default at that size, and the button says how many are
    // actually narrowing the list so a forgotten filter is not invisible.
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-filters-toggle]');
        if (!button) return;

        var panel = document.querySelector('.filters.collapsible');
        if (!panel) return;

        var open = panel.classList.toggle('is-open');
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    // ---- dialogs ---------------------------------------------------------
    // The compose sheets. Real <dialog> markup that is already on the page, so
    // with no JavaScript the button is a link to #compose and the form still
    // works — it just sits inline instead of over the page.
    document.addEventListener('click', function (event) {
        var opener = event.target.closest('[data-open-dialog]');

        if (opener) {
            var dialog = document.getElementById(opener.getAttribute('data-open-dialog'));
            if (!dialog || typeof dialog.showModal !== 'function') return;

            event.preventDefault();
            dialog.showModal();

            // Straight into the message: the addresses and the subject are
            // usually already right, and a cursor in the first field would mean
            // tabbing past three of them every time. The composer is a
            // contenteditable, not a textarea — looking only for the latter is
            // how this quietly stopped focusing anything.
            var body = dialog.querySelector('[data-composer], textarea');
            if (body) body.focus();
            return;
        }

        var closer = event.target.closest('[data-close-dialog]');
        if (closer) {
            var open = closer.closest('dialog');
            if (open) open.close();
            return;
        }

        // Clicking the backdrop closes it. The dialog element itself fills the
        // whole viewport, so a click that landed on it rather than on anything
        // inside it was a click outside the sheet.
        if (event.target.tagName === 'DIALOG') event.target.close();
    });

    // ---- the message composer -------------------------------------------
    // A contenteditable box with a formatting toolbar. The box is not the field
    // that posts: its HTML is copied into a hidden input on submit, so the form
    // has one obvious value and still works if any of this fails to run.
    //
    // Everything that comes back is rebuilt against an allow-list in
    // Support\RichText before it is stored or sent. None of this is a security
    // boundary; it is a typing aid.
    document.querySelectorAll('[data-composer]').forEach(function (editor) {
        var id = editor.getAttribute('data-composer');
        var field = document.querySelector('[data-composer-value="' + id + '"]');
        var form = editor.closest('form');
        if (!field || !form) return;

        function sync() {
            // A box the browser considers empty still holds a stray <br>, which
            // would post as a message with something in it.
            var html = editor.innerHTML.trim();
            field.value = /^(<br\s*\/?>)*$/i.test(html) ? '' : html;
        }

        editor.addEventListener('input', sync);
        editor.addEventListener('blur', sync);
        form.addEventListener('submit', sync);

        // The placeholder is CSS on :empty, which a stray <br> defeats.
        editor.addEventListener('keyup', function () {
            if (/^(<br\s*\/?>)*$/i.test(editor.innerHTML.trim())) editor.innerHTML = '';
        });
    });

    function activeComposer(el) {
        var scope = el.closest('.sheet-body') || el.closest('form') || document;
        return scope.querySelector('[data-composer]');
    }

    document.addEventListener('click', function (event) {
        var tool = event.target.closest('.tool[data-format]');
        if (!tool) return;

        event.preventDefault();
        var editor = activeComposer(tool);
        if (!editor) return;

        editor.focus();
        var command = tool.getAttribute('data-format');

        if (command === 'createLink') {
            var url = window.prompt('Link to where?', 'https://');
            if (!url || !/^https?:\/\/|^mailto:/i.test(url)) return;
            document.execCommand('createLink', false, url);
            return;
        }

        document.execCommand(command, false, null);
    });

    document.addEventListener('change', function (event) {
        var size = event.target.closest('.tool-size');
        if (!size || !size.value) return;

        var editor = activeComposer(size);
        if (!editor) return;

        editor.focus();
        document.execCommand('fontSize', false, size.value);
    });

    // ---- merge variables -------------------------------------------------
    // Dropped in at the cursor, or on the end if the box was never focused.
    document.addEventListener('change', function (event) {
        var picker = event.target.closest('[data-merge-for]');
        if (!picker || !picker.value) return;

        var editor = document.querySelector('[data-composer="' + picker.getAttribute('data-merge-for') + '"]');
        if (editor) {
            editor.focus();
            if (!document.execCommand('insertText', false, picker.value)) {
                editor.appendChild(document.createTextNode(picker.value));
            }
            editor.dispatchEvent(new Event('input'));
        }

        picker.value = '';
    });

    // ---- attachments -----------------------------------------------------
    // Uploaded as they are picked rather than with the message, so a 10MB file
    // does not ride along with every failed send, and so the draft survives.
    document.addEventListener('change', function (event) {
        var input = event.target.closest('[data-attach-input]');
        if (!input || !input.files || !input.files.length) return;

        var id = input.getAttribute('data-attach-input');
        var list = document.querySelector('[data-attach-list="' + id + '"]');
        var form = input.closest('form');
        if (!list || !form) return;

        var csrf = form.querySelector('[name="csrf"]');
        var files = Array.prototype.slice.call(input.files);
        input.value = '';

        files.forEach(function (file) {
            var row = document.createElement('li');
            row.className = 'attach-row is-uploading';
            row.textContent = file.name + ' — uploading…';
            list.appendChild(row);

            var payload = new FormData();
            payload.append('file', file);
            payload.append('csrf', csrf ? csrf.value : '');

            fetch(document.body.getAttribute('data-attach-endpoint') || 'attachments', {
                method: 'POST',
                body: payload,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                return response.json().catch(function () {
                    return { ok: false, message: 'The server did not answer with JSON.' };
                });
            }).then(function (result) {
                row.classList.remove('is-uploading');

                if (!result || !result.ok) {
                    row.classList.add('is-failed');
                    row.textContent = file.name + ' — ' + ((result && result.message) || 'upload failed');
                    return;
                }

                row.textContent = '';

                var name = document.createElement('span');
                name.textContent = result.name;
                row.appendChild(name);

                var value = document.createElement('input');
                value.type = 'hidden';
                value.name = 'attachments[]';
                value.value = result.path;
                row.appendChild(value);

                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'linkish';
                remove.textContent = 'Remove';
                remove.addEventListener('click', function () { row.remove(); });
                row.appendChild(remove);
            }).catch(function () {
                row.classList.remove('is-uploading');
                row.classList.add('is-failed');
                row.textContent = file.name + ' — upload failed';
            });
        });
    });

    // ---- the bulk composer picks up whatever is ticked -------------------
    // The dialog's form is a sibling of the list's form, not a child of it —
    // nesting forms is invalid and the browser drops the inner one. So the
    // ticked ids are copied across when the dialog opens, and the count in the
    // heading is filled in at the same time.
    document.addEventListener('click', function (event) {
        var opener = event.target.closest('[data-open-dialog="bulk-compose"]');
        if (!opener) return;

        var list = document.querySelector('[data-bulk-form]');
        var form = document.querySelector('[data-bulk-recipients]');
        if (!list || !form) return;

        form.querySelectorAll('[data-recipient]').forEach(function (el) { el.remove(); });

        var picked = Array.prototype.slice.call(list.querySelectorAll('[data-check-row]:checked'));

        picked.forEach(function (box) {
            var carry = document.createElement('input');
            carry.type = 'hidden';
            carry.name = 'ids[]';
            carry.value = box.value;
            carry.setAttribute('data-recipient', '');
            form.appendChild(carry);
        });

        var label = picked.length + (picked.length === 1 ? ' lead' : ' leads');
        form.querySelectorAll('[data-bulk-recipient-count]').forEach(function (el) {
            el.textContent = label;
        });
        document.querySelectorAll('#bulk-compose [data-bulk-recipient-count]').forEach(function (el) {
            el.textContent = label;
        });
    });

    // ---- destructive confirmations --------------------------------------
    // A styled dialog rather than window.confirm, which cannot be themed, reads
    // as a browser malfunction on a phone, and is easy to dismiss by reflex.
    // The form is only submitted when the dialog says so.
    var pendingForm = null;

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) return;

        var dialogId = form.getAttribute('data-confirm-dialog');
        if (!dialogId || form.dataset.confirmed === 'yes') return;

        var dialog = document.getElementById(dialogId);
        if (!dialog || typeof dialog.showModal !== 'function') return;

        event.preventDefault();
        event.stopImmediatePropagation();

        pendingForm = form;

        // The count is only known at the moment of asking.
        var counter = dialog.querySelector('[data-confirm-count]');
        if (counter) {
            var picked = form.querySelectorAll('[data-check-row]:checked').length;
            counter.textContent = picked + (picked === 1 ? ' lead' : ' leads');
        }

        dialog.showModal();
    }, true);

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-confirm-go]')) {
            var dialog = event.target.closest('dialog');
            if (dialog) dialog.close();

            if (pendingForm) {
                var form = pendingForm;
                pendingForm = null;
                form.dataset.confirmed = 'yes';
                // requestSubmit rather than submit(), so the submit listeners
                // that carry the pressed button and show the busy state still
                // run — submit() skips all of them.
                if (form.requestSubmit) form.requestSubmit();
                else form.submit();
            }
            return;
        }

        if (event.target.closest('[data-confirm-no]')) {
            pendingForm = null;
            var open = event.target.closest('dialog');
            if (open) open.close();
        }
    });

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
