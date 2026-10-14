import 'bootstrap';

const root = document.documentElement;
const sidebar = document.querySelector('#appSidebar');

document.querySelector('[data-sidebar-collapse]')?.addEventListener('click', () => {
    const nextState = root.dataset.sidebar === 'collapsed' ? 'expanded' : 'collapsed';
    root.dataset.sidebar = nextState;
    localStorage.setItem('frc-accounting-sidebar', nextState);
});

document.querySelector('[data-sidebar-mobile]')?.addEventListener('click', () => {
    document.body.classList.add('sidebar-open');
});

document.querySelectorAll('[data-sidebar-close]').forEach((element) => {
    element.addEventListener('click', () => document.body.classList.remove('sidebar-open'));
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        document.body.classList.remove('sidebar-open');
    }
});

sidebar?.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => document.body.classList.remove('sidebar-open'));
});

document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.passwordToggle);

        if (! input) {
            return;
        }

        const shouldShow = input.type === 'password';
        input.type = shouldShow ? 'text' : 'password';
        button.textContent = shouldShow ? 'Hide' : 'Show';
        button.setAttribute('aria-label', shouldShow ? 'Hide password' : 'Show password');
    });
});

const journalForm = document.querySelector('[data-journal-form]');

if (journalForm) {
    const body = journalForm.querySelector('[data-journal-lines]');
    const updateRows = () => {
        body.querySelectorAll('[data-journal-line]').forEach((row, index) => {
            row.querySelectorAll('[name]').forEach((input) => {
                input.name = input.name.replace(/lines\[\d+]/, `lines[${index}]`);
            });
        });
    };
    const updateTotals = () => {
        const decimalUnits = (value) => {
            const match = String(value || '0').trim().match(/^(\d+)(?:\.(\d{0,4}))?$/);
            if (! match) return null;
            return BigInt(match[1]) * 10000n + BigInt((match[2] || '').padEnd(4, '0'));
        };
        const total = (selector) => [...body.querySelectorAll(selector)].reduce((sum, input) => {
            const units = decimalUnits(input.value);
            return units === null || sum === null ? null : sum + units;
        }, 0n);
        const format = (units) => {
            if (units === null) return 'Invalid';
            const absolute = units < 0n ? -units : units;
            return `${units < 0n ? '-' : ''}${absolute / 10000n}.${String(absolute % 10000n).padStart(4, '0')}`;
        };
        const debit = total('[data-debit]');
        const credit = total('[data-credit]');
        journalForm.querySelector('[data-total-debit]').textContent = format(debit);
        journalForm.querySelector('[data-total-credit]').textContent = format(credit);
        journalForm.querySelector('[data-balance-difference]').textContent = debit === null || credit === null ? 'Invalid' : format(debit - credit);
        const state = journalForm.querySelector('[data-balance-state]');
        const balanced = debit !== null && credit !== null && debit > 0n && debit === credit;
        state.textContent = balanced ? 'Balanced' : 'Unbalanced';
        state.classList.toggle('success', balanced);
    };

    journalForm.querySelector('[data-add-journal-line]')?.addEventListener('click', () => {
        const row = body.querySelector('[data-journal-line]')?.cloneNode(true);
        if (! row) return;
        row.querySelectorAll('input').forEach((input) => { input.value = input.hasAttribute('data-debit') || input.hasAttribute('data-credit') ? '0' : ''; });
        row.querySelectorAll('select').forEach((select) => { select.selectedIndex = 0; });
        body.append(row);
        updateRows();
        updateTotals();
    });
    body.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-remove-journal-line]');
        if (! remove || body.querySelectorAll('[data-journal-line]').length <= 2) return;
        remove.closest('[data-journal-line]').remove();
        updateRows();
        updateTotals();
    });
    body.addEventListener('input', updateTotals);
    journalForm.addEventListener('submit', (event) => {
        if (! journalForm.checkValidity()) return;
        const submitter = event.submitter;
        if (submitter) {
            submitter.disabled = true;
            submitter.textContent = 'Saving...';
        }
    });
    updateTotals();
}

document.querySelectorAll('[data-submit-once]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (! form.checkValidity()) return;
        const submitter = event.submitter;
        if (submitter) {
            submitter.disabled = true;
            submitter.textContent = 'Processing...';
        }
    });
});

// Confirmation dialog for any submit button or form carrying data-confirm.
document.addEventListener('submit', (event) => {
    const message = event.submitter?.dataset.confirm ?? event.target.dataset.confirm;
    if (message && ! window.confirm(message)) {
        event.preventDefault();
        event.stopImmediatePropagation();
    }
}, true);

document.querySelectorAll('[data-check-all]').forEach((toggle) => {
    toggle.addEventListener('change', () => {
        document.querySelectorAll(toggle.dataset.checkAll).forEach((box) => {
            if (! box.disabled) box.checked = toggle.checked;
        });
    });
});

// Repeating document lines (purchase orders) with exact 4-decimal totals.
document.querySelectorAll('[data-line-items]').forEach((container) => {
    const body = container.querySelector('[data-line-body]');
    const template = container.querySelector('template');
    const units = (value) => {
        const match = String(value || '0').trim().match(/^(\d+)(?:\.(\d{0,4}))?$/);
        return match ? BigInt(match[1]) * 10000n + BigInt((match[2] || '').padEnd(4, '0')) : null;
    };
    const format = (value) => value === null ? 'Invalid' : `${value < 0n ? '-' : ''}${(value < 0n ? -value : value) / 10000n}.${String((value < 0n ? -value : value) % 10000n).padStart(4, '0')}`;
    const reindex = () => body.querySelectorAll('[data-line-row]').forEach((row, index) => {
        row.querySelectorAll('[name]').forEach((input) => {
            input.name = input.name.replace(/lines\[[^\]]*]/, `lines[${index}]`);
        });
    });
    const recalculate = () => {
        let total = 0n;
        body.querySelectorAll('[data-line-row]').forEach((row) => {
            const read = (name) => units(row.querySelector(`[data-field="${name}"]`)?.value);
            const [quantity, price, discount, tax] = [read('quantity'), read('unit_price'), read('discount_amount'), read('tax_amount')];
            const valid = [quantity, price, discount, tax].every((value) => value !== null);
            const line = valid ? (quantity * price + 5000n) / 10000n - discount + tax : null;
            const output = row.querySelector('[data-line-total]');
            if (output) output.textContent = format(line);
            total = total === null || line === null ? null : total + line;
        });
        const output = container.querySelector('[data-grand-total]');
        if (output) output.textContent = format(total);
    };

    container.querySelector('[data-add-line]')?.addEventListener('click', () => {
        body.append(template.content.cloneNode(true));
        reindex();
        recalculate();
    });
    body.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-remove-line]');
        if (! remove || body.querySelectorAll('[data-line-row]').length <= 1) return;
        remove.closest('[data-line-row]').remove();
        reindex();
        recalculate();
    });
    body.addEventListener('input', recalculate);
    recalculate();
});
