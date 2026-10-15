// End-to-end UAT workflows driven through a real (headless) browser.
// Rebuilds the isolated UAT database first, then drives every form through the DOM.
//
//   node tests/Uat/browser/workflows.mjs [baseUrl] [outputDir] [--no-reset]
//
// Results: <outputDir>/workflows-results.json and <outputDir>/screens/*.png

import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { launchBrowser } from './cdp.mjs';

const args = process.argv.slice(2).filter((arg) => !arg.startsWith('--'));
const BASE = args[0] ?? 'http://127.0.0.1:8010';
const OUT = args[1] ?? join(process.cwd(), 'storage', 'uat');
const RESET = !process.argv.includes('--no-reset');
const PASSWORD = 'Uat-Password-2026!';
const PHP = 'E:\\wamp64\\bin\\php\\php8.3.14\\php.exe';
const TODAY = '2026-10-14';
mkdirSync(join(OUT, 'screens'), { recursive: true });
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const results = { base: BASE, startedAt: new Date().toISOString(), reset: null, cases: [], defects: [], automationNotes: [], consoleErrors: [], pagesVisited: 0 };
const save = () => writeFileSync(join(OUT, 'workflows-results.json'), JSON.stringify(results, null, 2));

if (RESET) {
    const started = Date.now();
    const output = execFileSync(PHP, ['tests/Uat/setup.php', '--fresh'], { cwd: join(import.meta.dirname, '..', '..', '..'), encoding: 'utf8' });
    results.reset = { command: `${PHP} tests/Uat/setup.php --fresh`, seconds: Math.round((Date.now() - started) / 1000), output: output.trim().split('\n').slice(-2) };
    console.log('UAT database rebuilt:', results.reset.output.join(' | '));
}

// ---------------------------------------------------------------- in-page helpers
const HELPERS = `
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const txt = (el) => (el?.textContent ?? '').replace(/\\s+/g, ' ').trim();
const pickOption = (select, want) => {
    const option = [...select.options].find((o) => (want.value !== undefined ? o.value === String(want.value) : (want.re ? new RegExp(want.re, 'i').test(o.text) : o.text.includes(want.text))));
    if (!option) throw new Error('No option ' + JSON.stringify(want) + ' in ' + select.name + ': ' + [...select.options].map((o) => o.text).join(' | '));
    select.value = option.value;
    return option.text;
};
const fill = (form, fields) => {
    for (const [name, value] of Object.entries(fields)) {
        const el = form.querySelector('[name="' + name + '"]:not([type=hidden])') ?? form.querySelector('[name="' + name + '"]');
        if (!el) throw new Error('Missing field ' + name);
        if (el.tagName === 'SELECT') pickOption(el, typeof value === 'object' ? value : { value });
        else if (el.type === 'checkbox') el.checked = !!value;
        else el.value = String(value);
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }
};
const submitForm = (form, buttonText) => {
    if (!form) throw new Error('Form not found');
    if (!form.checkValidity()) throw new Error('Client validation: ' + $$(':invalid', form).map((e) => e.name + ' ' + e.validationMessage).join('; '));
    const button = buttonText ? $$('button, input[type=submit]', form).find((b) => txt(b).includes(buttonText) || b.value === buttonText) : null;
    if (buttonText && !button) throw new Error('Button "' + buttonText + '" not found in form');
    form.requestSubmit(button ?? undefined);
};
const formByAction = (re) => $$('form').find((f) => new RegExp(re).test(f.getAttribute('action') ?? ''));
const buttonForm = (text) => $$('form').find((f) => $$('button', f).some((b) => txt(b) === text || txt(b).startsWith(text)));
const rows = (root = document) => $$('table tr', root).map((tr) => [...tr.cells].map((c) => txt(c)));
const kpis = () => Object.fromEntries($$('.kpi-card').map((c) => [txt($('.kpi-label', c)), { value: txt($('.kpi-value', c)), status: txt($('.status-badge', c)) }]));
const crafted = (action, fields = {}) => {
    const form = document.createElement('form');
    form.method = 'POST'; form.action = action;
    const token = $('meta[name=csrf-token]')?.content ?? $('input[name=_token]')?.value;
    for (const [name, value] of Object.entries({ _token: token, ...fields })) {
        for (const v of [].concat(value)) { const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = v; form.append(input); }
    }
    document.body.append(form);
    form.submit();
};
`;

const wrap = (fn, fnArgs) => `(async () => { ${HELPERS}\n return (${fn.toString()})(${fnArgs.map((a) => JSON.stringify(a)).join(', ')}); })()`;
const ev = (page, fn, ...fnArgs) => page.evaluate(wrap(fn, fnArgs));

const consoleSink = (page, label) => {
    if (page.consoleErrors.length) results.consoleErrors.push({ user: page.user, url: label, errors: [...page.consoleErrors] });
    page.consoleErrors = [];
};

async function go(page, path) {
    const status = await page.goto(path.startsWith('http') ? path : BASE + path);
    results.pagesVisited++;
    consoleSink(page, path);
    return status;
}

/** Run a DOM action that navigates (form submit, click) and wait for the next document. */
async function act(page, fn, ...fnArgs) {
    page.documentStatus = null;
    const loaded = page.waitFor('Page.loadEventFired', 60000);
    loaded.catch(() => {});
    await ev(page, fn, ...fnArgs);
    await loaded;
    await sleep(200);
    results.pagesVisited++;
    const status = page.documentStatus;
    consoleSink(page, await page.evaluate('location.href'));
    return status;
}

const info = (page) => ev(page, () => ({
    url: location.href.replace(location.origin, ''),
    heading: txt($('.page-header h1')) || txt($('h1')) || document.title,
    alerts: $$('.alert').map(txt).filter(Boolean),
    invalid: $$('.app-content .invalid-feedback').map(txt).filter(Boolean),
    serverError: /Whoops|Internal Server Error|ErrorException|QueryException|Undefined (variable|array key|property)|Call to (undefined|a member)|TypeError:|Server Error/.test(document.body.innerText),
    overflow: document.documentElement.scrollWidth - window.innerWidth,
    bodyStart: txt(document.body).slice(0, 200),
}));

const shot = async (page, name) => { const file = join(OUT, 'screens', `${name}.png`); await page.screenshot(file); return `screens/${name}.png`; };
const money = (s) => (s == null ? null : Number(String(s).replace(/[^0-9.-]/g, '')));
const idFrom = (url, re) => Number((url.match(re) ?? [])[1]);

// ---------------------------------------------------------------- case bookkeeping
function newCase(id, title, expected) {
    const c = { id, title, expected, steps: [], checks: [], actual: '', status: 'NOT RUN', evidence: {} };
    results.cases.push(c);
    return c;
}
const step = (c, text) => { c.steps.push(text); console.log(`  [${c.id}] ${text}`); };
const check = (c, name, ok, detail) => { c.checks.push({ name, ok: !!ok, detail }); console.log(`    ${ok ? 'ok  ' : 'FAIL'} ${name}${detail !== undefined ? ' — ' + JSON.stringify(detail).slice(0, 300) : ''}`); return !!ok; };
const defect = (c, d) => { results.defects.push({ case: c.id, ...d }); };
function finish(c, actual) {
    c.actual = actual ?? c.checks.map((k) => `${k.ok ? '✓' : '✗'} ${k.name}`).join('; ');
    if (c.status === 'NOT RUN' || c.status === 'RUNNING') c.status = c.checks.length && c.checks.every((k) => k.ok) ? 'PASS' : (c.checks.length ? 'FAIL' : 'NOT RUN');
    save();
}
async function runCase(c, body) {
    console.log(`\n== ${c.id} ${c.title}`);
    c.status = 'RUNNING';
    try { await body(c); } catch (error) {
        c.error = String(error?.stack ?? error).split('\n').slice(0, 4).join(' | ');
        console.log(`    ERROR ${c.error}`);
        c.status = c.checks.some((k) => !k.ok) ? 'FAIL' : 'BLOCKED';
    }
    finish(c);
}

// ---------------------------------------------------------------- sessions
const browser = await launchBrowser();
console.log(`Browser: ${browser.executable}`);
const sessions = {};
async function as(role) {
    if (sessions[role]) return sessions[role];
    const page = await browser.newPage();
    page.user = role;
    await page.viewport(1366, 900);
    await page.login(BASE, `uat.${role}@uat.test`, PASSWORD);
    const url = await page.evaluate('location.pathname');
    if (url.includes('/login')) throw new Error(`Login failed for ${role}`);
    consoleSink(page, '/login');
    sessions[role] = page;
    return page;
}

const ctx = {}; // ids discovered along the way

