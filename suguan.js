const suguanData = JSON.parse(document.querySelector('#suguan-data').textContent);
const serviceList = document.querySelector('[data-service-list]');
const chipList = document.querySelector('[data-officer-chips]');
const pickerHint = document.querySelector('[data-picker-hint]');
const saveState = document.querySelector('[data-save-state]');
const suguanDialog = document.querySelector('#print-generator');
const suguanForm = suguanDialog.querySelector('form');
const builder = document.querySelector('[data-suguan-builder]');
const suguanCheckbox = suguanForm.querySelector('input[name="print_forms[]"][value="suguan"]');
const weekInput = document.querySelector('[data-suguan-week-input]');
const weekLabel = document.querySelector('[data-week-label]');
const weekMeta = document.querySelector('[data-week-meta]');
const currentWeekButton = document.querySelector('[data-week-step="current"]');
const ewsTypes = ['katandaan', 'pnk'];
const roles = [
    ['computer', 'Computer Operator'],
    ['video', 'Video Operator'],
    ['audio', 'Audio Operator'],
];
const icons = {
    grip: '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>',
    up: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m18 15-6-6-6 6"/></svg>',
    down: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>',
    trash: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg>',
    x: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>',
    plus: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>',
    edit: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>',
};

// On a phone each service card shows only its day, time and roles; Edit opens its date, time and type.
// The open cards are remembered here because the list is redrawn after every change.
const editingIds = new Set();

let services = suguanData.services;
let selectedName = null;
let armedSlot = null;
let saveTimeout;
let savePromise = Promise.resolve();
let dirty = false;
// The phone layout (the Android app): tap instead of click, and no Esc key.
const touchLayout = () => window.matchMedia('(max-width: 640px), (pointer: coarse) and (max-height: 520px)').matches;

function newId() {
    return Math.random().toString(36).slice(2, 14);
}

function parseDate(value) {
    return new Date(`${value}T00:00:00`);
}

function formatTime(value) {
    const [hours, minutes] = value.split(':').map(Number);
    if (Number.isNaN(hours)) {
        return '';
    }
    return `${((hours + 11) % 12) + 1}:${String(minutes).padStart(2, '0')} ${hours < 12 ? 'AM' : 'PM'}`;
}

function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) {
        node.className = className;
    }
    if (text !== undefined) {
        node.textContent = text;
    }
    return node;
}

function iconButton(icon, label, className = 'icon-button icon-button-small') {
    const button = element('button', className);
    button.type = 'button';
    button.innerHTML = icon;
    button.setAttribute('aria-label', label);
    button.title = label;
    return button;
}

function setSaveState(text, state = '') {
    saveState.textContent = text;
    saveState.dataset.state = state;
}

