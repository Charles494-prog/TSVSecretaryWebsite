// ---- toasts: short success / error messages in the corner ----
const toastRegion = document.querySelector('[data-toasts]');
const toastIcons = {
    success: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>',
    error: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>',
    info: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M12 16v-4M12 8h.01"/></svg>',
};

// Phones (the Android app, or a phone-width browser) get full-screen "done" pages instead of toasts.
const phoneLayout = window.matchMedia('(max-width: 640px), (pointer: coarse) and (max-height: 520px)');

function showSuccessScreen(title, message, type) {
    document.querySelector('.success-screen')?.remove();
    const screen = document.createElement('div');
    screen.className = 'success-screen';
    screen.setAttribute('role', 'alertdialog');
    screen.setAttribute('aria-modal', 'true');
    screen.innerHTML = `<div class="success-screen-body"><span class="success-check success-${type}"></span><h2></h2><p></p><button class="button success-done" type="button">Done</button></div>`;
    screen.querySelector('.success-check').innerHTML = toastIcons[type] || toastIcons.success;
    screen.querySelector('h2').textContent = title;
    const body = screen.querySelector('p');
    body.textContent = message;
    body.hidden = !message;
    const close = () => {
        screen.classList.add('is-leaving');
        window.setTimeout(() => screen.remove(), 220);
    };
    screen.querySelector('.success-done').addEventListener('click', close);
    document.body.append(screen);
    screen.querySelector('.success-done').focus({ preventScroll: true });
}

function showToast(title, message = '', type = 'success') {
    // Errors stay on the page; a status switch is too quick a change to need a whole page.
    if (phoneLayout.matches && type !== 'error' && title !== 'Status Updated') {
        showSuccessScreen(title, message, type);
        return;
    }
    if (!toastRegion) {
        return;
    }
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
    const icon = document.createElement('span');
    icon.className = 'toast-icon';
    icon.innerHTML = toastIcons[type] || toastIcons.success;
    const text = document.createElement('div');
    const heading = document.createElement('strong');
    heading.textContent = title;
    text.append(heading);
    if (message) {
        const body = document.createElement('p');
        body.textContent = message;
        text.append(body);
    }
    toast.append(icon, text);
    toast.addEventListener('click', () => toast.remove());
    toastRegion.append(toast);
    window.setTimeout(() => toast.classList.add('is-leaving'), 4200);
    window.setTimeout(() => toast.remove(), 4600);
}

const pendingToast = document.querySelector('#toast-data');
if (pendingToast) {
    try {
        const data = JSON.parse(pendingToast.textContent);
        showToast(data.title, data.message, data.type);
    } catch {
        // A malformed message is simply not shown.
    }
}

// ---- "Are you sure?" dialog for forms marked data-confirm ----
const confirmDialog = document.querySelector('#confirm-dialog');
let confirmForm = null;
document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!confirmDialog) {
            return;
        }
        event.preventDefault();
        confirmForm = form;
        const safe = form.hasAttribute('data-confirm-safe');
        confirmDialog.querySelector('[data-confirm-title]').textContent = form.dataset.confirmTitle || 'Are you sure?';
        confirmDialog.querySelector('[data-confirm-message]').textContent = form.dataset.confirm;
        const ok = confirmDialog.querySelector('[data-confirm-ok]');
        ok.textContent = form.dataset.confirmButton || 'Delete';
        ok.classList.toggle('button-danger', !safe);
        confirmDialog.querySelector('[data-confirm-icon]').hidden = safe;
        confirmDialog.showModal();
        confirmDialog.querySelector('[data-confirm-cancel]').focus();
    });
});
confirmDialog?.querySelector('[data-confirm-cancel]').addEventListener('click', () => confirmDialog.close());
confirmDialog?.querySelector('[data-confirm-ok]').addEventListener('click', () => {
    confirmDialog.close();
    // submit() skips the submit event, so the dialog does not open again
    confirmForm?.submit();
});
confirmDialog?.addEventListener('click', (event) => {
    if (event.target === confirmDialog) {
        confirmDialog.close();
    }
});