// =============================================================== 1. Approval controls
await runCase(newCase('UAT-01', 'Approval controls (Director only) and audit trail', 'Admin gets 403 / no control; Director sets fees.accounting_policy_approved=1 with a reason; auditor sees approval_settings.changed with old 0 → new 1.'), async (c) => {
    const admin = await as('admin');
    step(c, 'Admin opens /settings/approval-controls');
    const adminStatus = await go(admin, '/settings/approval-controls');
    const adminInfo = await info(admin);
    c.evidence.admin = { status: adminStatus, text: adminInfo.bodyStart.slice(0, 160) };
    check(c, 'Admin refused (403)', adminStatus === 403, adminStatus);
    step(c, 'Admin crafts a PUT to the approval controls (direct request)');
    await go(admin, '/settings');
    const adminPut = await act(admin, () => crafted('/settings/approval-controls', { _method: 'PUT', 'settings[fees__accounting_policy_approved]': '1', reason: 'Admin attempting change' }));
    check(c, 'Admin PUT refused (403)', adminPut === 403, adminPut);
    const adminSettings = await go(admin, '/settings');
    const adminSettingsHasPolicy = await ev(admin, () => !!$('[name*="fees__accounting_policy_approved"], [name*="fees.accounting_policy_approved"]'));
    check(c, 'General settings screen has no fee policy control', !adminSettingsHasPolicy, { status: adminSettings });

    const director = await as('director');
    step(c, 'Director opens Approval Controls, selects "Approved" for Fee Income Policy, enters reason, saves (confirm accepted)');
    const st = await go(director, '/settings/approval-controls');
    check(c, 'Director can open Approval Controls', st === 200, st);
    const before = await ev(director, () => ({ fee: $('[name="settings[fees__accounting_policy_approved]"]').value, basis: $('[name="settings[fees__accounting_basis]"]')?.value, payment: $('[name="settings[approval__payment_threshold]"]')?.value, po: $('[name="settings[approval__purchase_order_threshold]"]')?.value, dep: $('[name="settings[assets__depreciation_policy_approved]"]')?.value, payroll: $('[name="settings[payroll__calculation_policy_approved]"]')?.value }));
    c.evidence.valuesBefore = before;
    check(c, 'Starting values: fee policy 0, basis cash, thresholds 150,000', before.fee === '0' && before.basis === 'cash' && money(before.payment) === 150000 && money(before.po) === 150000, before);
    // Reason too short first: expect validation text.
    await act(director, () => { const f = $('form[data-submit-once]'); fill(f, { 'settings[fees__accounting_policy_approved]': '1' }); f.querySelector('#reason').removeAttribute('minlength'); f.querySelector('#reason').value = 'short'; submitForm(f, 'Save approval controls'); });
    const shortInfo = await info(director);
    c.evidence.shortReason = shortInfo.invalid;
    check(c, 'Short reason rejected with message', shortInfo.invalid.some((m) => /reason/i.test(m)), shortInfo.invalid);
    await act(director, () => { const f = $('form[data-submit-once]'); fill(f, { 'settings[fees__accounting_policy_approved]': '1', reason: 'UAT: Board resolution UAT-2026-01 approves cash-basis fee income policy' }); submitForm(f, 'Save approval controls'); });
    const saved = await info(director);
    c.evidence.saveMessage = saved.alerts;
    c.evidence.dialog = director.lastDialog;
    check(c, 'Saved with audit confirmation', saved.alerts.some((a) => /1 approval control\(s\) updated and audited/.test(a)), saved.alerts);
    const after = await ev(director, () => $('[name="settings[fees__accounting_policy_approved]"]').value);
    check(c, 'Fee policy now 1 (Approved)', after === '1', after);

    const auditor = await as('auditor');
    step(c, 'Auditor filters the audit log by action approval_settings.changed and opens the entry');
    await go(auditor, '/audit-logs');
    await act(auditor, () => { const f = $('form.filter-bar'); fill(f, { event: { value: 'approval_settings.changed' } }); submitForm(f); });
    const auditRows = await ev(auditor, () => rows($('.app-content table')).filter((r) => r.some((cell) => cell.includes('approval_settings.changed'))));
    check(c, 'Audit row approval_settings.changed present', auditRows.length >= 1, auditRows[0]);
    await act(auditor, () => { $$('table a').find((a) => a.href.includes('/audit-logs/')).click(); });
    const props = await ev(auditor, () => rows($$('table')[1]));
    c.evidence.auditProperties = props;
    const flat = props.map((r) => r.join('=')).join(' | ');
    check(c, 'Entry shows key, old 0, new 1, reason and Director as actor', /fees\.accounting_policy_approved/.test(flat) && /=0\b/.test(flat) && /=1\b/.test(flat) && /Board resolution/.test(flat), flat);
    const actor = await ev(auditor, () => txt($$('tr').find((tr) => txt(tr.querySelector('th')) === 'Actor')?.querySelector('td')));
    check(c, 'Actor is UAT Director', /UAT Director/.test(actor ?? ''), actor);
});

// =============================================================== 2. FRC sync
await runCase(newCase('UAT-02', 'FRC sync, payment list totals, filters, scoping', 'Full sync → 4 enrollments / 10 payments; totals paid 18,000, cash 11,000, online 7,000, pending 1,500; re-sync no duplicates; filters work; no personal data; branch-B sees only B; cashier 403.'), async (c) => {
    const acc = await as('accountant');
    step(c, 'Accountant opens FRC Financial Integration');
    check(c, 'Overview opens', (await go(acc, '/frc-integration')) === 200);
    const syncOnce = async (mode, label) => {
        const click = async () => {
            await go(acc, '/frc-integration');
            acc.lastDialog = null;
            const before = results.consoleErrors.length;
            const status = await act(acc, (m) => { const f = $$('form').find((x) => x.querySelector('input[name=mode][value="' + m + '"]')); $('button', f).click(); }, mode);
            return { status, jsErrors: results.consoleErrors.slice(before).flatMap((e) => e.errors) };
        };
        let r = await click();
        if (r.status === 429) { c.evidence[`${label}Throttled`] = true; await sleep(61000); r = await click(); }
        const i = await info(acc);
        return { status: r.status, dialog: acc.lastDialog, jsErrors: r.jsErrors.map((e) => e.split('\n')[0]), alerts: i.alerts };
    };
    step(c, 'Click "Manual full sync" (a confirmation dialog is expected)');
    const full1 = await syncOnce('full', 'full1');
    c.evidence.fullSync1 = full1;
    check(c, 'Full sync completed with success message', full1.status === 200 && full1.alerts.some((a) => /Full FRC sync completed/.test(a)), full1.alerts);
    check(c, 'Confirmation dialog shown before the sync', /FULL FRC sync/.test(full1.dialog ?? ''), { dialog: full1.dialog, jsErrors: full1.jsErrors });
    if (!full1.dialog && full1.jsErrors.some((e) => /confirm is not a function/.test(e))) {
        defect(c, { page: '/frc-integration (Run a sync: "Incremental sync" and "Manual full sync" buttons)', steps: 'As accountant open FRC Financial Integration and click "Manual full sync"', expected: 'Browser confirmation "Run a manual FULL FRC sync? …" before anything runs; Cancel aborts', actual: 'No confirmation is shown; the inline handler throws "TypeError: confirm is not a function" (inside an inline onsubmit, `confirm` resolves to the form\'s hidden <input name="confirm">), so the sync is submitted immediately without confirmation', evidence: full1.jsErrors.join(' | ') });
    }
    const overview = await ev(acc, () => kpis());
    c.evidence.overviewKpis = overview;
    c.evidence.overviewScreenshot = await shot(acc, '02-frc-overview-after-sync');
    check(c, 'Overview: 4 enrollments, 10 payments mirrored', overview['Mirrored enrollments']?.value === '4' && overview['Mirrored payments']?.value === '10', overview);

    step(c, 'Open Sync history');
    await go(acc, '/frc-integration/sync-runs');
    const runs = await ev(acc, () => rows($('.app-content table')).slice(1).filter((r) => r.length > 10));
    c.evidence.syncRuns = runs.map((r) => ({ run: r[0], entity: r[1], mode: r[2], status: r[3], received: r[8], created: r[9], updated: r[10], unchanged: r[11], failed: r[12] }));
    const enr = c.evidence.syncRuns.find((r) => r.entity === 'Enrollments'); const pay = c.evidence.syncRuns.find((r) => r.entity === 'Payments');
    check(c, 'Sync history: enrollments 4 received/new, payments 10 received/new', enr?.received === '4' && enr?.created === '4' && pay?.received === '10' && pay?.created === '10', { enr, pay });

    step(c, 'Open Source payments (all branches)');
    await go(acc, '/frc-integration/payments');
    const totals = await ev(acc, () => ({ kpis: kpis(), rows: rows($('.app-content table')).slice(1) }));
    c.evidence.paymentListKpis = totals.kpis;
    c.evidence.paymentListScreenshot = await shot(acc, '02-frc-payment-list-totals');
    ctx.paymentRows = totals.rows;
    ctx.paymentLinks = await ev(acc, () => Object.fromEntries($$('.app-content table tbody tr').map((tr) => [txt(tr.cells[2]), tr.querySelector('a')?.getAttribute('href')])));
    const k = totals.kpis;
    check(c, 'Records = 10 (all statuses; includes the cancelled-enrollment payment)', k.Records?.value === '10', k.Records);
    check(c, 'Paid total = 18,000.00', /18,000\.00/.test(k['Paid total']?.value), k['Paid total']);
    check(c, 'Cash = 11,000.00', /11,000\.00/.test(k.Cash?.value), k.Cash);
    check(c, 'Online / bank = 7,000.00', /7,000\.00/.test(k['Online / bank']?.value), k['Online / bank']);
    check(c, 'Pending verification = 1,500.00 (1 payment)', /1,500\.00/.test(k['Pending verification']?.value) && /1 payments/.test(k['Pending verification']?.status), k['Pending verification']);
    if (!/18,000\.00/.test(k['Paid total']?.value)) {
        defect(c, { page: '/frc-integration/payments', steps: 'Full sync, open Source payments with no filters', expected: 'Paid total 18,000.00 and Cash 11,000.00 — payment 9010 (500, cash) belongs to CANCELLED enrollment 504 and must be excluded from paid totals (Finance Report rule)', actual: `Paid total ${k['Paid total']?.value}, Cash ${k.Cash?.value}`, evidence: 'screens/02-frc-payment-list-totals.png; FrcPaymentListService::summary() sums every live paid row without checking enrollment status' });
    }

    step(c, 'Incremental sync, then a second full sync');
    const inc = await syncOnce('incremental', 'incremental');
    c.evidence.incrementalSync = inc;
    check(c, 'Incremental sync completed', inc.status === 200 && inc.alerts.some((a) => /sync completed/i.test(a)), inc.alerts);
    const full2 = await syncOnce('full', 'full2');
    c.evidence.fullSync2 = full2;
    check(c, 'Second full sync completed', full2.status === 200 && full2.alerts.some((a) => /sync completed/i.test(a)), full2.alerts);
    await go(acc, '/frc-integration/sync-runs');
    const runs2 = await ev(acc, () => rows($('.app-content table')).slice(1).filter((r) => r.length > 10).map((r) => ({ run: r[0], entity: r[1], mode: r[2], status: r[3], received: r[8], created: r[9], updated: r[10], unchanged: r[11] })));
    c.evidence.syncRunsAfterResync = runs2;
    check(c, 'Re-syncs created no new rows', runs2.filter((r) => r.run !== enr?.run && r.run !== pay?.run).every((r) => r.created === '0'), runs2);
    await go(acc, '/frc-integration/payments');
    const after = await ev(acc, () => kpis().Records?.value);
    check(c, 'Still 10 payments (no duplicates)', after === '10', after);

    step(c, 'Filters: branch, method, status, receipt search');
    const filter = async (fields) => {
        await go(acc, '/frc-integration/payments');
        await act(acc, (f) => { const form = $$('form').find((x) => x.querySelector('[name=search]')); fill(form, f); submitForm(form); }, fields);
        return ev(acc, () => ({ count: kpis().Records?.value, paid: kpis()['Paid total']?.value, receipts: $$('.app-content table tbody tr').map((tr) => txt(tr.cells[1])), branches: [...new Set($$('.app-content table tbody tr').map((tr) => txt(tr.cells[3])))] }));
    };
    const fA = await filter({ branch_id: { text: 'UAT Branch A' } });
    const fB = await filter({ branch_id: { text: 'UAT Branch B' } });
    const fCash = await filter({ method: { value: 'cash' } });
    const fPending = await filter({ status: { value: 'pending_verification' } });
    const fSearch = await filter({ search: 'UAT-000003' });
    c.evidence.filters = { branchA: fA, branchB: fB, cash: fCash, pending: fPending, search: fSearch };
    check(c, 'Branch A filter: 6 rows, paid 14,000.00', fA.count === '6' && /14,000\.00/.test(fA.paid) && fA.branches.join() === 'UAT Branch A', fA);
    check(c, 'Branch B filter: 4 rows, only Branch B', fB.count === '4' && fB.branches.join() === 'UAT Branch B', fB);
    check(c, 'Method cash: 6 rows', fCash.count === '6', fCash);
    check(c, 'Status pending verification: 1 row', fPending.count === '1', fPending);
    check(c, 'Search UAT-000003: exactly that receipt', fSearch.count === '1' && fSearch.receipts[0] === 'UAT-000003', fSearch);

    step(c, 'Open payment detail of UAT-000003');
    await go(acc, ctx.paymentLinks['9003']);
    const detail = await ev(acc, () => ({ labels: $$('.app-content th').map(txt), text: txt($('.app-content')) }));
    const personal = detail.text.match(/(@[a-z]|cnic|phone|mobile|father|guardian|address|parent|student name|child name|dob|date of birth)/i);
    c.evidence.detailLabels = detail.labels.slice(0, 20);
    check(c, 'Payment detail shows no personal data fields', !personal, personal?.[0]);

    step(c, 'Branch-B accountant and cashier scoping');
    const bb = await as('branchb');
    await go(bb, '/frc-integration/payments');
    const bbView = await ev(bb, () => ({ count: kpis().Records?.value, branches: [...new Set($$('.app-content table tbody tr').map((tr) => txt(tr.cells[3])))], ids: $$('.app-content table tbody tr').map((tr) => txt(tr.cells[2])) }));
    c.evidence.branchBUser = bbView;
    check(c, 'Branch-B user sees only Branch B payments (4)', bbView.count === '4' && bbView.branches.join() === 'UAT Branch B', bbView);
    const cashier = await as('cashier');
    const cs = await go(cashier, '/frc-integration');
    const cp = await go(cashier, '/frc-integration/payments');
    check(c, 'Cashier refused FRC integration (403)', cs === 403 && cp === 403, { overview: cs, payments: cp });
});