function save() {
    window.clearTimeout(saveTimeout);
    if (!dirty) {
        return savePromise;
    }
    dirty = false;
    setSaveState('Saving…', 'saving');
    const body = new FormData();
    body.set('csrf_token', suguanData.csrf);
    body.set('action', 'save_suguan');
    body.set('week', suguanData.week);
    body.set('services', JSON.stringify(services));

    savePromise = savePromise.then(async () => {
        try {
            const response = await fetch('index.php', {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body,
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok || !result.success) {
                throw new Error(result.message || `Could not save (HTTP ${response.status}).`);
            }
            setSaveState(dirty ? 'Unsaved changes' : 'Saved', dirty ? '' : 'saved');
            return true;
        } catch (error) {
            dirty = true;
            setSaveState(error.message, 'error');
            return false;
        }
    });
    return savePromise;
}

function changed({ rerender = true } = {}) {
    dirty = true;
    setSaveState('Unsaved changes');
    window.clearTimeout(saveTimeout);
    saveTimeout = window.setTimeout(save, 600);
    if (rerender) {
        render();
    }
}

function assign(serviceId, role, name) {
    const service = services.find((item) => item.id === serviceId);
    if (!service) {
        return;
    }
    service[role] = name;
    changed();
}

function updateHint() {
    if (selectedName) {
        pickerHint.textContent = touchLayout()
            ? `Tap a role to assign ${selectedName}. Tap the name again to stop.`
            : `Click a role to assign ${selectedName}. Press Esc to stop.`;
    } else if (armedSlot) {
        pickerHint.textContent = 'Now click a name to fill the highlighted role.';
    } else {
        pickerHint.textContent = touchLayout() ? 'Tap a name, then tap a role below.' : 'Pick a name, then click a role below.';
    }
}

function renderChips() {
    const counts = {};
    services.forEach((service) => roles.forEach(([role]) => {
        if (service[role]) {
            counts[service[role]] = (counts[service[role]] || 0) + 1;
        }
    }));

    chipList.replaceChildren(...suguanData.officers.map((name) => {
        const chip = element('button', 'chip');
        chip.type = 'button';
        chip.draggable = true;
        chip.classList.toggle('is-selected', name === selectedName);
        chip.setAttribute('aria-pressed', String(name === selectedName));
        chip.append(element('span', '', name));
        chip.append(element('span', 'chip-count', String(counts[name] || 0)));
        chip.addEventListener('click', () => {
            if (armedSlot) {
                assign(armedSlot.id, armedSlot.role, name);
                armedSlot = null;
            } else {
                selectedName = selectedName === name ? null : name;
                render();
            }
        });
        chip.addEventListener('dragstart', (event) => {
            event.dataTransfer.setData('text/officer', name);
            event.dataTransfer.effectAllowed = 'copy';
        });
        return chip;
    }));
    if (!suguanData.officers.length) {
        chipList.append(element('p', 'muted', 'No active officers yet. Add officers on the Overview page.'));
    }
}

function renderRoleSlot(service, role, label) {
    const slot = element('div', 'role-slot');
    const name = service[role];
    const others = roles.filter(([other]) => other !== role).map(([other]) => service[other]);
    slot.classList.toggle('is-filled', Boolean(name));
    slot.classList.toggle('is-armed', armedSlot?.id === service.id && armedSlot?.role === role);
    slot.classList.toggle('is-match', Boolean(name) && name === selectedName);
    slot.classList.toggle('is-conflict', Boolean(name) && others.includes(name));

    const assignButton = element('button', 'role-assign');
    assignButton.type = 'button';
    assignButton.append(element('span', 'role-label', label));
    assignButton.append(element('span', 'role-name', name || 'Assign'));
    assignButton.addEventListener('click', () => {
        if (touchLayout()) {
            openOfficerSheet(service, role, label);
            return;
        }
        if (selectedName) {
            assign(service.id, role, selectedName);
            return;
        }
        const isArmed = armedSlot?.id === service.id && armedSlot?.role === role;
        armedSlot = isArmed ? null : { id: service.id, role };
        render();
    });
    slot.append(assignButton);

    if (name) {
        const clear = iconButton(icons.x, `Clear ${label}`, 'role-clear');
        clear.addEventListener('click', () => assign(service.id, role, ''));
        slot.append(clear);
    }

    slot.addEventListener('dragover', (event) => {
        if (event.dataTransfer.types.includes('text/officer')) {
            event.preventDefault();
            slot.classList.add('is-drop');
        }
    });
    slot.addEventListener('dragleave', () => slot.classList.remove('is-drop'));
    slot.addEventListener('drop', (event) => {
        const officer = event.dataTransfer.getData('text/officer');
        if (officer) {
            event.preventDefault();
            assign(service.id, role, officer);
        }
    });
    return slot;
}

// ---- Phones: tap a role, then pick the officer from a list that slides up from the bottom ----
let openSheet = null;

function closeOfficerSheet({ fromHistory = false } = {}) {
    if (!openSheet) {
        return;
    }
    const sheet = openSheet;
    openSheet = null;
    sheet.classList.add('is-leaving');
    window.setTimeout(() => sheet.remove(), 200);
    document.body.classList.remove('has-sheet');
    // The sheet added a history step so Android's Back button closes it; undo that step.
    if (!fromHistory && window.history.state?.officerSheet) {
        window.history.back();
    }
}

window.addEventListener('popstate', () => closeOfficerSheet({ fromHistory: true }));

function openOfficerSheet(service, role, label) {
    closeOfficerSheet();
    const counts = {};
    services.forEach((item) => roles.forEach(([other]) => {
        if (item[other]) {
            counts[item[other]] = (counts[item[other]] || 0) + 1;
        }
    }));
    const current = service[role];
    const date = parseDate(service.date);
    const when = Number.isNaN(date.getTime())
        ? formatTime(service.time)
        : `${date.toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric' })} · ${formatTime(service.time)}`;

    const sheet = element('div', 'officer-sheet');
    sheet.setAttribute('role', 'dialog');
    sheet.setAttribute('aria-modal', 'true');
    sheet.setAttribute('aria-label', `Choose the ${label} operator`);
    const backdrop = element('div', 'officer-sheet-backdrop');
    backdrop.addEventListener('click', () => closeOfficerSheet());
    const panel = element('div', 'officer-sheet-panel');
    const head = element('div', 'officer-sheet-head');
    head.append(element('strong', '', label), element('span', 'muted', when));
    const list = element('div', 'officer-sheet-list');

    suguanData.officers.forEach((name) => {
        const row = element('button', 'officer-sheet-row');
        row.type = 'button';
        const isCurrent = name === current;
        row.classList.toggle('is-current', isCurrent);
        const otherRole = roles.find(([other]) => other !== role && service[other] === name);
        const text = element('span', 'officer-sheet-name');
        text.append(element('span', '', name));
        const detail = [`${counts[name] || 0} ${counts[name] === 1 ? 'duty' : 'duties'} this week`];
        if (otherRole) {
            detail.push(`already ${otherRole[1]} here`);
        }
        text.append(element('small', otherRole ? 'is-warning' : 'muted', detail.join(' · ')));
        row.append(text);
        if (isCurrent) {
            row.append(element('span', 'officer-sheet-check', '✓'));
        }
        row.addEventListener('click', () => {
            closeOfficerSheet();
            assign(service.id, role, name);
        });
        list.append(row);
    });
    if (!suguanData.officers.length) {
        list.append(element('p', 'muted officer-sheet-empty', 'No active officers yet. Add officers on the Overview page.'));
    }

    const actions = element('div', 'officer-sheet-actions');
    if (current) {
        const clear = element('button', 'button button-light', 'Leave empty');
        clear.type = 'button';
        clear.addEventListener('click', () => {
            closeOfficerSheet();
            assign(service.id, role, '');
        });
        actions.append(clear);
    }
    const cancel = element('button', 'button button-light', 'Cancel');
    cancel.type = 'button';
    cancel.addEventListener('click', () => closeOfficerSheet());
    actions.append(cancel);

    panel.append(element('span', 'officer-sheet-grip'), head, list, actions);
    sheet.append(backdrop, panel);
    document.body.append(sheet);
    document.body.classList.add('has-sheet');
    openSheet = sheet;
    window.history.pushState({ officerSheet: true }, '');
    list.querySelector('.is-current')?.scrollIntoView({ block: 'center' });
}

function addServiceAfter(index) {
    const reference = services[index] ?? services[services.length - 1];
    const service = {
        id: newId(),
        date: reference?.date ?? suguanData.week,
        time: reference?.time ?? '08:00',
        type: 'pamamahayag',
        ews: false,
        regular: false,
        computer: '',
        video: '',
        audio: '',
    };
    services.splice(index + 1, 0, service);
    editingIds.add(service.id);
    changed();
    serviceList.querySelector(`[data-id="${service.id}"] input[type="time"]`)?.focus();
}

function moveService(fromIndex, toIndex) {
    if (toIndex < 0 || toIndex >= services.length || fromIndex === toIndex) {
        return;
    }
    const [service] = services.splice(fromIndex, 1);
    services.splice(toIndex, 0, service);
    changed();
}

function renderService(service, index) {
    const card = element('article', 'service-card');
    card.dataset.id = service.id;
    card.classList.toggle('is-worship', service.type === 'katandaan' || service.ews);
    card.classList.toggle('is-ews', service.ews);

    const head = element('div', 'service-head');
    const handle = iconButton(icons.grip, 'Drag to move', 'drag-handle');
    handle.addEventListener('pointerdown', (event) => startReorder(event, service.id, card, handle));
    handle.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
            event.preventDefault();
            moveService(index, index + (event.key === 'ArrowUp' ? -1 : 1));
            serviceList.querySelector(`[data-id="${service.id}"] .drag-handle`)?.focus();
        }
    });
    head.append(handle);

    const date = parseDate(service.date);
    const title = element('div', 'service-title');
    title.append(element('strong', '', Number.isNaN(date.getTime())
        ? 'Choose a date'
        : date.toLocaleDateString('en-US', { weekday: 'long' })));
    title.append(element('span', 'muted mono', Number.isNaN(date.getTime())
        ? ''
        : `${date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} · ${formatTime(service.time)}`));
    head.append(title);

    const badges = element('div', 'service-badges');
    badges.append(element('span', `type-badge type-${service.type}`, suguanData.types[service.type]));
    if (service.ews) {
        badges.append(element('span', 'type-badge type-ews', 'EWS'));
    }
    if (!service.regular) {
        badges.append(element('span', 'type-badge type-once', 'This week only'));
    }
    head.append(badges);

    const actions = element('div', 'service-actions');
    card.classList.toggle('is-editing', editingIds.has(service.id));
    const edit = iconButton(icons.edit, 'Edit the date, time and type', 'icon-button icon-button-small service-edit');
    edit.setAttribute('aria-expanded', String(editingIds.has(service.id)));
    edit.addEventListener('click', () => {
        const open = !editingIds.has(service.id);
        if (open) {
            editingIds.add(service.id);
        } else {
            editingIds.delete(service.id);
        }
        card.classList.toggle('is-editing', open);
        edit.setAttribute('aria-expanded', String(open));
    });
    actions.append(edit);
    const remove = iconButton(icons.trash, 'Delete service', 'icon-button icon-button-small icon-button-danger');
    remove.addEventListener('click', () => {
        if (window.confirm('Delete this service from the Suguan?')) {
            services.splice(index, 1);
            changed();
        }
    });
    actions.append(remove);
    head.append(actions);
    card.append(head);

    const fields = element('div', 'service-fields');
    const dateInput = element('input');
    dateInput.type = 'date';
    dateInput.value = service.date;
    dateInput.required = true;
    dateInput.setAttribute('aria-label', 'Date');
    dateInput.addEventListener('change', () => {
        if (dateInput.value) {
            service.date = dateInput.value;
            changed();
        }
    });
    const timeInput = element('input');
    timeInput.type = 'time';
    timeInput.value = service.time;
    timeInput.required = true;
    timeInput.setAttribute('aria-label', 'Time');
    timeInput.addEventListener('change', () => {
        if (timeInput.value) {
            service.time = timeInput.value;
            changed();
        }
    });
    const typeSelect = element('select');
    typeSelect.setAttribute('aria-label', 'Type of gathering');
    Object.entries(suguanData.types).forEach(([value, label]) => {
        const option = element('option', '', label);
        option.value = value;
        option.selected = value === service.type;
        typeSelect.append(option);
    });
    typeSelect.addEventListener('change', () => {
        service.type = typeSelect.value;
        if (!ewsTypes.includes(service.type)) {
            service.ews = false;
        }
        changed();
    });
    fields.append(dateInput, timeInput, typeSelect);

    const toggles = element('div', 'service-toggles');
    if (ewsTypes.includes(service.type)) {
        toggles.append(toggleSwitch('EWS', service.ews, (checked) => {
            service.ews = checked;
            changed();
        }));
    }
    toggles.append(toggleSwitch('Weekly', service.regular, (checked) => {
        service.regular = checked;
        changed();
    }));
    fields.append(toggles);
    card.append(fields);

    const slots = element('div', 'role-slots');
    roles.forEach(([role, label]) => slots.append(renderRoleSlot(service, role, label)));
    card.append(slots);

    return card;
}

