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
    ['computer', 'Computer'],
    ['video', 'Video'],
    ['audio', 'Audio'],
];
const icons = {
    grip: '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>',
    up: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m18 15-6-6-6 6"/></svg>',
    down: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>',
    trash: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg>',
    x: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>',
    plus: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>',
};

let services = suguanData.services;
let selectedName = null;
let armedSlot = null;
let saveTimeout;
let savePromise = Promise.resolve();
let dirty = false;
let draggedId = null;

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
        pickerHint.textContent = `Click a role to assign ${selectedName}. Press Esc to stop.`;
    } else if (armedSlot) {
        pickerHint.textContent = 'Now click a name to fill the highlighted role.';
    } else {
        pickerHint.textContent = 'Pick a name, then click a role below.';
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
    const handle = iconButton(icons.grip, 'Drag to reorder', 'drag-handle');
    handle.addEventListener('pointerdown', () => {
        card.draggable = true;
    });
    handle.addEventListener('pointerup', () => {
        card.draggable = false;
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
    const up = iconButton(icons.up, 'Move up');
    up.disabled = index === 0;
    up.addEventListener('click', () => moveService(index, index - 1));
    const down = iconButton(icons.down, 'Move down');
    down.disabled = index === services.length - 1;
    down.addEventListener('click', () => moveService(index, index + 1));
    const remove = iconButton(icons.trash, 'Delete service', 'icon-button icon-button-small icon-button-danger');
    remove.addEventListener('click', () => {
        if (window.confirm('Delete this service from the Suguan?')) {
            services.splice(index, 1);
            changed();
        }
    });
    actions.append(up, down, remove);
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

    card.addEventListener('dragstart', (event) => {
        if (!card.draggable) {
            return;
        }
        draggedId = service.id;
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/service', service.id);
        window.requestAnimationFrame(() => card.classList.add('is-dragging'));
    });
    card.addEventListener('dragend', () => {
        card.draggable = false;
        draggedId = null;
        card.classList.remove('is-dragging');
        clearDropMarkers();
    });
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

function clearDropMarkers() {
    serviceList.querySelectorAll('.drop-before, .drop-after').forEach((node) => {
        node.classList.remove('drop-before', 'drop-after');
    });
}

function dropTarget(event) {
    const cards = [...serviceList.querySelectorAll('.service-card:not(.is-dragging)')];
    for (const card of cards) {
        const box = card.getBoundingClientRect();
        if (event.clientY < box.top + box.height / 2) {
            return { card, before: true };
        }
    }
    return cards.length ? { card: cards[cards.length - 1], before: false } : null;
}

serviceList.addEventListener('dragover', (event) => {
    if (!draggedId) {
        return;
    }
    event.preventDefault();
    clearDropMarkers();
    const target = dropTarget(event);
    target?.card.classList.add(target.before ? 'drop-before' : 'drop-after');
});

serviceList.addEventListener('drop', (event) => {
    if (!draggedId) {
        return;
    }
    event.preventDefault();
    const target = dropTarget(event);
    const fromIndex = services.findIndex((service) => service.id === draggedId);
    if (!target || fromIndex < 0) {
        return;
    }
    const [service] = services.splice(fromIndex, 1);
    let toIndex = services.findIndex((item) => item.id === target.card.dataset.id);
    if (!target.before) {
        toIndex += 1;
    }
    services.splice(toIndex, 0, service);
    draggedId = null;
    changed();
});

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

document.querySelector('[data-suguan-sort]').addEventListener('click', () => {
    services.sort((a, b) => `${a.date} ${a.time}`.localeCompare(`${b.date} ${b.time}`));
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