// ---- downloads: the server answers with a cookie holding our token; when it appears, the file was sent ----
function newDownloadToken() {
    return Math.random().toString(36).slice(2, 14);
}

function waitForDownload(token, onDone) {
    const started = Date.now();
    const timer = window.setInterval(() => {
        if (document.cookie.split('; ').includes(`tsv_download=${token}`)) {
            window.clearInterval(timer);
            document.cookie = 'tsv_download=; Max-Age=0; path=/';
            onDone();
        } else if (Date.now() - started > 180000) {
            window.clearInterval(timer);
        }
    }, 400);
}

document.querySelectorAll('form[data-download-toast]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        const token = newDownloadToken();
        form.querySelector('[name="download_token"]').value = token;
        // other handlers may still cancel this submit (missing fields, unsaved Suguan); only then is nothing awaited
        window.setTimeout(() => {
            if (event.defaultPrevented) {
                return;
            }
            waitForDownload(token, () => {
                if (form.hasAttribute('data-download-close')) {
                    form.closest('dialog')?.close();
                }
                showToast(form.dataset.downloadToast, form.dataset.downloadMessage || '');
            });
        }, 0);
    });
});

document.querySelectorAll('a[data-download-toast]').forEach((link) => {
    const base = link.getAttribute('href');
    link.addEventListener('click', () => {
        const token = newDownloadToken();
        link.setAttribute('href', `${base}&download_token=${token}`);
        waitForDownload(token, () => showToast(link.dataset.downloadToast, link.dataset.downloadMessage || ''));
    });
});

const statusForms = document.querySelectorAll('form[data-status-toggle]');
const statusFeedback = document.querySelector('#status-feedback');
const officersBody = document.querySelector('[data-officers-body]');
const statusFilter = document.querySelector('[data-status-filter]');
const activeCount = document.querySelector('[data-active-count]');
const inactiveCount = document.querySelector('[data-inactive-count]');
const searchInput = document.querySelector('input[name="search"]');
const filterForm = document.querySelector('[data-filter-form]');
const generatorDialog = document.querySelector('#print-generator');
const generatorForm = generatorDialog?.querySelector('form');
const openGeneratorButtons = document.querySelectorAll('[data-open-generator]');
const agendaCheckbox = generatorForm?.querySelector('input[value="meeting_agenda"]');
const agendaFields = generatorForm?.querySelector('[data-agenda-fields]');
const agendaInputs = agendaFields?.querySelectorAll(
    '[name="agenda_topics"]'
);
const importDialog = document.querySelector('#import-dialog');
const dbLabel = document.querySelector('[data-db-label]');
const dbDot = document.querySelector('[data-db-dot]');

// Keep the sidebar connection indicator current while the page stays open.
async function checkConnection() {
    let state = 'server';
    try {
        const response = await fetch('index.php?action=ping', { headers: { Accept: 'application/json' }, cache: 'no-store' });
        state = (await response.json()).db === true ? 'online' : 'database';
    } catch {
        state = 'server';
    }
    if (dbLabel && dbDot) {
        dbLabel.textContent = { online: 'Local database', database: 'No connection', server: 'Server offline' }[state];
        dbDot.classList.toggle('is-offline', state !== 'online');
        dbDot.title = {
            online: 'Connected',
            database: 'The database file could not be opened.',
            server: 'The app is not responding. Restart TSV Secretary.',
        }[state];
    }
}

if (dbDot) {
    window.setInterval(checkConnection, 20000);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            checkConnection();
        }
    });
}
const meetingFields = generatorForm?.querySelector('[data-meeting-fields]');

document.querySelector('[data-open-import]')?.addEventListener('click', () => importDialog?.showModal());
importDialog?.querySelectorAll('[data-close-dialog]').forEach((button) => {
    button.addEventListener('click', () => importDialog.close());
});
importDialog?.addEventListener('click', (event) => {
    if (event.target === importDialog) {
        importDialog.close();
    }
});