// =============================================================== 3. Settlement mapping & posting
await runCase(newCase('UAT-03', 'Settlement mapping and cash-basis fee posting', 'Preview shows eligible lines and skip reasons; post creates FR journals; no double posting; reconciliation not reconciled until easypaisa B mapped, then reconciled.'), async (c) => {
    const acc = await as('accountant');
    const mapRow = async (branchName, method, accountText) => {
        await go(acc, '/fee-settlement-mappings');
        return act(acc, (b, m, t) => {
            const card = $$('.card').find((x) => txt($('h2', x)).startsWith(b));
            const form = $$('form', card).find((f) => f.querySelector('input[name=payment_method][value="' + m + '"]'));
            fill(form, { financial_account_id: t === '' ? { value: '' } : { text: t } });
            submitForm(form, 'Save');
        }, branchName, method, accountText);
    };
    step(c, 'Map Branch A: cash→UAT Cash A, card/bank_transfer/easypaisa→UAT Bank A');
    for (const [m, a] of [['cash', 'UAT Cash A'], ['card', 'UAT Bank A'], ['bank_transfer', 'UAT Bank A'], ['easypaisa', 'UAT Bank A']]) {
        const s = await mapRow('UAT Branch A', m, a);
        const i = await info(acc);
        check(c, `A ${m} → ${a}`, s === 200 && !i.alerts.some((x) => /alert-danger|invalid|must/i.test(x)) && i.invalid.length === 0, { s, alerts: i.alerts, invalid: i.invalid });
    }
    step(c, 'Map Branch B: cash→UAT Cash B, bank_transfer→UAT Bank B; leave easypaisa unmapped');
    for (const [m, a] of [['cash', 'UAT Cash B'], ['bank_transfer', 'UAT Bank B']]) {
        const s = await mapRow('UAT Branch B', m, a);
        check(c, `B ${m} → ${a}`, s === 200, s);
    }
    // A wrong-type mapping is refused (cash method to a bank account) if the screen offers it.
    const mappingState = await ev(acc, () => $$('.card').map((card) => ({ branch: txt($('h2', card)), rows: rows(card).slice(1).map((r) => r.slice(0, 3)) })));
    c.evidence.mappingsAfter = mappingState;

    step(c, 'Open posting preview (all branches)');
    await go(acc, '/fee-posting/payments');
    const preview = await ev(acc, () => ({
        banners: $$('.alert').map(txt),
        cards: $$('.card .text-muted.small').map((e) => txt(e) + ': ' + txt(e.nextElementSibling)),
        rows: $$('form[action$="/fee-posting/payments"] table.table.align-top > tbody > tr').map((tr) => ({ receipt: txt(tr.cells[2]), branch: txt(tr.cells[3]), service: txt(tr.cells[4]), method: txt(tr.cells[5]), status: txt(tr.cells[6]), amount: txt(tr.cells[7]), selectable: !!tr.querySelector('input[name="ids[]"]'), plan: txt(tr.cells[8]) })),
    }));
    c.evidence.preview = preview;
    c.evidence.previewScreenshot = await shot(acc, '03-posting-preview-before');
    const eligible = preview.rows.filter((r) => r.selectable);
    const skipped = preview.rows.filter((r) => !r.selectable);
    check(c, 'Policy approved banner (basis cash)', preview.banners.some((b) => /basis: cash \(approved\)/i.test(b)), preview.banners);
    check(c, '6 eligible payments with journal lines (Dr cash/bank, Cr fee income)', eligible.length === 6 && eligible.every((r) => /Debit\s*Credit/.test(r.plan) && /^FR/.test(r.plan) && /(11101|11102|11201|11202) — UAT (Cash|Bank)/.test(r.plan) && /4110[23] — /.test(r.plan)), eligible.map((r) => r.receipt));
    const reasonOf = (frc) => skipped.find((r) => r.receipt.includes(`FRC #${frc}`))?.plan;
    c.evidence.skipReasons = { pending9008: reasonOf(9008), rejected9009: reasonOf(9009), cancelled9010: reasonOf(9010), easypaisaB9007: reasonOf(9007) };
    check(c, 'Skip reasons shown for pending, rejected, cancelled-enrollment, unmapped easypaisa B', ['9008', '9009', '9010', '9007'].every((id) => /Skipped/.test(reasonOf(id) ?? '')), c.evidence.skipReasons);
    const therapyLine = eligible.find((r) => r.receipt.includes('UAT-000001'))?.plan ?? '';
    const generalLine = eligible.find((r) => r.receipt.includes('UAT-000004'))?.plan ?? '';
    check(c, 'Speech Therapy credits 41102 UAT Speech Therapy Fees; School Readiness credits its own ledger 41103 UAT Day Programme Fees (never General Fee Income)', /41102 — UAT Speech Therapy Fees/.test(therapyLine) && /41103 — UAT Day Programme Fees/.test(generalLine) && !/41101/.test(generalLine) && /11101 — UAT Cash Branch A/.test(therapyLine), { therapyLine, generalLine });

    step(c, 'Select all eligible payments and post (confirm accepted)');
    await act(acc, () => { const f = $('form[action$="/fee-posting/payments"]'); $$('input[name="ids[]"]', f).forEach((b) => { b.checked = true; }); submitForm(f, 'Post selected payments'); });
    const posted = await ev(acc, () => $$('.card ul li').map(txt).filter((t) => /posted|skipped|failed/i.test(t)));
    c.evidence.postingResults = posted;
    check(c, '6 payments posted', posted.filter((t) => /^posted/i.test(t)).length === 6, posted);

    step(c, 'Posted fee receipts: voucher type FR');
    await go(acc, '/fee-posting/postings');
    const postings = await ev(acc, () => $$('.card table.align-top > tbody > tr').map((tr) => ({ record: txt(tr.cells[1]), branch: txt(tr.cells[2]), service: txt(tr.cells[3]), amount: txt(tr.cells[5]), voucher: txt(tr.cells[6]), status: txt(tr.cells[7]), journal: tr.cells[6]?.querySelector('a')?.getAttribute('href') })));
    c.evidence.postings = postings;
    ctx.postings = postings;
    check(c, '6 postings, every voucher FR…', postings.length === 6 && postings.every((p) => /^FR/.test(p.voucher)), postings.map((p) => p.voucher));
    if (postings[0]?.journal) {
        await go(acc, postings[0].journal);
        const j = await ev(acc, () => ({ title: txt($('.page-header h1')), type: txt($$('.surface-card small').find((s) => txt(s) === 'Type')?.nextElementSibling), status: txt($('.page-description')) }));
        c.evidence.firstFeeJournal = j;
        check(c, 'Fee journal type FR and Posted', j.type === 'FR' && /Posted/i.test(j.status), j);
    }

    step(c, 'Re-open preview and re-submit an already-posted payment id (crafted)');
    await go(acc, '/fee-posting/payments');
    const preview2 = await ev(acc, () => ({ selectable: $$('input[name="ids[]"]').length, rows: $$('form[action$="/fee-posting/payments"] table.table.align-top > tbody > tr').length }));
    c.evidence.previewAfterPost = preview2;
    check(c, 'Preview now offers nothing to post', preview2.selectable === 0, preview2);
    const postedPaymentId = idFrom(ctx.paymentLinks['9001'] ?? '', /payments\/(\d+)/);
    await act(acc, (id) => crafted('/fee-posting/payments', { 'ids[]': [String(id)] }), postedPaymentId);
    const repost = await ev(acc, () => [...$$('.card ul li').map(txt), ...$$('.alert').map(txt)]);
    c.evidence.repostAttempt = repost;
    await go(acc, '/fee-posting/postings');
    const count2 = await ev(acc, () => $$('.card table.align-top > tbody > tr').filter((tr) => tr.cells.length > 5).length);
    check(c, 'Still 6 postings (nothing posted twice)', count2 === 6, { count2, repost });

    step(c, 'Reconciliation before mapping easypaisa B');
    await go(acc, '/frc-integration/reconciliation');
    const rec1 = await reconciliation(acc);
    c.evidence.reconciliationBefore = rec1;
    c.evidence.reconciliationBeforeScreenshot = await shot(acc, '03-reconciliation-before');
    check(c, 'Not reconciled (unmapped/unposted easypaisa B remains)', /Not reconciled/.test(rec1.status), rec1.status);

    step(c, 'Map Branch B easypaisa → UAT Bank B, post it');
    await mapRow('UAT Branch B', 'easypaisa', 'UAT Bank B');
    await go(acc, '/fee-posting/payments');
    const elig2 = await ev(acc, () => $$('input[name="ids[]"]').length);
    check(c, 'Easypaisa B now eligible (1)', elig2 === 1, elig2);
    if (elig2) {
        await act(acc, () => { const f = $('form[action$="/fee-posting/payments"]'); $$('input[name="ids[]"]', f).forEach((b) => { b.checked = true; }); submitForm(f, 'Post selected payments'); });
        const r = await ev(acc, () => $$('.card ul li').map(txt));
        check(c, 'Easypaisa B posted', r.some((t) => /^posted/i.test(t)), r);
    }
    await go(acc, '/frc-integration/reconciliation');
    const rec2 = await reconciliation(acc);
    c.evidence.reconciliationAfter = rec2;
    c.evidence.reconciliationAfterScreenshot = await shot(acc, '03-reconciliation-after');
    ctx.reconciliationAfter = rec2;
    check(c, 'Reconciliation: total paid 18,000.00 (7 payments), cash 11,000.00, online 7,000.00, pending 1,500.00', /18,000\.00/.test(rec2.kpis['Total paid']?.value) && /11,000\.00/.test(rec2.kpis['Cash received']?.value) && /7,000\.00/.test(rec2.kpis['Online / bank received']?.value) && /1,500\.00/.test(rec2.kpis['Pending verification']?.value), rec2.kpis);
    check(c, 'Reconciliation status after all eligible posted', /^Reconciled/.test(rec2.status), rec2.status);
});