function toggleSwitch(label, checked, onChange) {
    const wrapper = element('label', 'switch');
    const input = element('input');
    input.type = 'checkbox';
    input.checked = checked;
    input.addEventListener('change', () => onChange(input.checked));
    wrapper.append(input, element('span', 'switch-track'), element('span', 'switch-label', label));
    return wrapper;
}

function renderInsertRow(index) {
    const row = element('div', 'insert-row');
    const button = element('button', 'insert-button');
    button.type = 'button';
    button.innerHTML = `${icons.plus}<span>Add service here</span>`;
    button.addEventListener('click', () => addServiceAfter(index));
    row.append(button);
    return row;
}

// ---- Reordering: press the grip and drag the service to where it belongs ----
// The card follows the pointer, a dashed gap shows where it will land, and the page scrolls near the edges.
function startReorder(event, serviceId, card, handle) {
    if (event.button !== 0 && event.pointerType === 'mouse') {
        return;
    }
    event.preventDefault();
    const box = card.getBoundingClientRect();
    const offsetY = event.clientY - box.top;
    const gap = element('div', 'service-drop-gap');
    gap.style.height = `${box.height}px`;
    card.after(gap);
    Object.assign(card.style, { position: 'fixed', left: `${box.left}px`, top: `${box.top}px`, width: `${box.width}px`, zIndex: '60' });
    card.classList.add('is-lifted');
    serviceList.classList.add('is-reordering');
    document.body.classList.add('is-reordering');
    try {
        handle.setPointerCapture(event.pointerId);
    } catch {
        // Only real pointers can be captured.
    }

    let lastY = event.clientY;
    let scrollTimer = null;
    const placeGap = () => {
        const cards = [...serviceList.querySelectorAll('.service-card:not(.is-lifted)')];
        const before = cards.find((other) => {
            const rect = other.getBoundingClientRect();
            return lastY < rect.top + rect.height / 2;
        });
        if (before) {
            if (gap.nextElementSibling !== before) {
                before.before(gap);
            }
        } else if (cards.length && gap.previousElementSibling !== cards[cards.length - 1]) {
            cards[cards.length - 1].after(gap);
        }
    };
    const edgeScroll = () => {
        const edge = 90;
        const bottomBar = touchLayout() ? 130 : 0;
        let speed = 0;
        if (lastY < edge) {
            speed = -Math.ceil((edge - lastY) / 6);
        } else if (lastY > window.innerHeight - edge - bottomBar) {
            speed = Math.ceil((lastY - (window.innerHeight - edge - bottomBar)) / 6);
        }
        if (speed) {
            window.scrollBy(0, speed);
            placeGap();
        }
        scrollTimer = window.requestAnimationFrame(edgeScroll);
    };
    scrollTimer = window.requestAnimationFrame(edgeScroll);

    const move = (moveEvent) => {
        lastY = moveEvent.clientY;
        card.style.top = `${lastY - offsetY}px`;
        placeGap();
    };
    const finish = (cancelled) => {
        window.cancelAnimationFrame(scrollTimer);
        handle.removeEventListener('pointermove', move);
        handle.removeEventListener('pointerup', up);
        handle.removeEventListener('pointercancel', cancel);
        const order = [...serviceList.querySelectorAll('.service-card:not(.is-lifted), .service-drop-gap')];
        const toIndex = order.indexOf(gap);
        gap.remove();
        card.removeAttribute('style');
        card.classList.remove('is-lifted');
        serviceList.classList.remove('is-reordering');
        document.body.classList.remove('is-reordering');
        const fromIndex = services.findIndex((item) => item.id === serviceId);
        if (!cancelled && fromIndex >= 0 && toIndex >= 0 && toIndex !== fromIndex) {
            moveService(fromIndex, toIndex);
        } else {
            render();
        }
    };
    const up = () => finish(false);
    const cancel = () => finish(true);
    handle.addEventListener('pointermove', move);
    handle.addEventListener('pointerup', up);
    handle.addEventListener('pointercancel', cancel);
}

