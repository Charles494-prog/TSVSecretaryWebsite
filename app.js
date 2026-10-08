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
const totalCount = document.querySelector('[data-total-count]');
const activeCount = document.querySelector('[data-active-count]');
const searchInput = document.querySelector('input[name="search"]');
const filterForm = document.querySelector('[data-filter-form]');
const generatorDialog = document.querySelector('#print-generator');
const generatorForm = generatorDialog?.querySelector('form');
const openGeneratorButton = document.querySelector('[data-open-generator]');
let saveSelectionTimeout;

openGeneratorButton?.addEventListener('click', () => {
    generatorDialog?.showModal();
});

generatorDialog?.querySelectorAll('[data-close-generator]').forEach((button) => {
    button.addEventListener('click', () => generatorDialog.close());
});

generatorDialog?.addEventListener('click', (event) => {
    if (event.target === generatorDialog) {
        generatorDialog.close();
    }
});

generatorForm?.querySelectorAll('input[name="print_forms[]"]').forEach((checkbox) => {
    checkbox.addEventListener('change', () => {
        window.clearTimeout(saveSelectionTimeout);
        saveSelectionTimeout = window.setTimeout(async () => {
            const formData = new FormData(generatorForm);
            formData.set('action', 'save_print_selection');

            try {
                const response = await fetch(generatorForm.action, {
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

statusFilter?.addEventListener('change', () => {
    filterForm?.requestSubmit();
});

function updateOfficerCounts() {
    if (!officersBody || !totalCount || !activeCount) {
        return;
    }

    const rows = officersBody.querySelectorAll('[data-officer-row]');
    totalCount.textContent = String(rows.length);
    activeCount.textContent = String(
        [...rows].filter((row) => row.dataset.active === '1').length
    );
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
            const response = await fetch(form.action, {
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
            updateOfficerCounts();
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
