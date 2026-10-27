document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
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
    '[name="first_prayer"], [name="last_prayer"], [name="agenda_topics"]'
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
        dbLabel.textContent = { online: 'Local MySQL', database: 'No connection', server: 'Server offline' }[state];
        dbDot.classList.toggle('is-offline', state !== 'online');
        dbDot.title = {
            online: 'Connected',
            database: 'MySQL is not reachable. Start it in the XAMPP Control Panel.',
            server: 'Apache is not responding. Start it in the XAMPP Control Panel.',
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
const importMaxBytes = 5 * 1024 * 1024;

function formatFileSize(bytes) {
    return bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

function showImportFile() {
    const file = importFileInput.files[0];
    let problem = '';
    if (file && !/\.(sql|mysql|txt)$/i.test(file.name)) {
        problem = 'That is not a .sql or .mysql backup file.';
    } else if (file && file.size > importMaxBytes) {
        problem = 'That file is larger than 5 MB.';
    }
    importFileInput.setCustomValidity(problem);
    dropzone.classList.toggle('has-file', Boolean(file) && !problem);
    dropzone.classList.toggle('has-error', Boolean(problem));
    dropzoneTitle.textContent = file ? file.name : 'Drag & drop your backup here';
    dropzoneHint.textContent = problem || (file
        ? `${formatFileSize(file.size)} · click or drop another file to change`
        : 'or click to browse · .sql or .mysql, up to 5 MB');
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
            const enabled = isFormChecked(field.dataset.onlyFor)
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

generatorDialog?.querySelectorAll('[data-close-generator]').forEach((button) => {
    button.addEventListener('click', () => generatorDialog.close());
});

generatorDialog?.addEventListener('click', (event) => {
    if (event.target === generatorDialog) {
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