async function reconciliation(page) {
    return ev(page, () => ({
        status: txt($('.alert[role=status]')),
        kpis: kpis(),
        source: Object.fromEntries(rows($$('.app-content .row .surface-card')[0]).map((r) => [r[0], r[1]])),
        ledger: Object.fromEntries(rows($$('.app-content .row .surface-card')[1]).map((r) => [r[0], r[1]])),
        differences: rows($$('.app-content section.surface-card').find((s) => /Differences/.test(txt($('h2', s))))).map((r) => r.join(': ')),
        diffRows: rows($$('.app-content section.surface-card').find((s) => /Payments with differences/.test(txt($('h2', s))))).slice(1).map((r) => r.join(' | ')),
    }));
}

// =============================================================== 4. Ledger impact
async function statement(page, path, fields = {}) {
    await go(page, path);
    if (Object.keys(fields).length) await act(page, (f) => { const form = $('form.row'); fill(form, f); submitForm(form, 'Generate'); }, fields);
    return ev(page, () => ({ rows: rows($('.app-content table')).filter((r) => r.length), alerts: $$('.alert').map(txt), exports: $$('.page-actions a').map((a) => ({ text: txt(a), href: a.getAttribute('href') })) }));
}
const rowVal = (table, label, col = 1) => table.rows.find((r) => r[0]?.toLowerCase().includes(label.toLowerCase()))?.[col];

await runCase(newCase('UAT-04', 'Ledger impact of fee posting', 'TB balanced; I&E fee income 18,000 (Speech Therapy 13,000 / Day Programme 5,000); branch A 14,000, B 4,000; service analysis and dashboard consistent.'), async (c) => {
    const acc = await as('accountant');
    step(c, 'Generate Trial Balance FY 2026 as of 31 Dec 2026');
    await go(acc, '/reports/trial-balance');
    await act(acc, () => { const f = $('form.row'); fill(f, { fiscal_year_id: { re: '2026' }, as_of: '2026-12-31' }); submitForm(f, 'Generate Trial Balance'); });
    const tb = await ev(acc, () => ({ alerts: $$('.alert').map(txt), foot: rows($('.app-content table tfoot'))[0], rows: rows($('.app-content table tbody')) }));
    c.evidence.trialBalance = { totals: tb.foot, alerts: tb.alerts, fee: tb.rows.filter((r) => /^411|^111|^112/.test(r[0])) };
    c.evidence.trialBalanceScreenshot = await shot(acc, '04-trial-balance');
    ctx.tbUrl = (await info(acc)).url;
    check(c, 'Trial balance balanced (no integrity warning, debit = credit)', !tb.alerts.some((a) => /integrity/i.test(a)) && tb.foot?.[1] === tb.foot?.[2], tb.foot);

    step(c, 'Income & Expenditure (current fiscal year, consolidated)');
    const ie = await statement(acc, '/statements/income-expenditure');
    c.evidence.incomeExpenditure = ie.rows.filter((r) => /UAT|Total|Surplus|Deficit/.test(r[0]));
    c.evidence.incomeExpenditureScreenshot = await shot(acc, '04-income-expenditure');
    check(c, 'Total Income 18,000.00', money(rowVal(ie, 'Total Income')) === 18000, rowVal(ie, 'Total Income'));
    check(c, 'UAT Speech Therapy Fees 13,000.00', money(rowVal(ie, 'UAT Speech Therapy Fees')) === 13000, rowVal(ie, 'UAT Speech Therapy Fees'));
    check(c, 'UAT Day Programme Fees 5,000.00', money(rowVal(ie, 'UAT Day Programme Fees')) === 5000, rowVal(ie, 'UAT Day Programme Fees'));
    const ieA = await statement(acc, '/statements/income-expenditure', { branch_id: { text: 'UAT Branch A' } });
    const ieB = await statement(acc, '/statements/income-expenditure', { branch_id: { text: 'UAT Branch B' } });
    c.evidence.ieBranch = { A: rowVal(ieA, 'Total Income'), B: rowVal(ieB, 'Total Income') };
    check(c, 'I&E branch A 14,000.00 / branch B 4,000.00', money(c.evidence.ieBranch.A) === 14000 && money(c.evidence.ieBranch.B) === 4000, c.evidence.ieBranch);

    step(c, 'Branch-wise I&E and service analysis');
    const bie = await statement(acc, '/statements/branch-income-expenditure');
    c.evidence.branchIE = bie.rows.slice(0, 12);
    const bieText = bie.rows.map((r) => r.join(' | ')).join('\n');
    check(c, 'Branch-wise I&E shows 14,000.00 and 4,000.00 income', /14,000\.00/.test(bieText) && /4,000\.00/.test(bieText), bie.rows.slice(0, 6));
    const sa = await statement(acc, '/statements/service-analysis');
    c.evidence.serviceAnalysis = sa.rows.slice(0, 12);
    const saText = sa.rows.map((r) => r.join(' | ')).join('\n');
    check(c, 'Service analysis: Speech Therapy 13,000.00, School Readiness 5,000.00', /Speech Therapy[^\n]*13,000\.00/.test(saText) && /School Readiness[^\n]*5,000\.00/.test(saText), sa.rows.slice(0, 6));

    step(c, 'Dashboard figures');
    await go(acc, '/dashboard');
    const dash = await ev(acc, () => kpis());
    c.evidence.dashboard = dash;
    const incomeCard = Object.entries(dash).find(([label]) => /income/i.test(label));
    check(c, 'Dashboard income figure = 18,000.00', incomeCard && /18,000\.00/.test(incomeCard[1].value), incomeCard);
});

// =============================================================== 5. Fee reversal
await runCase(newCase('UAT-05', 'Fee receipt reversal', 'Reverse UAT-000001 (3,000) with a reason; original stays posted-reversed, reversal journal exists, I&E drops to 15,000, reconciliation shows the difference.'), async (c) => {
    const acc = await as('accountant');
    step(c, 'Posted fee receipts → reverse UAT-000001 (period October 2026, date today, reason)');
    await go(acc, '/fee-posting/postings');
    await act(acc, (today) => {
        const tr = $$('.card table.align-top > tbody > tr').find((r) => txt(r.cells[1]).startsWith('UAT-000001'));
        const f = $('form', tr);
        fill(f, { accounting_period_id: { text: 'October 2026' }, transaction_date: today, reason: 'UAT: receipt issued in error, reversed for test' });
        submitForm(f, 'Reverse');
    }, TODAY);
    const msg = await info(acc);
    c.evidence.reverseMessage = msg.alerts;
    const row = await ev(acc, () => { const tr = $$('.card table.align-top > tbody > tr').find((r) => txt(r.cells[1]).startsWith('UAT-000001')); return tr && { voucher: txt(tr.cells[6]), status: txt(tr.cells[7]), links: $$('a', tr.cells[6]).map((a) => ({ text: txt(a), href: a.getAttribute('href') })) }; });
    c.evidence.reversedRow = row;
    check(c, 'Posting status reversed and "Reversed by" voucher shown', row && /reversed/i.test(row.status) && /Reversed by/.test(row.voucher), row);
    if (row?.links?.[0]) {
        await go(acc, row.links[0].href);
        const orig = await ev(acc, () => ({ title: txt($('.page-header h1')), desc: txt($('.page-description')), lines: rows($('.app-content table')).slice(1, 3) }));
        c.evidence.originalJournal = orig;
        check(c, 'Original journal still exists with its voucher and original lines (status shows Reversed)', /^FR/.test(orig.title) && /Reversed|Posted/.test(orig.desc) && orig.lines.some((l) => /11101/.test(l[1]) && /3,000/.test(l[4])), orig);
    }
    if (row?.links?.[1]) {
        await go(acc, row.links[1].href);
        const rev = await ev(acc, () => ({ title: txt($('.page-header h1')), desc: txt($('.page-description')), lines: rows($('.app-content table')).slice(1, 3) }));
        c.evidence.reversalJournal = rev;
        check(c, 'Reversal journal exists (posted, opposite lines)', /Posted/.test(rev.desc), rev);
    }
    const ie = await statement(acc, '/statements/income-expenditure');
    c.evidence.ieTotalIncome = rowVal(ie, 'Total Income');
    check(c, 'I&E total income now 15,000.00', money(c.evidence.ieTotalIncome) === 15000, c.evidence.ieTotalIncome);
    await go(acc, '/frc-integration/reconciliation');
    const rec = await reconciliation(acc);
    c.evidence.reconciliation = { status: rec.status, ledger: rec.ledger, diffRows: rec.diffRows };
    ctx.reconciliationFinal = rec;
    check(c, 'Reconciliation reflects the reversal (reversed 3,000.00 / not reconciled)', /3,000\.00/.test(rec.ledger['Reversed amount'] ?? '') && /Not reconciled/.test(rec.status), { status: rec.status, reversed: rec.ledger['Reversed amount'] });
});