const dropzone = importDialog?.querySelector('[data-dropzone]');
const importFileInput = importDialog?.querySelector('[data-import-file]');
const dropzoneTitle = importDialog?.querySelector('[data-dropzone-title]');
const dropzoneHint = importDialog?.querySelector('[data-dropzone-hint]');
const importMaxBytes = Number(dropzoneHint?.dataset.maxBytes) || 40 * 1024 * 1024;
const importLimitText = formatFileSize(importMaxBytes);
const importIdleHint = dropzoneHint?.textContent ?? '';

function formatFileSize(bytes) {
    return bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

function showImportFile() {
    const file = importFileInput.files[0];
    let problem = '';
    if (file && !/\.(sql|mysql|txt)$/i.test(file.name)) {
        problem = 'That is not a .sql or .mysql backup file.';
    } else if (file && file.size > importMaxBytes) {
        problem = `That file is larger than ${importLimitText}.`;
    }
    importFileInput.setCustomValidity(problem);
    dropzone.classList.toggle('has-file', Boolean(file) && !problem);
    dropzone.classList.toggle('has-error', Boolean(problem));
    dropzoneTitle.textContent = file ? file.name : 'Drag & drop your backup here';
    dropzoneHint.textContent = problem || (file
        ? `${formatFileSize(file.size)} · click or drop another file to change`
        : importIdleHint);
}

function useDroppedFiles(files) {
    if (!files?.length || !importFileInput) {
        return;
    }
    const transfer = new DataTransfer();
    transfer.items.add(files[0]);
    importFileInput.files = transfer.files;
    showImportFile();
}

importFileInput?.addEventListener('change', showImportFile);

// Dropping a file anywhere on the officers page opens the import dialog with it selected.
const draggingFiles = (event) => [...(event.dataTransfer?.types ?? [])].includes('Files');
if (dropzone) {
    let dragDepth = 0;
    document.addEventListener('dragenter', (event) => {
        if (!draggingFiles(event)) {
            return;
        }
        dragDepth += 1;
        if (!importDialog.open && !generatorDialog?.open) {
            importDialog.showModal();
        }
        dropzone.classList.add('is-dragover');
    });
    document.addEventListener('dragleave', (event) => {
        if (draggingFiles(event) && --dragDepth <= 0) {
            dragDepth = 0;
            dropzone.classList.remove('is-dragover');
        }
    });
    document.addEventListener('dragover', (event) => {
        if (draggingFiles(event)) {
            event.preventDefault();
        }
    });
    document.addEventListener('drop', (event) => {
        if (!draggingFiles(event)) {
            return;
        }
        event.preventDefault();
        dragDepth = 0;
        dropzone.classList.remove('is-dragover');
        if (importDialog.open) {
            useDroppedFiles(event.dataTransfer.files);
        }
    });
}
const monthlyFields = generatorForm?.querySelector('[data-monthly-fields]');
let saveSelectionTimeout;

document.querySelector('[data-theme-toggle]')?.addEventListener('click', () => {
    const theme = document.documentElement.dataset.theme === 'light' ? 'dark' : 'light';
    document.documentElement.dataset.theme = theme;
    try { window.LtsvssAndroid?.setDark(theme === 'dark'); } catch {}
    try {
        localStorage.setItem('theme', theme);
    } catch {
        // The theme still applies for this page view.
    }
});

function openGenerator() {
    if (statusFeedback) {
        statusFeedback.hidden = true;
    }
    generatorDialog?.showModal();
}

openGeneratorButtons.forEach((button) => {
    button.addEventListener('click', openGenerator);
});

if (generatorDialog && window.location.hash === '#generator') {
    window.history.replaceState(null, '', window.location.pathname + window.location.search);
    openGenerator();
}

document.addEventListener('keydown', (event) => {
    const target = event.target;
    const isTyping = target instanceof HTMLElement
        && (target.isContentEditable || ['INPUT', 'SELECT', 'TEXTAREA'].includes(target.tagName));
    if (event.key.toLowerCase() !== 'f' || event.ctrlKey || event.metaKey || event.altKey || isTyping || generatorDialog?.open) {
        return;
    }
    if (searchInput) {
        event.preventDefault();
        searchInput.focus();
        searchInput.select();
    }
});

function isFormChecked(formKey) {
    return generatorForm?.querySelector(`input[name="print_forms[]"][value="${formKey}"]`)?.checked === true;
}

function toggleFieldGroup(group, enabled, inputs) {
    if (group) {
        group.hidden = !enabled;
    }
    inputs?.forEach((input) => {
        input.required = enabled;
    });
}

function updateAgendaFields() {
    toggleFieldGroup(agendaFields, agendaCheckbox?.checked === true, agendaInputs);
    toggleFieldGroup(
        meetingFields,
        isFormChecked('weekly_attendance') || isFormChecked('meeting_agenda'),
        meetingFields?.querySelectorAll('input')
    );
    generatorForm?.querySelectorAll('[data-shown-for]').forEach((group) => {
        group.hidden = !isFormChecked(group.dataset.shownFor);
    });
    const monthlyKeys = ['monthly_attendance', 'tsv_form3', 'directory_report'];
    if (monthlyFields) {
        monthlyFields.hidden = !monthlyKeys.some(isFormChecked);
        const webexCount = monthlyFields.querySelector('[name="webex_count"]');
        monthlyFields.querySelectorAll('[data-only-for]').forEach((field) => {
            // The Webex reason shows only when it was not used; the activities only when it was.
            const webexUsed = Number(webexCount?.value || 0) > 0;
            const recruitCount = Number(monthlyFields.querySelector('[name="recruit_count"]')?.value || 0);
            const webexWhen = field.dataset.webexWhen;
            const directoryOn = generatorForm.querySelector('[data-dir-toggle]')?.checked === true;
            const enabled = isFormChecked(field.dataset.onlyFor)
                && (!field.hasAttribute('data-dir-detail') || directoryOn)
                && (!webexWhen || (webexWhen === 'used') === webexUsed)
                && (!field.hasAttribute('data-recruit-list') || recruitCount > 0);
            field.hidden = !enabled;
            field.querySelectorAll('[data-required]').forEach((input) => {
                input.required = enabled;
            });
        });
    }
}

generatorForm?.querySelectorAll('input[name="print_forms[]"]').forEach((checkbox) => {
    checkbox.addEventListener('change', updateAgendaFields);
});
generatorForm?.querySelector('[name="webex_count"]')?.addEventListener('input', updateAgendaFields);

// Row lists whose length follows a number field: one activity per Webex use, one row per recruit.
function syncRows(container) {
    const countInput = generatorForm.querySelector(`[name="${container.dataset.rows}"]`);
    const wanted = Math.min(Number(container.dataset.maxRows), Math.max(1, Math.floor(Number(countInput?.value || 0))));
    let rows = container.querySelectorAll('[data-row]');
    while (rows.length < wanted) {
        const row = rows[0].cloneNode(true);
        row.querySelectorAll('input').forEach((input) => {
            input.value = '';
        });
        container.append(row);
        rows = container.querySelectorAll('[data-row]');
    }
    for (let index = rows.length - 1; index >= wanted; index--) {
        rows[index].remove();
    }
}

generatorForm?.querySelectorAll('[data-rows]').forEach((container) => {
    generatorForm.querySelector(`[name="${container.dataset.rows}"]`)?.addEventListener('input', () => {
        syncRows(container);
        updateAgendaFields();
    });
    syncRows(container);
});
// Rows added above start without "required"; refresh so every shown row must be filled in.
updateAgendaFields();
updateAgendaFields();

const generatorIsPage = generatorDialog?.classList.contains('generator-inline');
generatorDialog?.querySelectorAll('[data-close-generator]').forEach((button) => {
    button.addEventListener('click', () => (generatorIsPage ? window.location.assign('index.php') : generatorDialog.close()));
});

generatorDialog?.addEventListener('click', (event) => {
    if (event.target === generatorDialog && !generatorIsPage) {
        generatorDialog.close();
    }
});

generatorForm?.querySelectorAll('input[name="print_forms[]"]:not(:disabled)').forEach((checkbox) => {
    checkbox.addEventListener('change', () => {
        window.clearTimeout(saveSelectionTimeout);
        saveSelectionTimeout = window.setTimeout(async () => {
            const formData = new FormData(generatorForm);
            formData.set('action', 'save_print_selection');

            try {
                const response = await fetch(generatorForm.getAttribute('action'), {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: formData,
                });
                const responseText = await response.text();
                let result;
                try {
                    result = JSON.parse(responseText);
                } catch {
                    throw new Error(
                        `The server did not return a valid print-selection response (HTTP ${response.status}).`
                    );
                }
                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Could not save print selections.');
                }
                if (statusFeedback) {
                    statusFeedback.hidden = true;
                }
            } catch (error) {
                if (statusFeedback) {
                    statusFeedback.textContent = error instanceof Error
                        ? error.message
                        : 'Could not save print selections.';
                    statusFeedback.hidden = false;
                }
            }
        }, 250);
    });
});

