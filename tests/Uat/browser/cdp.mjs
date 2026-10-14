// Minimal dependency-free Chrome DevTools Protocol driver for UAT browser tests.
// Launches the locally installed Chrome (or Edge) headless with a throw-away
// profile; nothing is installed and the user's own browser profile is untouched.

import { spawn } from 'node:child_process';
import { existsSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const CANDIDATES = [
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
];

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export async function launchBrowser({ port = 9333 } = {}) {
    const executable = CANDIDATES.find((path) => existsSync(path));
    if (!executable) throw new Error('No Chrome or Edge executable found.');
    const profile = mkdtempSync(join(tmpdir(), 'frc-uat-browser-'));
    const child = spawn(executable, [
        '--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`,
        '--no-first-run', '--no-default-browser-check', '--disable-gpu', '--disable-extensions', '--window-size=1366,900',
    ], { stdio: 'ignore' });

    let version;
    for (let attempt = 0; attempt < 60 && !version; attempt++) {
        try { version = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); } catch { await sleep(250); }
    }
    if (!version) throw new Error('Browser did not start.');

    const browser = new Browser(new WebSocket(version.webSocketDebuggerUrl), executable);
    await browser.ready;
    browser.close = async () => {
        try { await browser.send('Browser.close'); } catch { /* already closed */ }
        child.kill();
        await sleep(500);
        try { rmSync(profile, { recursive: true, force: true }); } catch { /* profile still locked */ }
    };

    return browser;
}

class Browser {
    constructor(socket, executable) {
        this.socket = socket;
        this.executable = executable;
        this.nextId = 1;
        this.pending = new Map();
        this.listeners = new Map();
        this.ready = new Promise((resolve, reject) => {
            socket.addEventListener('open', resolve);
            socket.addEventListener('error', reject);
        });
        socket.addEventListener('message', (event) => {
            const message = JSON.parse(event.data);
            if (message.id && this.pending.has(message.id)) {
                const { resolve, reject } = this.pending.get(message.id);
                this.pending.delete(message.id);
                message.error ? reject(new Error(message.error.message)) : resolve(message.result);
                return;
            }
            const key = `${message.sessionId ?? ''}:${message.method}`;
            for (const handler of this.listeners.get(key) ?? []) handler(message.params);
        });
    }

    send(method, params = {}, sessionId) {
        const id = this.nextId++;
        this.socket.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));

        return new Promise((resolve, reject) => {
            this.pending.set(id, { resolve, reject });
            setTimeout(() => {
                if (this.pending.has(id)) { this.pending.delete(id); reject(new Error(`Timeout: ${method}`)); }
            }, 60000);
        });
    }

    on(sessionId, method, handler) {
        const key = `${sessionId}:${method}`;
        this.listeners.set(key, [...(this.listeners.get(key) ?? []), handler]);
    }

    /** A new isolated context (own cookies) with one page. */
    async newPage() {
        const { browserContextId } = await this.send('Target.createBrowserContext', { disposeOnDetach: true });
        const { targetId } = await this.send('Target.createTarget', { url: 'about:blank', browserContextId });
        const { sessionId } = await this.send('Target.attachToTarget', { targetId, flatten: true });
        const page = new Page(this, sessionId, targetId, browserContextId);
        await page.init();

        return page;
    }
}

export class Page {
    constructor(browser, sessionId, targetId, contextId) {
        Object.assign(this, { browser, sessionId, targetId, contextId });
        this.consoleErrors = [];
        this.documentStatus = null;
        this.downloads = [];
    }

    send(method, params) {
        return this.browser.send(method, params, this.sessionId);
    }

    async init() {
        await Promise.all(['Page.enable', 'Runtime.enable', 'Log.enable', 'Network.enable'].map((method) => this.send(method)));
        await this.send('Page.setLifecycleEventsEnabled', { enabled: true });
        const on = (method, handler) => this.browser.on(this.sessionId, method, handler);
        on('Runtime.exceptionThrown', (params) => this.consoleErrors.push(params.exceptionDetails?.exception?.description ?? params.exceptionDetails?.text));
        on('Runtime.consoleAPICalled', (params) => {
            if (params.type === 'error') this.consoleErrors.push(params.args.map((arg) => arg.value ?? arg.description).join(' '));
        });
        on('Log.entryAdded', (params) => {
            // A 403/404 the test expects is reported separately; ignore resource-status noise here.
            if (params.entry.level === 'error' && !/status of (403|404|409|422)/.test(params.entry.text)) this.consoleErrors.push(params.entry.text);
        });
        on('Network.responseReceived', (params) => {
            if (params.type === 'Document') this.documentStatus = params.response.status;
            const disposition = params.response.headers['content-disposition'] ?? params.response.headers['Content-Disposition'];
            if (disposition) this.downloads.push({ url: params.response.url, status: params.response.status, type: params.response.mimeType, disposition });
        });
        on('Page.javascriptDialogOpening', (params) => {
            this.lastDialog = params.message;
            this.send('Page.handleJavaScriptDialog', { accept: true }).catch(() => {});
        });
    }

    async viewport(width, height, mobile = false) {
        await this.send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile });
    }

    /** Navigate and wait until the document has loaded. */
    async goto(url) {
        this.documentStatus = null;
        const loaded = this.waitFor('Page.loadEventFired');
        await this.send('Page.navigate', { url });
        await loaded;
        await sleep(150);

        return this.documentStatus;
    }

    waitFor(method, timeout = 45000) {
        return new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error(`Timeout waiting for ${method}`)), timeout);
            this.browser.on(this.sessionId, method, (params) => { clearTimeout(timer); resolve(params); });
        });
    }

    async evaluate(expression) {
        const result = await this.send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description ?? result.exceptionDetails.text);

        return result.result.value;
    }

    /** Run a script that submits a form or clicks a link, then wait for the next page. */
    async submit(expression) {
        this.documentStatus = null;
        const loaded = this.waitFor('Page.loadEventFired');
        await this.evaluate(expression);
        await loaded;
        await sleep(150);

        return this.documentStatus;
    }

    async login(baseUrl, email, password) {
        await this.goto(`${baseUrl}/login`);

        return this.submit(`(() => {
            const form = document.querySelector('form[action$="/login"]');
            form.querySelector('[name=email]').value = ${JSON.stringify(email)};
            form.querySelector('[name=password]').value = ${JSON.stringify(password)};
            form.requestSubmit();
        })()`);
    }

    async screenshot(path) {
        const { data } = await this.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        writeFileSync(path, Buffer.from(data, 'base64'));
    }

    /** Fetch a URL with this page's cookies and return status, type and size (for exports and links). */
    async fetchInfo(url) {
        return this.evaluate(`fetch(${JSON.stringify(url)}, { credentials: 'same-origin', redirect: 'manual' }).then(async (r) => {
            const body = await r.arrayBuffer();
            const head = new TextDecoder('latin1').decode(body.slice(0, 8));
            return { status: r.status, type: r.headers.get('content-type'), disposition: r.headers.get('content-disposition'), size: body.byteLength, head };
        })`);
    }
}