// =============================================================== 6. Journal lifecycle
async function newJournal(page, lines, narration) {
    await go(page, '/journals/create');
    return act(page, (ls, n, today) => {
        const f = $('form[data-journal-form]');
        fill(f, { voucher_type_id: { re: '^JV' }, transaction_date: today, branch_id: { text: 'UAT Branch A' }, fiscal_year_id: { re: '2026' }, accounting_period_id: { text: 'October 2026' }, narration: n });
        ls.forEach((l, i) => { fill(f, { [`lines[${i}][account_id]`]: { re: '^' + l.code + ' ' }, [`lines[${i}][debit]`]: l.debit ?? '0', [`lines[${i}][credit]`]: l.credit ?? '0' }); });
        submitForm(f, 'Save Draft');
    }, lines, narration, TODAY);
}

await runCase(newCase('UAT-06', 'Manual journal lifecycle and cash/bank guard', 'Cash-credit journal refused with guard message; Dr Utilities / Cr Trade Creditors saved, submitted, not approvable by maker, approved by approver, posted by accountant with voucher number.'), async (c) => {
    const acc = await as('accountant');
    step(c, 'Create journal Dr 51104 UAT Utilities 1,000 / Cr 11101 UAT Cash Branch A 1,000');
    await newJournal(acc, [{ code: '51104', debit: '1000' }, { code: '11101', credit: '1000' }], 'UAT guard test — expense paid from cash');
    const g0 = await info(acc);
    const guardMsg = (m) => /cannot credit a cash, bank or petty cash ledger/i.test(m);
    let refusedAt = 'save';
    let g = g0;
    if (/\/journals\/\d+/.test(g0.url) && !g0.alerts.some(guardMsg)) {
        step(c, 'Draft was accepted; submit it (guard expected at submit)');
        refusedAt = 'submit';
        await act(acc, () => submitForm(buttonForm('Submit'), 'Submit'));
        g = await info(acc);
    }
    const visible = await ev(acc, () => txt($('.app-content')));
    const status = await ev(acc, () => txt($('.page-description')));
    c.evidence.guard = { refusedAt, saveResult: g0.alerts, url: g.url, alerts: g.alerts, invalid: g.invalid, journalStatus: status };
    const shown = [...g.alerts, ...g.invalid].some(guardMsg) || guardMsg(visible);
    check(c, 'Cash-crediting journal not submitted (stays Draft)', /Draft/.test(status) || refusedAt === 'save', status);
    check(c, 'Guard message visible to the user', shown, c.evidence.guard);
    if (!shown && /Draft/.test(status)) defect(c, { page: '/journals/{id} (Submit)', steps: 'Accountant creates a manual journal Dr 51104 UAT Utilities / Cr 11101 UAT Cash Branch A, saves draft, clicks Submit', expected: 'Refusal with the message "A manual journal cannot credit a cash, bank or petty cash ledger …"', actual: `Submission refused but the page shows only: ${g.alerts.join(' / ')} — the guard message (error key "lines") is not rendered on the journal page`, evidence: JSON.stringify(c.evidence.guard) });

    step(c, 'Create journal Dr 51104 UAT Utilities 2,500 / Cr 21101 UAT Trade Creditors 2,500 and submit');
    await newJournal(acc, [{ code: '51104', debit: '2500' }, { code: '21101', credit: '2500' }], 'UAT utilities bill accrued');
    const j = await info(acc);
    ctx.journalId = idFrom(j.url, /journals\/(\d+)/);
    check(c, 'Draft saved', ctx.journalId > 0 && /Draft/.test(j.heading), j.heading);
    await act(acc, () => submitForm(buttonForm('Submit'), 'Submit'));
    const sub = await ev(acc, () => ({ desc: txt($('.page-description')), approve: !!buttonForm('Approve') }));
    check(c, 'Submitted; accountant has no Approve button', /Submitted/.test(sub.desc) && !sub.approve, sub);
    await act(acc, (id) => crafted(`/journals/${id}/approve`), ctx.journalId);
    const selfApprove = await info(acc);
    c.evidence.selfApprove = { status: acc.documentStatus, text: selfApprove.bodyStart.slice(0, 120), alerts: selfApprove.alerts };
    check(c, 'Accountant approve request refused (403)', acc.documentStatus === 403, c.evidence.selfApprove);

    const approver = await as('approver');
    step(c, 'Approver approves');
    await go(approver, `/journals/${ctx.journalId}`);
    await act(approver, () => submitForm(buttonForm('Approve'), 'Approve'));
    const ap = await ev(approver, () => txt($('.page-description')));
    check(c, 'Approved by approver', /Approved/.test(ap), ap);

    step(c, 'Accountant posts (confirm accepted)');
    await go(acc, `/journals/${ctx.journalId}`);
    await act(acc, () => submitForm(buttonForm('Post Journal'), 'Post Journal'));
    const posted = await ev(acc, () => ({ title: txt($('.page-header h1')), desc: txt($('.page-description')) }));
    c.evidence.posted = posted;
    check(c, 'Posted with JV voucher number', /Posted/.test(posted.desc) && /^JV/.test(posted.title), posted);
});

// =============================================================== 7. High-value payments
async function newTransaction(page, type, fields) {
    await go(page, `/finance/${type}/create`);
    await act(page, (f) => { const form = $('form[action$="/finance"]'); fill(form, f); submitForm(form, 'Save draft'); }, fields);
    const i = await info(page);
    return { ...i, id: idFrom(i.url, /transaction\/(\d+)/) };
}
const txState = (page) => ev(page, () => ({ status: txt($$('dt').find((d) => txt(d) === 'Status')?.nextElementSibling), journal: txt($$('dt').find((d) => txt(d) === 'Journal')?.nextElementSibling), actions: $$('.col-lg-5 button, .col-lg-5 .status-badge').map(txt), alerts: $$('.alert').map(txt), invalid: $$('.app-content .invalid-feedback').map(txt) }));
const clickTx = (page, text) => act(page, (t) => submitForm(buttonForm(t), t), text);

await runCase(newCase('UAT-07', 'High-value payment requires Director', '150,000.01 cash payment: admin/approver cannot approve; director approves; accountant disburses and posts. 150,000.00 approvable by approver.'), async (c) => {
    const acc = await as('accountant');
    step(c, 'Fund UAT Cash A: cash receipt 400,000.00 against 31101 UAT Opening Fund, post');
    const rc = await newTransaction(acc, 'cash_receipt', { branch_id: { text: 'UAT Branch A' }, transaction_date: TODAY, amount: '400000', financial_account_id: { text: 'UAT Cash A' }, contra_account_id: { re: '^31101' }, party_name: 'UAT Fund transfer', narration: 'UAT funding of cash for payment tests' });
    check(c, 'Cash receipt draft saved', rc.id > 0, rc.alerts.concat(rc.invalid));
    await clickTx(acc, 'Post to Journal Engine');
    const rcs = await txState(acc);
    c.evidence.funding = rcs;
    check(c, 'Cash receipt posted (CR voucher)', /posted/.test(rcs.status) && /^CR/.test(rcs.journal), rcs);

    step(c, 'Cash payment 150,000.01 from UAT Cash A (Dr 51101 UAT Supplies), submit');
    const p = await newTransaction(acc, 'cash_payment', { branch_id: { text: 'UAT Branch A' }, transaction_date: TODAY, amount: '150000.01', financial_account_id: { text: 'UAT Cash A' }, contra_account_id: { re: '^51101' }, party_name: 'UAT Equipment vendor', narration: 'UAT high-value payment' });
    ctx.highPayment = p.id;
    check(c, 'Draft saved', p.id > 0, p.alerts.concat(p.invalid));
    await clickTx(acc, 'Submit for approval');
    check(c, 'Submitted', /submitted/.test((await txState(acc)).status));
    for (const role of ['admin', 'approver']) {
        const pg = await as(role);
        await go(pg, `/finance/transaction/${p.id}`);
        const s = await txState(pg);
        c.evidence[`${role}View`] = s;
        check(c, `${role}: no Approve button, shows "Awaiting Director approval"`, !s.actions.includes('Approve') && s.actions.some((a) => /Awaiting Director approval/.test(a)), s.actions);
        await act(pg, (id) => crafted(`/finance/transaction/${id}/approve`), p.id);
        const after = await info(pg);
        const st = await txState(pg);
        c.evidence[`${role}ApproveAttempt`] = { status: pg.documentStatus, alerts: after.alerts, invalid: after.invalid, txStatus: st.status, text: after.bodyStart.slice(0, 100) };
        check(c, `${role}: approve attempt refused, still submitted`, pg.documentStatus === 403 || (/submitted/.test(st.status ?? '') && (after.alerts.length || after.invalid.length)), c.evidence[`${role}ApproveAttempt`]);
    }
    const dir = await as('director');
    step(c, 'Director approves');
    await go(dir, `/finance/transaction/${p.id}`);
    await clickTx(dir, 'Approve');
    const ds = await txState(dir);
    check(c, 'Director approved', /approved/.test(ds.status), ds);
    step(c, 'Accountant confirms disbursement and posts');
    await go(acc, `/finance/transaction/${p.id}`);
    await clickTx(acc, 'Confirm disbursement');
    const dis = await txState(acc);
    check(c, 'Disbursed', /disbursed/.test(dis.status), dis);
    await clickTx(acc, 'Post to Journal Engine');
    const ps = await txState(acc);
    c.evidence.highPosted = ps;
    check(c, 'Posted (CP voucher)', /posted/.test(ps.status) && /^CP/.test(ps.journal), ps);

    step(c, 'Cash payment of exactly 150,000.00 approved by approver');
    const q = await newTransaction(acc, 'cash_payment', { branch_id: { text: 'UAT Branch A' }, transaction_date: TODAY, amount: '150000.00', financial_account_id: { text: 'UAT Cash A' }, contra_account_id: { re: '^51101' }, party_name: 'UAT Supplier', narration: 'UAT threshold payment' });
    await clickTx(acc, 'Submit for approval');
    const apr = await as('approver');
    await go(apr, `/finance/transaction/${q.id}`);
    const qa = await txState(apr);
    check(c, 'Approver sees Approve button at exactly the threshold', qa.actions.includes('Approve'), qa.actions);
    if (qa.actions.includes('Approve')) { await clickTx(apr, 'Approve'); }
    const qs = await txState(apr);
    check(c, 'Approver approved 150,000.00', /approved/.test(qs.status), qs);
    await go(acc, `/finance/transaction/${q.id}`);
    await clickTx(acc, 'Confirm disbursement');
    await clickTx(acc, 'Post to Journal Engine');
    const qp = await txState(acc);
    check(c, '150,000.00 payment posted', /posted/.test(qp.status), qp);
});