generatorForm?.addEventListener('submit', (event) => {
    const selected = generatorForm.querySelector(
        'input[name="print_forms[]"]:not(:disabled):checked'
    );
    if (!selected) {
        event.preventDefault();
        if (statusFeedback) {
            statusFeedback.textContent = 'Select at least one available form to generate.';
            statusFeedback.hidden = false;
        }
    }
});

statusFilter?.addEventListener('change', () => {
    filterForm?.requestSubmit();
});

function updateOfficerCounts(becameActive) {
    if (!activeCount || !inactiveCount) {
        return;
    }

    const change = becameActive ? 1 : -1;
    activeCount.textContent = String(Number(activeCount.textContent) + change);
    inactiveCount.textContent = String(Number(inactiveCount.textContent) - change);
}

function updateEmptyState() {
    if (!officersBody || officersBody.querySelector('[data-officer-row]')) {
        return;
    }

    const emptyRow = document.createElement('tr');
    const emptyCell = document.createElement('td');
    const filter = statusFilter?.value ?? 'all';
    const hasSearch = Boolean(searchInput?.value.trim());
    emptyCell.className = 'empty-state';
    emptyCell.colSpan = 9;

    if (filter === 'active') {
        emptyCell.textContent = hasSearch
            ? 'No active officers match your search.'
            : 'No active officers found.';
    } else if (filter === 'inactive') {
        emptyCell.textContent = hasSearch
            ? 'No inactive officers match your search.'
            : 'No inactive officers found.';
    } else {
        emptyCell.textContent = hasSearch
            ? 'No officers match your search.'
            : 'No officer records yet. Add an officer to get started.';
    }

    emptyRow.append(emptyCell);
    officersBody.replaceChildren(emptyRow);
}

