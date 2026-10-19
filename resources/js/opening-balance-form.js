export const parseOpeningAmount = (value) => {
    const normalized = String(value ?? '').trim();

    if (normalized === '') {
        return 0n;
    }

    const match = normalized.match(/^(\d+)(?:\.(\d{0,2}))?$/);

    if (! match) {
        return null;
    }

    return BigInt(match[1]) * 100n + BigInt((match[2] ?? '').padEnd(2, '0'));
};

export const formatOpeningPkr = (minorUnits) => {
    const negative = minorUnits < 0n;
    const absolute = negative ? -minorUnits : minorUnits;
    const whole = (absolute / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const fraction = (absolute % 100n).toString().padStart(2, '0');

    return `PKR ${negative ? '-' : ''}${whole}.${fraction}`;
};

export const hasEnteredOpeningValue = (row) => {
    const debit = parseOpeningAmount(row.debit);
    const credit = parseOpeningAmount(row.credit);

    return (debit === null && String(row.debit).trim() !== '')
        || (credit === null && String(row.credit).trim() !== '')
        || debit !== 0n
        || credit !== 0n
        || String(row.reference ?? '').trim() !== '';
};

export const openingRowMatches = (row, state) => {
    const matchesSearch = row.search.toLocaleLowerCase().includes(state.search.trim().toLocaleLowerCase());
    const matchesType = state.type === 'all' || row.type === state.type;
    const matchesEntered = ! state.enteredOnly || hasEnteredOpeningValue(row);

    return matchesSearch && matchesType && matchesEntered;
};

export const calculateOpeningTotals = (rows) => {
    let debit = 0n;
    let credit = 0n;
    let valid = true;

    rows.forEach((row) => {
        const rowDebit = parseOpeningAmount(row.debit);
        const rowCredit = parseOpeningAmount(row.credit);

        if (rowDebit === null || rowCredit === null) {
            valid = false;

            return;
        }

        debit += rowDebit;
        credit += rowCredit;
    });

    return { debit, credit, difference: debit - credit, valid };
};

const readOpeningRow = (element) => ({
    element,
    type: element.dataset.accountType,
    search: element.dataset.accountSearch ?? '',
    debit: element.querySelector('[data-opening-debit]')?.value ?? '',
    credit: element.querySelector('[data-opening-credit]')?.value ?? '',
    reference: element.querySelector('[data-opening-reference]')?.value ?? '',
});

export const setOpeningGroupExpanded = (group, expanded) => {
    const toggle = group.querySelector('[data-opening-group-toggle]');
    const body = group.querySelector('[data-opening-group-body]');

    toggle?.setAttribute('aria-expanded', String(expanded));
    if (body) {
        body.hidden = ! expanded;
    }
};

export const initializeOpeningBalanceForms = () => {
    document.querySelectorAll('[data-opening-balance-form]').forEach((form) => {
        const rowElements = [...form.querySelectorAll('[data-opening-account-row]')];
        const search = form.querySelector('[data-opening-search]');
        const clearSearch = form.querySelector('[data-opening-search-clear]');
        const enteredOnly = form.querySelector('[data-opening-entered-only]');
        const filterButtons = [...form.querySelectorAll('[data-opening-type-filter]')];
        const emptyState = form.querySelector('[data-opening-filter-empty]');
        const state = { search: '', type: 'all', enteredOnly: false };

        const rows = () => rowElements.map(readOpeningRow);

        const updateTotals = () => {
            const totals = calculateOpeningTotals(rows());
            form.querySelector('[data-opening-total-debit]').textContent = formatOpeningPkr(totals.debit);
            form.querySelector('[data-opening-total-credit]').textContent = formatOpeningPkr(totals.credit);
            form.querySelector('[data-opening-difference]').textContent = formatOpeningPkr(totals.difference);

            const status = form.querySelector('[data-opening-balance-status]');
            status.classList.remove('neutral', 'success', 'danger');
            if (! totals.valid) {
                status.classList.add('danger');
                status.textContent = 'Check amount format';
            } else if (totals.debit === 0n && totals.credit === 0n) {
                status.classList.add('neutral');
                status.textContent = 'No amounts entered';
            } else if (totals.debit === totals.credit) {
                status.classList.add('success');
                status.textContent = 'Balanced';
            } else {
                status.classList.add('danger');
                status.textContent = 'Unbalanced';
            }
        };

        const applyFilters = () => {
            let visibleRows = 0;

            rowElements.forEach((element) => {
                const visible = openingRowMatches(readOpeningRow(element), state);
                element.hidden = ! visible;
                visibleRows += visible ? 1 : 0;
            });

            form.querySelectorAll('[data-opening-group]').forEach((group) => {
                const visibleInGroup = [...group.querySelectorAll('[data-opening-account-row]')].filter((row) => ! row.hidden).length;
                group.hidden = visibleInGroup === 0;
                const count = group.querySelector('[data-opening-group-count]');
                if (count) {
                    count.textContent = String(visibleInGroup);
                }
            });

            form.querySelectorAll('[data-opening-type-section]').forEach((section) => {
                section.hidden = ! [...section.querySelectorAll('[data-opening-group]')].some((group) => ! group.hidden);
            });

            if (emptyState) {
                emptyState.hidden = visibleRows !== 0;
            }
        };

        search?.addEventListener('input', () => {
            state.search = search.value;
            clearSearch.disabled = state.search === '';
            applyFilters();
        });
        clearSearch?.addEventListener('click', () => {
            search.value = '';
            state.search = '';
            clearSearch.disabled = true;
            search.focus();
            applyFilters();
        });
        filterButtons.forEach((button) => button.addEventListener('click', () => {
            state.type = button.dataset.openingTypeFilter;
            filterButtons.forEach((candidate) => {
                const active = candidate === button;
                candidate.classList.toggle('active', active);
                candidate.setAttribute('aria-pressed', String(active));
            });
            applyFilters();
        }));
        enteredOnly?.addEventListener('change', () => {
            state.enteredOnly = enteredOnly.checked;
            applyFilters();
        });
        form.querySelectorAll('[data-opening-group-toggle]').forEach((toggle) => {
            toggle.addEventListener('click', () => {
                setOpeningGroupExpanded(toggle.closest('[data-opening-group]'), toggle.getAttribute('aria-expanded') !== 'true');
            });
        });
        form.querySelector('[data-opening-expand-all]')?.addEventListener('click', () => {
            form.querySelectorAll('[data-opening-group]').forEach((group) => setOpeningGroupExpanded(group, true));
        });
        form.querySelector('[data-opening-collapse-all]')?.addEventListener('click', () => {
            form.querySelectorAll('[data-opening-group]').forEach((group) => setOpeningGroupExpanded(group, false));
        });
        form.addEventListener('input', (event) => {
            if (! event.target.matches('[data-opening-debit], [data-opening-credit], [data-opening-reference]')) {
                return;
            }
            updateTotals();
            if (state.enteredOnly) {
                applyFilters();
            }
        });

        applyFilters();
        updateTotals();
    });
};
