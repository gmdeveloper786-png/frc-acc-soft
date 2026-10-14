// Browser smoke test of every sidebar page for each UAT role, at desktop,
// tablet and phone widths. Checks HTTP status, server error pages, JavaScript
// errors, horizontal overflow and every same-origin link found on each page.
//
//   node tests/Uat/browser/smoke.mjs [baseUrl] [outputDir]

import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { launchBrowser } from './cdp.mjs';

const BASE = process.argv[2] ?? 'http://127.0.0.1:8010';
const OUT = process.argv[3] ?? join(process.cwd(), 'storage', 'uat');
const PASSWORD = 'Uat-Password-2026!';
mkdirSync(join(OUT, 'screens'), { recursive: true });

const ROLES = ['admin', 'accountant', 'cashier', 'approver', 'director', 'board', 'auditor', 'branchb'];
const VIEWPORTS = { desktop: [1366, 900, false], tablet: [768, 1024, true], phone: [390, 844, true] };
const results = { base: BASE, startedAt: new Date().toISOString(), roles: {}, links: [], forbidden: [], summary: {} };

const pageState = (page) => page.evaluate(`(() => ({
    title: document.title,
    serverError: /Whoops|Internal Server Error|ErrorException|QueryException|Undefined (variable|array key|property)|Call to (undefined|a member)|TypeError:|Server Error/.test(document.body.innerText),
    overflow: document.documentElement.scrollWidth - window.innerWidth,
    heading: document.querySelector('.page-header h1')?.innerText ?? '',
    sidebar: [...document.querySelectorAll('#appSidebar a.sidebar-link')].map((a) => ({ text: a.innerText.trim(), href: a.href })),
    links: [...new Set([...document.querySelectorAll('.app-content a[href]')].map((a) => a.href).filter((href) => href.startsWith(location.origin) && !href.includes('#') && !/logout|\\/export\\//.test(href)))].slice(0, 40),
    buttons: document.querySelectorAll('button:not([disabled]), input[type=submit]').length,
    forms: document.querySelectorAll('form').length,
}))()`);

const browser = await launchBrowser();
console.log(`Browser: ${browser.executable}`);
const checkedLinks = new Map();

for (const role of ROLES) {
    const page = await browser.newPage();
    await page.viewport(...VIEWPORTS.desktop);
    const loginStatus = await page.login(BASE, `uat.${role}@uat.test`, PASSWORD);
    const landing = await pageState(page);
    const roleResult = { loginStatus, landing: landing.heading, pages: [] };
    results.roles[role] = roleResult;
    if (!landing.sidebar.length) {
        roleResult.error = 'Login failed or no sidebar rendered.';
        console.log(`  ${role}: LOGIN FAILED`);
        continue;
    }

    for (const link of landing.sidebar) {
        const entry = { text: link.text, url: link.href.replace(BASE, ''), viewports: {} };
        for (const [name, size] of Object.entries(VIEWPORTS)) {
            if (name !== 'desktop' && !['admin', 'cashier'].includes(role)) continue;
            await page.viewport(...size);
            page.consoleErrors = [];
            const status = await page.goto(link.href);
            const state = await pageState(page);
            entry.viewports[name] = { status, serverError: state.serverError, overflowPx: state.overflow, jsErrors: [...page.consoleErrors], heading: state.heading };
            if (name === 'desktop') {
                entry.forms = state.forms;
                entry.buttons = state.buttons;
                for (const href of state.links) {
                    if (checkedLinks.has(`${role}|${href}`)) continue;
                    const info = await page.fetchInfo(href);
                    checkedLinks.set(`${role}|${href}`, info.status);
                    if (info.status >= 400 && info.status !== 403) results.links.push({ role, from: entry.url, href: href.replace(BASE, ''), status: info.status });
                }
            }
            if (role === 'admin') await page.screenshot(join(OUT, 'screens', `${name}-${entry.url.replace(/[^a-z0-9]+/gi, '_').slice(0, 60) || 'root'}.png`));
        }
        roleResult.pages.push(entry);
        const desk = entry.viewports.desktop;
        const flag = desk.status >= 400 || desk.serverError || desk.jsErrors.length ? 'FAIL' : 'ok';
        console.log(`  ${role.padEnd(10)} ${String(desk.status).padEnd(4)} ${flag.padEnd(5)} ${entry.url}`);
    }
    await page.send('Target.closeTarget', { targetId: page.targetId }).catch(() => {});
}

// A cashier must be refused administration and other modules even by direct URL.
const cashier = await browser.newPage();
await cashier.login(BASE, 'uat.cashier@uat.test', PASSWORD);
for (const path of ['/users', '/roles', '/settings', '/settings/approval-controls', '/account-mappings', '/accounting-setup/readiness', '/audit-logs', '/payroll/employees', '/payroll-reports', '/journals', '/statements/balance-sheet', '/purchase-orders']) {
    const status = await cashier.goto(`${BASE}${path}`);
    results.forbidden.push({ role: 'cashier', path, status, ok: status === 403 });
}
const board = await browser.newPage();
await board.login(BASE, 'uat.board@uat.test', PASSWORD);
for (const path of ['/users/create', '/journals/create', '/purchase-orders/create', '/settings/approval-controls', '/payroll-reports/register/export/csv']) {
    const status = await board.goto(`${BASE}${path}`);
    results.forbidden.push({ role: 'board', path, status, ok: status === 403 });
}

const pages = Object.values(results.roles).flatMap((role) => role.pages ?? []);
const views = pages.flatMap((page) => Object.entries(page.viewports).map(([name, view]) => ({ page: page.url, name, ...view })));
results.summary = {
    rolesLoggedIn: Object.values(results.roles).filter((role) => !role.error).length,
    pageVisits: views.length,
    httpErrors: views.filter((view) => view.status >= 400).map((view) => `${view.name} ${view.status} ${view.page}`),
    serverErrorPages: views.filter((view) => view.serverError).map((view) => `${view.name} ${view.page}`),
    jsErrorPages: views.filter((view) => view.jsErrors.length).map((view) => `${view.name} ${view.page}: ${view.jsErrors[0]}`),
    horizontalOverflow: views.filter((view) => view.overflowPx > 1).map((view) => `${view.name} ${view.page} (+${view.overflowPx}px)`),
    brokenLinks: results.links.length,
    linksChecked: checkedLinks.size,
    forbiddenChecks: results.forbidden.length,
    forbiddenFailures: results.forbidden.filter((check) => !check.ok),
};
results.finishedAt = new Date().toISOString();
writeFileSync(join(OUT, 'smoke-results.json'), JSON.stringify(results, null, 2));
console.log(JSON.stringify(results.summary, null, 2));
await browser.close();