statusForms.forEach((form) => {
    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const button = form.querySelector('button[type="submit"]');
        const row = form.closest('[data-officer-row]');
        if (!button || !row || !statusFeedback) {
            return;
        }

        statusFeedback.hidden = true;
        button.disabled = true;

        try {
            const response = await fetch(form.getAttribute('action'), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: new FormData(form),
            });
            const responseText = await response.text();
            let result;
            try {
                result = JSON.parse(responseText);
            } catch {
                throw new Error(
                    `The server did not return a valid status response (HTTP ${response.status}). Check the PHP error log and reload the page.`
                );
            }

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Could not update officer status. Please try again.');
            }
            if (result.is_active !== 0 && result.is_active !== 1) {
                throw new Error('The server returned an invalid officer status.');
            }

            const isActive = result.is_active === 1;
            const changed = (row.dataset.active === '1') !== isActive;
            row.dataset.active = isActive ? '1' : '0';
            button.textContent = isActive ? 'Active' : 'Inactive';
            button.classList.toggle('status-active', isActive);
            button.classList.toggle('status-inactive', !isActive);

            const name = row.querySelector('.name-cell')?.textContent.trim() || 'officer';
            showToast('Status Updated', `${name} is now ${isActive ? 'Active' : 'Inactive'}.`);
            button.setAttribute(
                'aria-label',
                `Set ${name} to ${isActive ? 'inactive' : 'active'}`
            );

            const filter = statusFilter?.value ?? 'all';
            if ((filter === 'active' && !isActive) || (filter === 'inactive' && isActive)) {
                row.remove();
                updateEmptyState();
            }
            if (changed) {
                updateOfficerCounts(isActive);
            }
        } catch (error) {
            statusFeedback.textContent = error instanceof Error
                ? error.message
                : 'Could not update officer status. Please try again.';
            statusFeedback.hidden = false;
        } finally {
            button.disabled = false;
        }
    });
});