// =============================================================== 8. Purchasing
await runCase(newCase('UAT-08', 'Purchase order above threshold, bill, partial payment, overpayment refused', 'PO 200,000 approvable only by Director; bill from PO posted; partial vendor payment posted; overpayment refused.'), async (c) => {
    const acc = await as('accountant');
    step(c, 'Accountant creates PO: UAT Medical Supplies Co, Branch A, 1 × 200,000.00 (51101), submits');
    await go(acc, '/purchase-orders/create');
    await act(acc, () => { const f = $('form[action$="/purchase-orders"]'); fill(f, { vendor_id: { text: 'UAT Medical Supplies' }, branch_id: { text: 'UAT Branch A' }, reference: 'UAT-Q-1', 'lines[0][description]': 'UAT therapy equipment', 'lines[0][quantity]': '1', 'lines[0][unit_price]': '200000', 'lines[0][account_id]': { re: '^51101' } }); submitForm(f, 'Save draft'); });
    const po = await info(acc);
    ctx.poId = idFrom(po.url, /purchase-orders\/(\d+)/);
    check(c, 'PO draft saved', ctx.poId > 0, [po.url, ...po.alerts, ...po.invalid]);
    await act(acc, () => submitForm(buttonForm('Submit for approval'), 'Submit for approval'));
    const apr = await as('approver');
    await go(apr, `/purchase-orders/${ctx.poId}`);
    const aView = await ev(apr, () => $$('.app-content .status-badge, .app-content button').map(txt));
    check(c, 'Approver: no Approve, "Awaiting Director approval"', !aView.includes('Approve') && aView.some((t) => /Awaiting Director approval/.test(t)), aView);
    await act(apr, (id) => crafted(`/purchase-orders/${id}/approve`), ctx.poId);
    const aTry = await info(apr);
    c.evidence.approverPoAttempt = { status: apr.documentStatus, alerts: aTry.alerts };
    check(c, 'Approver approve attempt refused', apr.documentStatus === 403 || aTry.alerts.some((a) => /director|not authori|cannot/i.test(a)), c.evidence.approverPoAttempt);
    const dir = await as('director');
    step(c, 'Director approves the PO');
    await go(dir, `/purchase-orders/${ctx.poId}`);
    await act(dir, () => submitForm(formByAction('/approve$'), 'Approve purchase order'));
    const dv = await ev(dir, () => txt($$('.status-badge').find((b) => /Approved|Issued|Submitted/.test(txt(b)))));
    check(c, 'PO approved by Director', /Approved/.test(dv), dv);

    step(c, 'Accountant: Create bill from PO, submit');
    await go(acc, `/purchase-orders/${ctx.poId}`);
    await act(acc, () => $$('a').find((a) => txt(a) === 'Create bill from PO').click());
    await act(acc, (today) => { const f = $('form[action$="/purchase-bills"]'); fill(f, { bill_number: 'UAT-INV-001', invoice_date: today, description: 'UAT bill from PO' }); submitForm(f, 'Save draft'); }, TODAY);
    const bill = await info(acc);
    ctx.billId = idFrom(bill.url, /purchase-bills\/(\d+)/);
    check(c, 'Bill draft saved from PO', ctx.billId > 0, [bill.url, ...bill.alerts, ...bill.invalid]);
    await act(acc, () => submitForm(buttonForm('Submit'), 'Submit'));
    let approverForBill = apr;
    await go(apr, `/purchase-bills/${ctx.billId}`);
    if (!(await ev(apr, () => !!buttonForm('Approve')))) approverForBill = dir;
    await go(approverForBill, `/purchase-bills/${ctx.billId}`);
    await act(approverForBill, () => submitForm(buttonForm('Approve'), 'Approve'));
    const ba = await info(approverForBill);
    c.evidence.billApproval = { by: approverForBill.user, alerts: ba.alerts, status: await ev(approverForBill, () => txt($('h2.h5'))) };
    check(c, 'Bill approved', /approved/.test(c.evidence.billApproval.status), c.evidence.billApproval);
    await go(acc, `/purchase-bills/${ctx.billId}`);
    await act(acc, () => submitForm(buttonForm('Post'), 'Post'));
    const bp = await ev(acc, () => ({ status: txt($('h2.h5')), outstanding: rows($('tfoot')).map((r) => r.join(' ')) }));
    c.evidence.billPosted = bp;
    check(c, 'Bill posted, outstanding 200,000.00', /posted/.test(bp.status) && bp.outstanding.some((r) => /Outstanding PKR 200,000\.00/.test(r)), bp);

    step(c, 'Partial vendor payment 50,000.00 from UAT Cash A');
    const vpFields = (amount) => ({ branch_id: { text: 'UAT Branch A' }, transaction_date: TODAY, amount, financial_account_id: { text: 'UAT Cash A' }, purchase_bill_id: { text: 'UAT-INV-001' }, narration: 'UAT vendor payment' });
    await go(acc, `/finance/vendor_payment/create?bill=${ctx.billId}`);
    await act(acc, (f) => { const form = $('form[action$="/finance"]'); fill(form, f); submitForm(form, 'Save draft'); }, vpFields('50000'));
    const vp = await info(acc);
    const vpId = idFrom(vp.url, /transaction\/(\d+)/);
    check(c, 'Vendor payment draft saved', vpId > 0, [vp.url, ...vp.alerts, ...vp.invalid]);
    await clickTx(acc, 'Submit for approval');
    await go(apr, `/finance/transaction/${vpId}`);
    await clickTx(apr, 'Approve');
    await go(acc, `/finance/transaction/${vpId}`);
    await clickTx(acc, 'Confirm disbursement');
    await clickTx(acc, 'Post to Journal Engine');
    const vps = await txState(acc);
    c.evidence.partialPayment = vps;
    check(c, 'Partial payment posted', /posted/.test(vps.status), vps);
    await go(acc, `/purchase-bills/${ctx.billId}`);
    const out = await ev(acc, () => rows($('tfoot')).map((r) => r.join(' ')));
    check(c, 'Bill outstanding now 150,000.00', out.some((r) => /Outstanding PKR 150,000\.00/.test(r)), out);

    step(c, 'Attempt overpayment 150,000.01 on the bill');
    await go(acc, `/finance/vendor_payment/create?bill=${ctx.billId}`);
    await act(acc, (f) => { const form = $('form[action$="/finance"]'); fill(form, f); submitForm(form, 'Save draft'); }, vpFields('150000.01'));
    let over = await info(acc);
    let overId = idFrom(over.url, /transaction\/(\d+)/);
    let refusal = [...over.alerts, ...over.invalid];
    c.evidence.overpaymentEntryPage = over.url;
    if (overId > 0) { // not refused at entry: try to take it through the workflow
        await clickTx(acc, 'Submit for approval');
        over = await info(acc); refusal = [...over.alerts, ...over.invalid];
        const st = await txState(acc);
        if (/submitted/.test(st.status)) { await go(apr, `/finance/transaction/${overId}`); await clickTx(apr, 'Approve'); over = await info(apr); refusal = [...over.alerts, ...over.invalid]; }
    }
    c.evidence.overpayment = { savedAsDraftId: overId || null, url: over.url, messages: refusal };
    await go(acc, `/purchase-bills/${ctx.billId}`);
    const out2 = await ev(acc, () => rows($('tfoot')).map((r) => r.join(' ')));
    c.evidence.overpayment.billOutstandingAfter = out2;
    check(c, 'Overpayment not recorded (no draft created, outstanding unchanged 150,000.00)', !overId && out2.some((r) => /Outstanding PKR 150,000\.00/.test(r)), c.evidence.overpayment);
    const specific = refusal.some((m) => /outstanding|exceed|more than|overpay/i.test(m));
    check(c, 'Specific refusal reason visible to the user', specific, refusal);
    if (!specific && !overId) defect(c, { page: '/finance/vendor_payment/create?bill={id}', steps: 'Posted bill 200,000 with 50,000 paid; create vendor payment of 150,000.01 against it, Save draft', expected: 'Refused with a visible reason (amount exceeds the outstanding 150,000.00)', actual: `Refused, but the form shows only "${refusal.join(' / ')}" — the transaction create view renders no field error messages, so the user is not told why`, evidence: JSON.stringify(c.evidence.overpayment) });
});

// =============================================================== 9. Donation
await runCase(newCase('UAT-09', 'Donation receipt', 'Donation (General Donation) recorded and posted with DR voucher.'), async (c) => {
    const acc = await as('accountant');
    step(c, 'Create donation 5,000.00 to UAT Cash A, category General Donation, post');
    const d = await newTransaction(acc, 'donation', { branch_id: { text: 'UAT Branch A' }, transaction_date: TODAY, amount: '5000', financial_account_id: { text: 'UAT Cash A' }, donation_category_id: { re: 'General' }, party_name: 'UAT Anonymous Donor', narration: 'UAT general donation' });
    check(c, 'Donation draft saved', d.id > 0, [d.url, ...d.alerts, ...d.invalid]);
    await clickTx(acc, 'Post to Journal Engine');
    const s = await txState(acc);
    c.evidence.donation = s;
    check(c, 'Posted with DR voucher', /posted/.test(s.status) && /^DR/.test(s.journal), s);
});

