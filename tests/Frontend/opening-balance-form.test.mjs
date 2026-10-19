import assert from 'node:assert/strict';
import test from 'node:test';

import {
    calculateOpeningTotals,
    formatOpeningPkr,
    hasEnteredOpeningValue,
    openingRowMatches,
    parseOpeningAmount,
    setOpeningGroupExpanded,
} from '../../resources/js/opening-balance-form.js';

const rows = [
    { type: 'asset', search: '11102 cash in hand - rehab centre', debit: '0.10', credit: '', reference: '' },
    { type: 'liability', search: '21101 accounts payable', debit: '', credit: '0.20', reference: '' },
    { type: 'equity', search: '31101 general fund', debit: '0.20', credit: '0.10', reference: 'AUD-TB-1' },
];

test('search is case-insensitive, partial, and combines with account type filters', () => {
    assert.equal(openingRowMatches(rows[0], { search: 'CASH IN', type: 'all', enteredOnly: false }), true);
    assert.equal(openingRowMatches(rows[1], { search: 'pay', type: 'liability', enteredOnly: false }), true);
    assert.equal(openingRowMatches(rows[1], { search: 'pay', type: 'asset', enteredOnly: false }), false);
    assert.equal(openingRowMatches(rows[2], { search: '311', type: 'equity', enteredOnly: false }), true);
});

test('entered-only filtering recognizes amounts and meaningful references without mutating rows', () => {
    const untouched = { type: 'asset', search: '11103 receivable', debit: '0.00', credit: '', reference: '' };
    const referenceOnly = { ...untouched, reference: 'Verified schedule' };
    const snapshot = structuredClone(rows);

    assert.equal(hasEnteredOpeningValue(untouched), false);
    assert.equal(hasEnteredOpeningValue(referenceOnly), true);
    assert.equal(openingRowMatches(untouched, { search: '', type: 'all', enteredOnly: true }), false);
    assert.equal(openingRowMatches(rows[0], { search: '', type: 'all', enteredOnly: true }), true);
    assert.deepEqual(rows, snapshot);
});

test('totals include every supplied row regardless of visibility metadata', () => {
    const totals = calculateOpeningTotals([
        { ...rows[0], hidden: true },
        { ...rows[1], hidden: false },
        { ...rows[2], hidden: true },
    ]);

    assert.deepEqual(totals, { debit: 30n, credit: 30n, difference: 0n, valid: true });
    assert.equal(formatOpeningPkr(totals.debit), 'PKR 0.30');
});

test('decimal calculations use exact integer minor units and reject excess precision', () => {
    assert.equal(parseOpeningAmount('9007199254740993.99'), 900719925474099399n);
    assert.equal(parseOpeningAmount('0.10') + parseOpeningAmount('0.20'), parseOpeningAmount('0.30'));
    assert.equal(parseOpeningAmount('1.001'), null);
    assert.equal(parseOpeningAmount('-1.00'), null);
    assert.equal(formatOpeningPkr(-123456n), 'PKR -1,234.56');
});

test('collapsing a group changes visibility and aria state without changing input values', () => {
    const amountInput = { value: '1250.75' };
    const body = { hidden: false, amountInput };
    const attributes = new Map([['aria-expanded', 'true']]);
    const toggle = { setAttribute: (name, value) => attributes.set(name, value) };
    const group = {
        querySelector: (selector) => selector === '[data-opening-group-toggle]' ? toggle : body,
    };

    setOpeningGroupExpanded(group, false);

    assert.equal(body.hidden, true);
    assert.equal(attributes.get('aria-expanded'), 'false');
    assert.equal(amountInput.value, '1250.75');
});
