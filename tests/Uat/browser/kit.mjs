// Shared helpers for the UAT browser scripts (same style as workflows.mjs):
// in-page DOM helpers, navigation with console-error capture, case bookkeeping
// and role sessions. Used by release.mjs; workflows.mjs keeps its own copy.

import { writeFileSync } from 'node:fs';
import { join } from 'node:path';

export const PASSWORD = 'Uat-Password-2026!';
export const PHP = 'E:\\wamp64\\bin\\php\\php8.3.14\\php.exe';
export const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
export const money = (s) => (s == null ? null : Number(String(s).replace(/[^0-9.-]/g, '')));
export const idFrom = (url, re) => Number((String(url ?? '').match(re) ?? [])[1]);

// ---------------------------------------------------------------- in-page helpers
export const HELPERS = `
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
    const button = buttonText ? [...$$('button, input[type=submit]', form), ...(form.id ? $$('[form="' + form.id + '"]') : [])].find((b) => txt(b).includes(buttonText) || b.value === buttonText) : null;
    if (buttonText && !button) throw new Error('Button "' + buttonText + '" not found in form');
    form.requestSubmit(button ?? undefined);
};
const formByAction = (re) => $$('form').find((f) => new RegExp(re).test(f.getAttribute('action') ?? ''));
const buttonForm = (text) => $$('form').find((f) => $$('button', f).some((b) => txt(b) === text || txt(b).startsWith(text)));
const rows = (root = document) => $$('table tr', root).map((tr) => [...tr.cells].map((c) => txt(c)));
const kpis = () => Object.fromEntries($$('.kpi-card').map((c) => [txt($('.kpi-label', c)), { value: txt($('.kpi-value', c)), status: txt($('.status-badge', c)) }]));
const exportLinks = () => [...new Set($$('a[href]').map((a) => a.href).filter((h) => /\\/export\\/(csv|xlsx|pdf)(\\?|$)/.test(h)))];
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

/**
 * @param {{ browser: any, base: string, out: string, results: any, file: string }} options
 */
export function createKit({ browser, base, out, results, file }) {
    const save = () => writeFileSync(join(out, file), JSON.stringify(results, null, 2));
    const ev = (page, fn, ...fnArgs) => page.evaluate(wrap(fn, fnArgs));

    const consoleSink = (page, label) => {
        if (page.consoleErrors.length) results.consoleErrors.push({ user: page.user, url: label, errors: [...page.consoleErrors] });
        page.consoleErrors = [];
    };

    async function go(page, path) {
        const status = await page.goto(path.startsWith('http') ? path : base + path);
        results.pagesVisited++;
        consoleSink(page, path);
        return status;
    }

    /** Run a DOM action that navigates (form submit, click) and wait for the next document. */
    async function act(page, fn, ...fnArgs) {
        page.documentStatus = null;
        const loaded = page.waitFor('Page.loadEventFired', 90000);
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
        description: txt($('.page-description')),
        alerts: $$('.alert').map(txt).filter(Boolean),
        errors: $$('.alert-danger').map(txt).filter(Boolean),
        invalid: $$('.app-content .invalid-feedback').map(txt).filter(Boolean),
        serverError: /Whoops|Internal Server Error|ErrorException|QueryException|Undefined (variable|array key|property)|Call to (undefined|a member)|TypeError:|Server Error/.test(document.body.innerText),
        overflow: document.documentElement.scrollWidth - window.innerWidth,
        bodyStart: txt(document.body).slice(0, 200),
    }));

    const shot = async (page, name) => { await page.screenshot(join(out, 'screens', `${name}.png`)); return `screens/${name}.png`; };

    function newCase(id, title, expected) {
        const c = { id, title, expected, steps: [], checks: [], actual: '', status: 'NOT RUN', evidence: {} };
        results.cases.push(c);
        return c;
    }
    const step = (c, text) => { c.steps.push(text); console.log(`  [${c.id}] ${text}`); };
    const check = (c, name, ok, detail) => { c.checks.push({ name, ok: !!ok, detail }); console.log(`    ${ok ? 'ok  ' : 'FAIL'} ${name}${detail !== undefined ? ' — ' + JSON.stringify(detail).slice(0, 300) : ''}`); return !!ok; };
    const defect = (c, d) => { results.defects.push({ case: c.id, ...d }); };
    const note = (c, text) => { results.automationNotes.push({ case: c.id, note: text }); };
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

    const sessions = {};
    async function as(role) {
        if (sessions[role]) return sessions[role];
        const page = await browser.newPage();
        page.user = role;
        await page.viewport(1366, 900);
        await page.login(base, `uat.${role}@uat.test`, PASSWORD);
        const url = await page.evaluate('location.pathname');
        if (url.includes('/login')) throw new Error(`Login failed for ${role}`);
        consoleSink(page, '/login');
        sessions[role] = page;
        return page;
    }

    /** Fetch a URL with the page's cookies; return status, type, signature and (for text) the body. */
    const download = (page, href) => ev(page, async (url) => {
        const r = await fetch(url, { credentials: 'same-origin', redirect: 'manual' });
        const buf = await r.arrayBuffer();
        const head = new TextDecoder('latin1').decode(buf.slice(0, 8));
        const text = /csv|text/.test(r.headers.get('content-type') ?? '') ? new TextDecoder().decode(buf) : null;
        return { status: r.status, type: r.headers.get('content-type'), disposition: r.headers.get('content-disposition'), size: buf.byteLength, head, text: text?.slice(0, 20000) };
    }, href);

    return { save, ev, go, act, info, shot, newCase, step, check, defect, note, finish, runCase, as, download, consoleSink };
}