// =============================================================== 10. Inventory
await runCase(newCase('UAT-10', 'Inventory receive/issue, over-issue refused, double-submit protection', 'Receive 10, issue 4, issue 7 refused, duplicate submit with the same key → one movement.'), async (c) => {
    const acc = await as('accountant');
    step(c, 'Create category, location (Branch A) and item');
    await go(acc, '/inventory/categories');
    await act(acc, () => { const f = formByAction('/inventory/categories$'); fill(f, { name: 'UAT Therapy Consumables' }); submitForm(f); });
    await go(acc, '/inventory/locations');
    await act(acc, () => { const f = formByAction('/inventory/locations$'); fill(f, { branch_id: { text: 'UAT Branch A' }, name: 'UAT Main Store' }); submitForm(f); });
    await go(acc, '/inventory/items/create');
    await act(acc, () => { const f = formByAction('/inventory/items$'); fill(f, { item_code: 'UAT-ITM-001', name: 'UAT Flash Cards', category_id: { text: 'UAT Therapy Consumables' }, unit_of_measure: 'pcs', minimum_stock_level: '2' }); submitForm(f, 'Create item'); });
    const it = await info(acc);
    check(c, 'Item created', !it.invalid.length && !it.alerts.some((a) => /danger|error|review/i.test(a)), [it.url, ...it.alerts]);
    const movement = async (kind, qty, extra = {}) => {
        await go(acc, `/inventory/${kind}`);
        await act(acc, (q, x, today) => { const f = $('form[data-submit-once]'); fill(f, { item_id: { text: 'UAT-ITM-001' }, location_id: { text: 'UAT Main Store' }, quantity: q, transaction_date: today, ...x }); submitForm(f); }, qty, extra, TODAY);
        return info(acc);
    };
    step(c, 'Receive 10');
    const r = await movement('receive', '10', { reference: 'UAT-GRN-1' });
    check(c, 'Receipt recorded', !r.invalid.length && !r.alerts.some((a) => /review|cannot|insufficient/i.test(a)), [r.url, ...r.alerts]);
    step(c, 'Issue 4 to a recipient');
    const i4 = await movement('issue', '4', { issued_to: 'UAT Therapy Room 1' });
    check(c, 'Issue of 4 recorded', !i4.invalid.length && !i4.alerts.some((a) => /review|cannot|insufficient/i.test(a)), [i4.url, ...i4.alerts]);
    step(c, 'Issue 7 (only 6 on hand)');
    const i7 = await movement('issue', '7', { issued_to: 'UAT Therapy Room 2' });
    c.evidence.overIssue = { url: i7.url, alerts: i7.alerts, invalid: i7.invalid };
    check(c, 'Issue of 7 refused with message', /\/inventory\/issue/.test(i7.url) && [...i7.alerts, ...i7.invalid].some((m) => /stock|balance|available|insufficient|on hand/i.test(m)), c.evidence.overIssue);

    step(c, 'Double submit: same issue form (same hidden idempotency key) posted twice concurrently');
    await go(acc, '/inventory/movements');
    const before = await ev(acc, () => $$('.app-content table tbody tr').filter((tr) => tr.cells.length > 3).length);
    await go(acc, '/inventory/issue');
    const dbl = await ev(acc, async (today) => {
        const f = $('form[data-submit-once]');
        fill(f, { item_id: { text: 'UAT-ITM-001' }, location_id: { text: 'UAT Main Store' }, quantity: '1', transaction_date: today, issued_to: 'UAT Double Submit' });
        const key = f.querySelector('[name=idempotency_key]').value;
        const send = () => fetch(f.action, { method: 'POST', body: new FormData(f), credentials: 'same-origin', redirect: 'follow' }).then((r) => r.status);
        const statuses = await Promise.all([send(), send()]);
        return { key, statuses };
    }, TODAY);
    await go(acc, '/inventory/movements');
    const afterRows = await ev(acc, () => $$('.app-content table tbody tr').filter((tr) => tr.cells.length > 3).map((tr) => txt(tr)));
    c.evidence.doubleSubmit = { ...dbl, movementsBefore: before, movementsAfter: afterRows.length, doubleRows: afterRows.filter((t) => /Double Submit/.test(t)).length };
    check(c, 'Only one movement created for the duplicated key', c.evidence.doubleSubmit.doubleRows === 1 || afterRows.length - before === 1, c.evidence.doubleSubmit);
    await go(acc, '/inventory/balances');
    const bal = await ev(acc, () => $$('.app-content table tbody tr').map((tr) => txt(tr)).filter((t) => /UAT-ITM-001/.test(t)));
    c.evidence.balance = bal;
    check(c, 'Balance 5 (10 − 4 − 1)', bal.some((t) => /\b5(\.0+)?\b/.test(t)), bal);
});

// =============================================================== 11. Policy safeguards
await runCase(newCase('UAT-11', 'Depreciation and payroll posting blocked while policy unapproved', 'Depreciation run approved but posting blocked; payroll run approved but posting blocked; board/cashier see no salary amounts.'), async (c) => {
    const acc = await as('accountant');
    const apr = await as('approver');
    step(c, 'Asset category (12101 / 12102 / 51103) and asset 120,000.00 in service 01 Jan 2026, activate');
    await go(acc, '/asset-categories/create');
    await act(acc, () => { const f = formByAction('/asset-categories$'); fill(f, { code: 'UAT-EQ', name: 'UAT Equipment', default_useful_life_months: '60', asset_account_id: { re: '12101' }, accumulated_depreciation_account_id: { re: '12102' }, depreciation_expense_account_id: { re: '51103' } }); submitForm(f, 'Create category'); });
    const cat = await info(acc);
    check(c, 'Asset category created', !cat.invalid.length, [cat.url, ...cat.alerts, ...cat.invalid]);
    await go(acc, '/fixed-assets/create');
    await act(acc, () => { const f = formByAction('/fixed-assets$'); fill(f, { name: 'UAT Audiometer', category_id: { re: 'UAT Equipment' }, branch_id: { text: 'UAT Branch A' }, acquisition_date: '2026-01-01', in_service_date: '2026-01-01', useful_life_months: '60', acquisition_cost: '120000', residual_value: '0', opening_accumulated_depreciation: '0' }); submitForm(f, 'Save draft'); });
    const asset = await info(acc);
    check(c, 'Asset saved', /fixed-assets\/\d+/.test(asset.url) && !asset.invalid.length, [asset.url, ...asset.alerts, ...asset.invalid]);
    if (await ev(acc, () => !!buttonForm('Activate asset'))) await act(acc, () => submitForm(buttonForm('Activate asset'), 'Activate asset'));
    c.evidence.assetStatus = await ev(acc, () => $$('.app-content .status-badge').map(txt).slice(0, 3));
    step(c, 'Prepare depreciation run Branch A, October 2026; save draft; submit');
    await go(acc, '/depreciation-runs/create');
    await act(acc, () => { const f = $('form[method=GET]'); fill(f, { branch_id: { text: 'UAT Branch A' }, accounting_period_id: { text: 'October 2026' } }); submitForm(f, 'Preview depreciation'); });
    const prev = await ev(acc, () => ({ banner: $$('.alert-warning').map(txt), rows: rows($('.app-content table')).slice(1, 3) }));
    c.evidence.depreciationPreview = prev;
    await act(acc, () => submitForm(formByAction('/depreciation-runs$'), 'Save as draft run'));
    const run = await info(acc);
    const runId = idFrom(run.url, /depreciation-runs\/(\d+)/);
    check(c, 'Draft depreciation run saved', runId > 0, [run.url, ...run.alerts]);
    await act(acc, () => submitForm(buttonForm('Recalculate and submit'), 'Recalculate and submit'));
    await go(apr, `/depreciation-runs/${runId}`);
    await act(apr, () => submitForm(buttonForm('Approve'), 'Approve'));
    await go(acc, `/depreciation-runs/${runId}`);
    const dv = await ev(acc, () => ({ badges: $$('.app-content .status-badge').map(txt), post: !!buttonForm('Post to General Ledger'), banner: $$('.alert-warning').map(txt) }));
    c.evidence.depreciationRunView = dv;
    check(c, 'Approved run shows "Posting blocked — depreciation policy not approved", no Post button', dv.badges.some((b) => /Posting blocked/.test(b)) && !dv.post, dv);
    await act(acc, (id) => crafted(`/depreciation-runs/${id}/post`), runId);
    const dpost = await info(acc);
    c.evidence.depreciationPostAttempt = { status: acc.documentStatus, alerts: dpost.alerts, invalid: dpost.invalid };
    check(c, 'Direct post request blocked with policy message', [...dpost.alerts, ...dpost.invalid].some((m) => /policy/i.test(m)) || acc.documentStatus === 403, c.evidence.depreciationPostAttempt);

    step(c, 'Payroll: component (if none), employee, salary structure, run, validate, submit, approve, try to post');
    await go(acc, '/payroll/components');
    const comps = await ev(acc, () => rows($('.app-content table')).length);
    if (comps < 2) await act(acc, () => { const f = formByAction('/payroll/components$'); fill(f, { code: 'UAT-BASIC', name: 'UAT Basic Salary', type: { re: 'Earning' } }); submitForm(f, 'Add component'); });
    await go(acc, '/payroll/employees/create');
    await act(acc, () => { const f = formByAction('/payroll/employees$'); fill(f, { employee_code: 'UAT-EMP-001', name: 'UAT Employee One', branch_id: { text: 'UAT Branch A' }, designation: 'Therapist', joined_on: '2026-01-01' }); submitForm(f, 'Create employee'); });
    const emp = await info(acc);
    const empId = idFrom(emp.url, /employees\/(\d+)/);
    check(c, 'Employee created', empId > 0, [emp.url, ...emp.alerts, ...emp.invalid]);
    await go(acc, `/payroll/employees/${empId}`);
    await act(acc, () => { const f = formByAction('/salary$'); const first = $('input[name^="amounts["]', f); first.value = '50000'; submitForm(f, 'Save salary structure'); });
    await go(acc, '/payroll/runs/create');
    await act(acc, () => { const f = formByAction('/payroll/runs$'); fill(f, { branch_id: { text: 'UAT Branch A' }, accounting_period_id: { text: 'October 2026' } }); submitForm(f, 'Create draft run'); });
    const pr = await info(acc);
    const prId = idFrom(pr.url, /runs\/(\d+)/);
    check(c, 'Payroll draft run created', prId > 0, [pr.url, ...pr.alerts, ...pr.invalid]);
    ctx.payrollRunId = prId; ctx.employeeId = empId;
    const lines = await ev(acc, () => txt($('.app-content')).includes('UAT Employee One'));
    if (!lines && (await ev(acc, () => !!formByAction('/lines$')))) await act(acc, () => { const f = formByAction('/lines$'); fill(f, { employee_id: { text: 'UAT Employee One' } }); submitForm(f, 'Add line'); });
    await act(acc, () => submitForm(buttonForm('Validate'), 'Validate'));
    await act(acc, () => submitForm(buttonForm('Submit for approval'), 'Submit for approval'));
    await go(apr, `/payroll/runs/${prId}`);
    await act(apr, () => submitForm(formByAction('/approve$'), 'Approve payroll run'));
    await go(acc, `/payroll/runs/${prId}`);
    const pv = await ev(acc, () => ({ badges: $$('.app-content .status-badge').map(txt), post: !!buttonForm('Post accrual') }));
    c.evidence.payrollRunView = pv;
    check(c, 'Approved payroll run: "Posting blocked — payroll policy not approved", no Post button', pv.badges.some((b) => /Posting blocked/.test(b)) && !pv.post, pv);
    await act(acc, (id) => crafted(`/payroll/runs/${id}/post`), prId);
    const ppost = await info(acc);
    c.evidence.payrollPostAttempt = { status: acc.documentStatus, alerts: ppost.alerts, invalid: ppost.invalid };
    check(c, 'Direct payroll post blocked with policy message', [...ppost.alerts, ...ppost.invalid].some((m) => /policy/i.test(m)) || acc.documentStatus === 403, c.evidence.payrollPostAttempt);

    step(c, 'Board and cashier views of payroll');
    const board = await as('board');
    const bs = await go(board, `/payroll/runs/${prId}`);
    const boardText = await ev(board, () => txt($('.app-content')));
    const be = await go(board, `/payroll/employees/${empId}`);
    const boardEmp = await ev(board, () => txt($('.app-content')));
    c.evidence.board = { runStatus: bs, employeeStatus: be, runShows50000: /50,000/.test(boardText), employeeShows50000: /50,000/.test(boardEmp) };
    // Management (the board account) holds payroll.view_sensitive by design; whether it should is open client decision (MILESTONE_8_QA_REPORT §7).
    c.evidence.board.note = 'Management role holds payroll.view_sensitive: on-screen amounts visible by current role design (open client decision); exports and posting are refused below.';
    check(c, 'Board (Management) on-screen salary visibility matches its payroll.view_sensitive grant (open client decision)', /50,000/.test(boardText) && /50,000/.test(boardEmp), c.evidence.board);
    if (/50,000/.test(boardText) || /50,000/.test(boardEmp)) defect(c, { page: `/payroll/runs/${prId}, /payroll/employees/${empId}`, steps: 'Accountant creates employee with salary 50,000 and a payroll run; log in as uat.board (Management) and open the run and the employee', expected: 'Board/Management sees no salary amounts', actual: 'Salary amount 50,000 visible to Management (role holds payroll.view_sensitive per RolePermissionSeeder milestone 7 read-only set)', evidence: JSON.stringify(c.evidence.board) });
    const cashier = await as('cashier');
    const cs = [await go(cashier, '/payroll/runs'), await go(cashier, `/payroll/runs/${prId}`), await go(cashier, `/payroll/employees/${empId}`)];
    check(c, 'Cashier has no payroll access (403)', cs.every((s) => s === 403), cs);
});

