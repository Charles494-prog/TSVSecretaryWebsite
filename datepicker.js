// Themed date picker that replaces the browser's native <input type="date"> popup.
// The original input keeps its name and stores YYYY-MM-DD, so the server code is unchanged;
// a visible text field shows "July 08, 2003" and also accepts typed dates.
(() => {
    const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    const WEEKDAYS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
    const ICON = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>';
    const CHEVRON = (d) => `<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${d}"/></svg>`;

    const pad = (number) => String(number).padStart(2, '0');
    const toIso = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
    const fromIso = (value) => {
        const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '');
        if (!match) {
            return null;
        }
        const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
        return date.getMonth() === Number(match[2]) - 1 ? date : null;
    };
    const display = (value) => {
        const date = fromIso(value);
        return date ? `${MONTHS[date.getMonth()]} ${pad(date.getDate())}, ${date.getFullYear()}` : '';
    };
    const todayIso = () => toIso(new Date());

    // Accepts "July 8, 2003", "Jul 8 2003", "8 July 2003", "2003-07-08" and "7/8/2003" (month first).
    function parseTyped(text) {
        const value = text.trim().replace(/\s+/g, ' ');
        if (value === '') {
            return '';
        }
        let year;
        let month;
        let day;
        let match = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(value);
        if (match) {
            [year, month, day] = [match[1], match[2], match[3]];
        } else if ((match = /^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$/.exec(value))) {
            [month, day, year] = [match[1], match[2], match[3]];
        } else if ((match = /^([a-z]+)\.? (\d{1,2}),? (\d{4})$/i.exec(value))) {
            [month, day, year] = [match[1], match[2], match[3]];
        } else if ((match = /^(\d{1,2}) ([a-z]+)\.?,? (\d{4})$/i.exec(value))) {
            [day, month, year] = [match[1], match[2], match[3]];
        } else {
            return null;
        }
        if (!/^\d+$/.test(month)) {
            const index = MONTHS.findIndex((name) => name.toLowerCase().startsWith(month.toLowerCase().slice(0, 3)));
            if (index < 0 || month.length < 3) {
                return null;
            }
            month = index + 1;
        }
        const date = new Date(Number(year), Number(month) - 1, Number(day));
        if (date.getMonth() !== Number(month) - 1 || date.getDate() !== Number(day)) {
            return null;
        }
        return toIso(date);
    }

    function limitMessage(input, iso) {
        if (input.max && iso > input.max) {
            return input.max === todayIso() ? 'The date cannot be later than today.' : `Choose a date on or before ${display(input.max)}.`;
        }
        if (input.min && iso < input.min) {
            return `Choose a date on or after ${display(input.min)}.`;
        }
        return '';
    }

    function enhance(input) {
        if (input.dataset.datepicker === 'on') {
            return;
        }
        input.dataset.datepicker = 'on';
        const wrapper = document.createElement('div');
        wrapper.className = 'datepicker';
        const field = document.createElement('input');
        field.type = 'text';
        field.className = 'datepicker-display';
        field.autocomplete = 'off';
        field.placeholder = 'Month DD, YYYY';
        field.setAttribute('aria-label', input.getAttribute('aria-label') || 'Date');
        // "required" moves to the visible field, since a hidden input is never validated.
        if (input.required) {
            field.required = true;
        }
        if (input.hasAttribute('data-required')) {
            field.setAttribute('data-required', '');
            input.removeAttribute('data-required');
        }
        input.required = false;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'datepicker-toggle';
        button.tabIndex = -1;
        button.setAttribute('aria-label', 'Open calendar');
        button.innerHTML = ICON;
        input.replaceWith(wrapper);
        input.type = 'hidden';
        wrapper.append(field, button, input);
        field.value = display(input.value);
    }

    function originalOf(element) {
        return element.closest('.datepicker')?.querySelector('input[type="hidden"]');
    }

    function commitTyped(field) {
        const input = originalOf(field);
        if (!input) {
            return;
        }
        const iso = parseTyped(field.value);
        if (iso === null) {
            field.setCustomValidity('Enter a date like July 08, 2003.');
            return;
        }
        const message = iso === '' ? '' : limitMessage(input, iso);
        field.setCustomValidity(message);
        if (message) {
            return;
        }
        setValue(input, iso);
    }

    function setValue(input, iso) {
        const field = input.closest('.datepicker').querySelector('.datepicker-display');
        field.value = display(iso);
        field.setCustomValidity('');
        if (input.value !== iso) {
            input.value = iso;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    // One shared calendar, shown in the top layer so it sits above the generator dialog.
    const popup = document.createElement('div');
    popup.className = 'datepicker-popup';
    popup.setAttribute('role', 'dialog');
    popup.setAttribute('aria-label', 'Choose a date');
    const canPopover = typeof popup.showPopover === 'function';
    if (canPopover) {
        popup.popover = 'manual';
    }
    popup.hidden = true;
    let activeInput = null;
    let viewYear = 0;
    let viewMonth = 0;
    let focusIso = '';

    function render() {
        const selected = activeInput.value;
        const today = todayIso();
        const max = activeInput.max || '';
        const min = activeInput.min || '';
        const maxYear = max ? Number(max.slice(0, 4)) : new Date().getFullYear() + 10;
        const minYear = min ? Number(min.slice(0, 4)) : 1920;
        const first = new Date(viewYear, viewMonth, 1);
        const start = new Date(viewYear, viewMonth, 1 - first.getDay());
        const prevDisabled = min && toIso(new Date(viewYear, viewMonth, 0)) < min;
        const nextDisabled = max && toIso(new Date(viewYear, viewMonth + 1, 1)) > max;

        let years = '';
        for (let year = maxYear; year >= minYear; year--) {
            years += `<option value="${year}" ${year === viewYear ? 'selected' : ''}>${year}</option>`;
        }
        let days = '';
        for (let index = 0; index < 42; index++) {
            const date = new Date(start.getFullYear(), start.getMonth(), start.getDate() + index);
            const iso = toIso(date);
            const disabled = (max && iso > max) || (min && iso < min);
            const classes = ['datepicker-day'];
            if (date.getMonth() !== viewMonth) classes.push('is-outside');
            if (iso === today) classes.push('is-today');
            if (iso === selected) classes.push('is-selected');
            days += `<button type="button" class="${classes.join(' ')}" data-iso="${iso}" tabindex="${iso === focusIso ? 0 : -1}" ${disabled ? 'disabled' : ''} aria-label="${display(iso)}" ${iso === selected ? 'aria-pressed="true"' : ''}>${date.getDate()}</button>`;
        }

        popup.innerHTML = `
            <div class="datepicker-head">
                <button type="button" class="datepicker-nav" data-step="-1" aria-label="Previous month" ${prevDisabled ? 'disabled' : ''}>${CHEVRON('m15 18-6-6 6-6')}</button>
                <div class="datepicker-selects">
                    <select data-pick="month" aria-label="Month">${MONTHS.map((name, index) => `<option value="${index}" ${index === viewMonth ? 'selected' : ''}>${name}</option>`).join('')}</select>
                    <select data-pick="year" aria-label="Year">${years}</select>
                </div>
                <button type="button" class="datepicker-nav" data-step="1" aria-label="Next month" ${nextDisabled ? 'disabled' : ''}>${CHEVRON('m9 18 6-6-6-6')}</button>
            </div>
            <div class="datepicker-weekdays">${WEEKDAYS.map((day) => `<span>${day}</span>`).join('')}</div>
            <div class="datepicker-grid">${days}</div>
            <div class="datepicker-foot">
                <button type="button" class="datepicker-link" data-action="clear">Clear</button>
                <button type="button" class="datepicker-link" data-action="today" ${(max && today > max) || (min && today < min) ? 'disabled' : ''}>Today</button>
            </div>`;
    }

    function place() {
        const anchor = activeInput.closest('.datepicker').getBoundingClientRect();
        const width = popup.offsetWidth;
        const height = popup.offsetHeight;
        let left = Math.min(anchor.left, window.innerWidth - width - 8);
        let top = anchor.bottom + 6;
        if (top + height > window.innerHeight - 8 && anchor.top - height - 6 > 8) {
            top = anchor.top - height - 6;
        }
        popup.style.left = `${Math.max(8, left)}px`;
        popup.style.top = `${Math.max(8, top)}px`;
    }

    function open(input) {
        activeInput = input;
        const base = fromIso(input.value) || fromIso(input.max && todayIso() > input.max ? input.max : todayIso());
        viewYear = base.getFullYear();
        viewMonth = base.getMonth();
        focusIso = toIso(base);
        // Inside an open modal dialog the calendar must live in that dialog: the browser makes everything
        // outside a modal dialog unclickable, even a popover drawn on top of it.
        const host = input.closest('dialog[open]') || document.body;
        if (popup.parentNode !== host) {
            host.append(popup);
        }
        render();
        popup.hidden = false;
        if (canPopover) {
            popup.showPopover();
        }
        place();
    }

    function close(returnFocus = false) {
        if (!activeInput) {
            return;
        }
        const field = activeInput.closest('.datepicker')?.querySelector('.datepicker-display');
        if (canPopover && popup.matches(':popover-open')) {
            popup.hidePopover();
        }
        popup.hidden = true;
        activeInput = null;
        if (returnFocus) {
            field?.focus();
        }
    }

    function moveFocus(iso) {
        const date = fromIso(iso);
        focusIso = iso;
        if (date.getFullYear() !== viewYear || date.getMonth() !== viewMonth) {
            viewYear = date.getFullYear();
            viewMonth = date.getMonth();
            render();
        } else {
            popup.querySelectorAll('.datepicker-day').forEach((day) => {
                day.tabIndex = day.dataset.iso === iso ? 0 : -1;
            });
        }
        popup.querySelector(`[data-iso="${iso}"]`)?.focus();
    }

    popup.addEventListener('click', (event) => {
        const target = event.target.closest('button');
        if (!target || target.disabled || !activeInput) {
            return;
        }
        if (target.dataset.iso) {
            setValue(activeInput, target.dataset.iso);
            close(true);
        } else if (target.dataset.step) {
            const date = new Date(viewYear, viewMonth + Number(target.dataset.step), 1);
            viewYear = date.getFullYear();
            viewMonth = date.getMonth();
            render();
        } else if (target.dataset.action === 'clear') {
            setValue(activeInput, '');
            close(true);
        } else if (target.dataset.action === 'today') {
            setValue(activeInput, todayIso());
            close(true);
        }
    });

    popup.addEventListener('change', (event) => {
        if (event.target.dataset.pick === 'month') {
            viewMonth = Number(event.target.value);
        } else if (event.target.dataset.pick === 'year') {
            viewYear = Number(event.target.value);
        }
        render();
        popup.querySelector(`[data-pick="${event.target.dataset.pick}"]`)?.focus();
    });

    popup.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            close(true);
            return;
        }
        const day = event.target.closest('.datepicker-day');
        if (!day) {
            return;
        }
        const date = fromIso(day.dataset.iso);
        const shifts = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
        if (event.key in shifts) {
            date.setDate(date.getDate() + shifts[event.key]);
        } else if (event.key === 'PageUp' || event.key === 'PageDown') {
            date.setMonth(date.getMonth() + (event.key === 'PageUp' ? -1 : 1));
        } else {
            return;
        }
        event.preventDefault();
        moveFocus(toIso(date));
    });

    document.addEventListener('click', (event) => {
        const wrapper = event.target.closest?.('.datepicker');
        if (wrapper && (event.target.closest('.datepicker-toggle') || event.target.closest('.datepicker-display'))) {
            const input = wrapper.querySelector('input[type="hidden"]');
            if (activeInput === input && event.target.closest('.datepicker-toggle')) {
                close(true);
            } else if (activeInput !== input) {
                close();
                open(input);
            }
            return;
        }
        if (activeInput && !popup.contains(event.target)) {
            close();
        }
    });

    document.addEventListener('keydown', (event) => {
        const field = event.target.closest?.('.datepicker-display');
        if (!field) {
            return;
        }
        if ((event.key === 'ArrowDown' && (event.altKey || !activeInput)) || (event.key === 'Enter' && !activeInput)) {
            event.preventDefault();
            commitTyped(field);
            open(originalOf(field));
            popup.querySelector('.datepicker-day[tabindex="0"]')?.focus();
        } else if (event.key === 'Escape' && activeInput) {
            event.preventDefault();
            event.stopPropagation();
            close();
        } else if (event.key === 'Tab' && activeInput) {
            close();
        }
    }, true);

    document.addEventListener('change', (event) => {
        if (event.target.classList?.contains('datepicker-display')) {
            commitTyped(event.target);
        }
    });
    // A date typed and submitted with Enter has not fired "change" yet; read it before the form is sent.
    document.addEventListener('submit', (event) => {
        const fields = [...(event.target.querySelectorAll?.('.datepicker-display') ?? [])];
        fields.forEach(commitTyped);
        const invalid = fields.find((field) => field.validationMessage !== '' && field.offsetParent !== null);
        if (invalid) {
            event.preventDefault();
            event.stopImmediatePropagation();
            invalid.reportValidity();
        }
    }, true);
    document.addEventListener('input', (event) => {
        if (event.target.classList?.contains('datepicker-display')) {
            event.target.setCustomValidity('');
            // Clearing the text clears the date.
            if (event.target.value.trim() === '') {
                setValue(originalOf(event.target), '');
            }
        }
    });

    // Close when the page or dialog scrolls, so the calendar never floats away from its field.
    window.addEventListener('resize', () => activeInput && place());
    document.addEventListener('scroll', (event) => {
        if (activeInput && !popup.contains(event.target)) {
            close();
        }
    }, true);
    document.addEventListener('close', () => close(), true);

    document.querySelectorAll('input[type="date"]').forEach(enhance);
    // Inputs added later (Suguan service rows, cloned recruit rows) are enhanced as they appear.
    new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            mutation.addedNodes.forEach((node) => {
                if (!(node instanceof HTMLElement)) {
                    return;
                }
                if (node.matches('input[type="date"]')) {
                    enhance(node);
                }
                node.querySelectorAll?.('input[type="date"]').forEach(enhance);
                // A cloned row brings a display field whose text no longer matches its (cleared) value.
                node.querySelectorAll?.('.datepicker').forEach((wrapper) => {
                    const hidden = wrapper.querySelector('input[type="hidden"]');
                    wrapper.querySelector('.datepicker-display').value = display(hidden?.value);
                });
            });
        }
    }).observe(document.body, { childList: true, subtree: true });
})();