function render() {
    renderChips();
    const nodes = [];
    services.forEach((service, index) => {
        nodes.push(renderService(service, index));
        if (index < services.length - 1) {
            nodes.push(renderInsertRow(index));
        }
    });
    if (!services.length) {
        nodes.push(element('div', 'panel empty-state', 'No services this week. Click “Add service” to start.'));
    }
    serviceList.replaceChildren(...nodes);
    updateHint();
}

document.querySelector('[data-suguan-add]').addEventListener('click', () => addServiceAfter(services.length - 1));

document.querySelector('[data-suguan-rotate]').addEventListener('click', () => {
    services = services.map((service) => ({
        ...service,
        computer: service.video,
        audio: service.computer,
        video: service.audio,
    }));
    changed();
});

// Normalize: the week becomes exactly the regular schedule reported in the INC Directory (day, time, PNK, EWS),
// keeping the operators already assigned; anything else (a Pamamahayag, extra services) is removed.
document.querySelector('[data-suguan-sort]').addEventListener('click', async () => {
    const body = new FormData();
    body.set('csrf_token', suguanData.csrf);
    body.set('action', 'align_suguan');
    body.set('week', suguanData.week);
    body.set('services', JSON.stringify(services));
    try {
        const response = await fetch('index.php', { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body });
        const result = await response.json();
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Could not sort.');
        }
        services = result.services;
    } catch (error) {
        setSaveState(error instanceof Error ? error.message : 'Could not normalize.', 'error');
        return;
    }
    changed();
});