// =============================================================== 12. Reports & exports
async function exportCheck(page, href, format) {
    return ev(page, async (url) => {
        const r = await fetch(url, { credentials: 'same-origin' });
        const buf = await r.arrayBuffer();
        const head = new TextDecoder('latin1').decode(buf.slice(0, 8));
        const text = /csv|text/.test(r.headers.get('content-type') ?? '') ? new TextDecoder().decode(buf) : null;
        return { status: r.status, type: r.headers.get('content-type'), disposition: r.headers.get('content-disposition'), size: buf.byteLength, head, text: text?.slice(0, 20000) };
    }, href);
}

await runCase(newCase('UAT-12', 'Report exports (CSV/XLSX/PDF) through the UI links', 'Every export 200, correct content type, PDF %PDF, XLSX PK, CSV contains totals.'), async (c) => {
    const acc = await as('accountant');
    const targets = [];
    await go(acc, ctx.tbUrl ?? '/reports/trial-balance');
    targets.push(['trial-balance', await ev(acc, () => $$('.page-actions a').map((a) => a.href).filter((h) => /export/.test(h)))]);
    const tbFoot = await ev(acc, () => rows($('.app-content table tfoot'))[0]);
    await go(acc, '/statements/income-expenditure');
    targets.push(['income-expenditure', await ev(acc, () => $$('.page-actions a').map((a) => a.href).filter((h) => /export/.test(h)))]);
    const ieTotal = await ev(acc, () => rows($('.app-content table')).find((r) => r[0] === 'Total Income')?.[1]);
    await go(acc, '/frc-integration/reconciliation');
    targets.push(['frc-reconciliation', await ev(acc, () => $$('.page-actions a').map((a) => a.href).filter((h) => /export/.test(h)))]);
    const recPaid = ctx.reconciliationFinal?.source?.['Total paid'];
    c.evidence.expectations = { tbTotals: tbFoot, ieTotalIncome: ieTotal, reconciliationPaid: recPaid };
    c.evidence.exports = {};
    for (const [report, hrefs] of targets) {
        check(c, `${report}: 3 export links (CSV, Excel, PDF)`, hrefs.length === 3, hrefs);
        for (const href of hrefs) {
            const format = href.match(/export\/(csv|xlsx|pdf)/)?.[1];
            const r = await exportCheck(acc, href, format);
            const plainTotal = (v) => String(v ?? '').replace(/,/g, '');
            const csvHas = (v) => r.text && (r.text.includes(v) || r.text.includes(plainTotal(v)));
            const ok = r.status === 200 && (format === 'pdf' ? r.head.startsWith('%PDF') && /pdf/.test(r.type) : format === 'xlsx' ? r.head.startsWith('PK') && /spreadsheet|excel|octet/.test(r.type) : /csv|text/.test(r.type));
            let content = true;
            if (format === 'csv') content = report === 'trial-balance' ? csvHas(tbFoot?.[1]) : report === 'income-expenditure' ? csvHas(ieTotal) : csvHas(recPaid);
            c.evidence.exports[`${report}.${format}`] = { status: r.status, type: r.type, size: r.size, head: r.head.replace(/[^\x20-\x7e]/g, '.'), csvContainsTotal: format === 'csv' ? content : undefined };
            check(c, `${report}.${format}: 200 + type/signature${format === 'csv' ? ' + totals' : ''}`, ok && content, c.evidence.exports[`${report}.${format}`]);
        }
    }
});

// =============================================================== 13. Authorization by URL
await runCase(newCase('UAT-13', 'Authorization by direct URL', 'Branch-B user → 403 on branch-A payment/journal/fee posting; board cannot post/export payroll; cashier cannot open FRC integration.'), async (c) => {
    const bb = await as('branchb');
    const aPayment = ctx.paymentLinks?.['9001'];
    const aJournal = ctx.postings?.find((p) => p.branch === 'UAT Branch A')?.journal;
    const checks = {};
    checks.payment = await go(bb, aPayment);
    checks.journal = await go(bb, aJournal);
    checks.manualJournal = await go(bb, `/journals/${ctx.journalId}`);
    await go(bb, '/fee-posting/postings');
    const aPosting = ctx.postings?.find((p) => p.branch === 'UAT Branch A' && /posted/.test(p.status));
    // Reverse a branch-A fee posting (crafted POST): must be refused.
    const postingId = await (async () => { const acc = await as('accountant'); await go(acc, '/fee-posting/postings'); return ev(acc, () => { const tr = $$('.card table.align-top > tbody > tr').find((r) => txt(r.cells[2]) === 'UAT Branch A' && /posted/.test(txt(r.cells[7]))); return Number(tr?.querySelector('form')?.getAttribute('action')?.match(/postings\/(\d+)/)?.[1]); }); })();
    await act(bb, (id, today) => crafted(`/fee-posting/postings/${id}/reverse`, { accounting_period_id: '1', transaction_date: today, reason: 'Branch B trying' }), postingId, TODAY);
    checks.feePostingReverse = bb.documentStatus;
    const bbPostView = await go(bb, '/fee-posting/postings');
    const bbPostBranches = await ev(bb, () => [...new Set($$('.card table.align-top > tbody > tr').map((tr) => txt(tr.cells[2])).filter(Boolean))]);
    c.evidence.branchB = { ...checks, postingsListBranches: bbPostBranches, postingsListStatus: bbPostView, aPosting: aPosting?.voucher };
    check(c, 'Branch-B: branch-A FRC payment 403', checks.payment === 403, checks.payment);
    check(c, 'Branch-B: branch-A fee journal 403', checks.journal === 403, checks.journal);
    check(c, 'Branch-B: branch-A manual journal 403', checks.manualJournal === 403, checks.manualJournal);
    check(c, 'Branch-B: reversing a branch-A fee posting refused (403)', checks.feePostingReverse === 403, checks.feePostingReverse);
    check(c, 'Branch-B: posted fee list shows only Branch B', bbPostBranches.every((b) => b === 'UAT Branch B'), bbPostBranches);

    const board = await as('board');
    await go(board, '/dashboard');
    await act(board, (id) => crafted(`/payroll/runs/${id}/post`), ctx.payrollRunId ?? 1);
    const boardPost = board.documentStatus;
    const boardExport = await board.fetchInfo(`${BASE}/payroll-reports/register/export/csv`);
    const boardExportXlsx = await board.fetchInfo(`${BASE}/payroll-reports/register/export/xlsx`);
    c.evidence.board = { post: boardPost, exportCsv: boardExport.status, exportXlsx: boardExportXlsx.status };
    check(c, 'Board cannot post payroll (403)', boardPost === 403, boardPost);
    check(c, 'Board cannot export payroll (403)', boardExport.status === 403 && boardExportXlsx.status === 403, c.evidence.board);
    const cashier = await as('cashier');
    const cashierChecks = { overview: await go(cashier, '/frc-integration'), reconciliation: await go(cashier, '/frc-integration/reconciliation'), posting: await go(cashier, '/fee-posting/payments') };
    c.evidence.cashier = cashierChecks;
    check(c, 'Cashier cannot open FRC integration screens (403)', Object.values(cashierChecks).every((s) => s === 403), cashierChecks);
});

// =============================================================== 14. Responsive
await runCase(newCase('UAT-14', 'Responsive spot check at 390px', 'FRC payment list, posting preview and reconciliation: no horizontal page overflow, no JS errors.'), async (c) => {
    const acc = await as('accountant');
    await acc.viewport(390, 844, true);
    c.evidence.pages = {};
    for (const [name, path] of [['payments', '/frc-integration/payments'], ['preview', '/fee-posting/payments'], ['reconciliation', '/frc-integration/reconciliation']]) {
        acc.consoleErrors = [];
        const status = await acc.goto(BASE + path);
        const errors = [...acc.consoleErrors];
        consoleSink(acc, `${path} @390`);
        const i = await info(acc);
        c.evidence.pages[name] = { status, overflowPx: i.overflow, jsErrors: errors, screenshot: await shot(acc, `14-phone-${name}`) };
        check(c, `${name}: 200, no overflow, no JS errors`, status === 200 && i.overflow <= 1 && errors.length === 0, c.evidence.pages[name]);
    }
    await acc.viewport(1366, 900);
});

// ---------------------------------------------------------------- summary
results.finishedAt = new Date().toISOString();
results.summary = Object.fromEntries(['PASS', 'FAIL', 'BLOCKED', 'NOT RUN'].map((s) => [s, results.cases.filter((x) => x.status === s).map((x) => x.id)]));
results.frcReconciliation = { afterAllPosted: ctx.reconciliationAfter, afterReversal: ctx.reconciliationFinal && { status: ctx.reconciliationFinal.status, kpis: ctx.reconciliationFinal.kpis, source: ctx.reconciliationFinal.source, ledger: ctx.reconciliationFinal.ledger } };
save();
console.log('\nSummary', JSON.stringify(results.summary));
console.log('Console errors:', results.consoleErrors.length, 'Defects:', results.defects.length);
await browser.close();