// ---- polish: picture fade-in, scroll reveal, count-up ----
const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// Pictures get "is-loaded" when they arrive, which ends their skeleton shimmer and fades them in.
const markLoaded = (img) => img.classList.add('is-loaded');
document.addEventListener('load', (event) => {
    if (event.target instanceof HTMLImageElement) markLoaded(event.target);
}, true);
document.addEventListener('error', (event) => {
    if (event.target instanceof HTMLImageElement) markLoaded(event.target);
}, true);
document.querySelectorAll('img').forEach((img) => {
    if (img.complete) markLoaded(img);
});

// Elements given to revealOnScroll() rise in the first time they come into view.
const revealObserver = 'IntersectionObserver' in window && !prefersReducedMotion
    ? new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-revealed');
                revealObserver.unobserve(entry.target);
            }
        });
    }, { rootMargin: '0px 0px -6% 0px', threshold: 0.06 })
    : null;
window.revealOnScroll = (elements) => {
    elements.forEach((element) => {
        if (!revealObserver) return;
        element.classList.add('reveal');
        revealObserver.observe(element);
    });
};

// The officer totals count up from zero when the page opens.
if (!prefersReducedMotion) {
    document.querySelectorAll('.stat-card strong').forEach((counter) => {
        const target = Number(counter.textContent.trim());
        if (!Number.isFinite(target) || target <= 0) return;
        const started = performance.now();
        const duration = 900;
        counter.textContent = '0';
        const tick = (now) => {
            const progress = Math.min(1, (now - started) / duration);
            const eased = 1 - (1 - progress) ** 4;
            counter.textContent = String(Math.round(target * eased));
            if (progress < 1) requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
    });
}

// Segmented tabs (e.g. Timeline / Horizontal / Gallery / Calendar) get a highlight that slides to the active tab.
window.syncSegmented = (group, instant = false) => {
    let indicator = group.querySelector('.segmented-indicator');
    if (!indicator) {
        indicator = document.createElement('span');
        indicator.className = 'segmented-indicator';
        indicator.setAttribute('aria-hidden', 'true');
        group.prepend(indicator);
        group.classList.add('has-indicator');
        instant = true;
        window.addEventListener('resize', () => window.syncSegmented(group, true));
    }
    const active = group.querySelector('.is-active');
    if (!active) return;
    group.classList.toggle('is-instant', instant);
    indicator.style.width = `${active.offsetWidth}px`;
    indicator.style.transform = `translateX(${active.offsetLeft}px)`;
    if (instant) requestAnimationFrame(() => group.classList.remove('is-instant'));
};

// ---- The form generator page (phones): two steps, choose the forms, then fill in what they need ----
if (generatorIsPage && generatorForm) {
    const body = generatorForm.querySelector('.generator-body');
    const groups = body.querySelector('.generator-groups');
    const actions = generatorForm.querySelector('.panel-footer .form-actions');
    const boxes = [...generatorForm.querySelectorAll('input[name="print_forms[]"]:not(:disabled)')];
    const checkedBoxes = () => boxes.filter((box) => box.checked);
    const labelOf = (box) => box.closest('label').querySelector('span').childNodes[0].textContent.trim();

    const stepper = document.createElement('div');
    stepper.className = 'gen-steps';
    stepper.innerHTML = '<button type="button" data-goto="1"><b>1</b>Choose forms</button><i aria-hidden="true"></i><button type="button" data-goto="2"><b>2</b>Fill in details</button>';
    body.prepend(stepper);

    const chosen = document.createElement('div');
    chosen.className = 'gen-chosen';
    groups.after(chosen);
    const nothing = document.createElement('p');
    nothing.className = 'gen-nothing';
    nothing.textContent = 'These forms need no details. Tap Generate to make them.';
    chosen.after(nothing);

    const back = document.createElement('button');
    back.type = 'button';
    back.className = 'button button-light gen-back';
    back.textContent = 'Back';
    const next = document.createElement('button');
    next.type = 'button';
    next.className = 'button gen-next';
    actions.prepend(back);
    actions.append(next);

    const detailSections = ['[data-suguan-builder]', '[data-meeting-fields]', '[data-agenda-fields]', '[data-forms-resumen-fields]', '[data-monthly-fields]']
        .map((selector) => generatorForm.querySelector(selector))
        .filter(Boolean);

    const refresh = () => {
        const picked = checkedBoxes();
        next.disabled = picked.length === 0;
        next.textContent = picked.length ? `Next · ${picked.length} ${picked.length === 1 ? 'form' : 'forms'}` : 'Choose a form';
        const change = document.createElement('button');
        change.type = 'button';
        change.className = 'gen-change';
        change.textContent = 'Change';
        change.addEventListener('click', () => setStep(1));
        const chips = picked.map((box) => {
            const chip = document.createElement('span');
            chip.className = 'gen-chip';
            chip.textContent = labelOf(box);
            return chip;
        });
        chosen.replaceChildren(...chips, change);
        nothing.hidden = detailSections.some((section) => !section.hidden);
    };
    function setStep(step) {
        if (step === 2 && checkedBoxes().length === 0) {
            return;
        }
        generatorForm.dataset.step = String(step);
        stepper.querySelectorAll('[data-goto]').forEach((button) => {
            button.setAttribute('aria-current', button.dataset.goto === String(step) ? 'step' : 'false');
        });
        refresh();
        window.scrollTo({ top: 0 });
    }
    boxes.forEach((box) => box.addEventListener('change', () => window.setTimeout(refresh, 0)));
    stepper.querySelectorAll('[data-goto]').forEach((button) => button.addEventListener('click', () => setStep(Number(button.dataset.goto))));
    next.addEventListener('click', () => setStep(2));
    back.addEventListener('click', () => setStep(1));
    // After a failed Generate the page comes back with the problems listed: show them with the details.
    setStep(document.querySelector('.content > .alert-error:not([hidden])') && checkedBoxes().length ? 2 : 1);
}

// ---- Directory Report: "Update data in the INC Directory" shows the form; changes from the Directory are marked ----
const directoryToggle = generatorForm?.querySelector('[data-dir-toggle]');
const directoryList = generatorForm?.querySelector('[data-dir-baseline]');
if (directoryToggle && directoryList) {
    directoryToggle.addEventListener('change', updateAgendaFields);
    const baseline = JSON.parse(directoryList.dataset.dirBaseline || '[]');
    const known = new Set(baseline.map(([day, time, lang]) => `${day}|${time}|${lang}`));
    const timeText = (value) => {
        const [h, m] = (value || '').split(':').map(Number);
        return Number.isFinite(h) ? `${((h + 11) % 12) + 1}:${String(m).padStart(2, '0')}${h < 12 ? 'AM' : 'PM'}` : value;
    };
    const markChanges = () => {
        directoryList.querySelectorAll('[data-dir-row]').forEach((row, index) => {
            const day = row.querySelector('[name="dir_day[]"]').value;
            const time = row.querySelector('[name="dir_time[]"]').value;
            const lang = row.querySelector('[name="dir_lang[]"]').value;
            const changed = !known.has(`${day}|${time}|${lang}`);
            row.classList.toggle('is-changed', changed);
            const was = row.querySelector('.dir-was');
            const before = baseline[index];
            was.hidden = !changed;
            was.textContent = before ? `Was: ${before[0]} ${timeText(before[1])} ${before[2]}` : 'New';
        });
        generatorForm.querySelectorAll('[data-baseline]').forEach((input) => {
            input.closest('label').classList.toggle('is-changed', input.value.trim() !== input.dataset.baseline.trim());
        });
    };
    generatorForm.addEventListener('input', (event) => {
        if (event.target.closest('[data-dir-detail]')) markChanges();
    });
    generatorForm.addEventListener('change', (event) => {
        if (event.target.closest('[data-dir-detail]')) markChanges();
    });
    markChanges();
}