// Esc first stops assigning; only a second Esc closes the generator.
suguanDialog.addEventListener('cancel', (event) => {
    if (selectedName || armedSlot) {
        event.preventDefault();
        selectedName = null;
        armedSlot = null;
        render();
    }
});

function updateBuilderVisibility() {
    builder.hidden = !suguanCheckbox?.checked;
    suguanDialog.classList.toggle('is-wide', !builder.hidden);
}

suguanCheckbox?.addEventListener('change', updateBuilderVisibility);

function renderWeekHeader() {
    weekInput.value = suguanData.week;
    weekLabel.textContent = suguanData.label;
    const isCurrent = suguanData.week === suguanData.current;
    weekMeta.textContent = `Week ${suguanData.weekNumber}${isCurrent ? ' · This week' : ''}`;
    currentWeekButton.hidden = isCurrent;
    setSaveState(suguanData.saved ? 'Saved' : 'Not saved yet', suguanData.saved ? 'saved' : '');
}

document.querySelectorAll('[data-week-step]').forEach((button) => {
    button.addEventListener('click', async () => {
        await save();
        if (dirty) {
            return;
        }
        const week = suguanData[button.dataset.weekStep];
        try {
            const response = await fetch(`index.php?action=suguan_data&week=${encodeURIComponent(week)}`, {
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) {
                throw new Error(`Could not load that week (HTTP ${response.status}).`);
            }
            Object.assign(suguanData, await response.json());
            services = suguanData.services;
            selectedName = null;
            armedSlot = null;
            renderWeekHeader();
            render();
        } catch (error) {
            setSaveState(error.message, 'error');
        }
    });
});

// Save pending Suguan edits before the generator downloads anything that reads them.
suguanForm.addEventListener('submit', async (event) => {
    if (event.defaultPrevented || (!dirty && saveState.dataset.state !== 'saving')) {
        return;
    }
    event.preventDefault();
    await save();
    if (!dirty) {
        suguanForm.requestSubmit(event.submitter);
    }
});

window.addEventListener('beforeunload', (event) => {
    if (dirty) {
        save();
        event.preventDefault();
    }
});

renderWeekHeader();
updateBuilderVisibility();
render();
