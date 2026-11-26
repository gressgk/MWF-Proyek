import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import fs from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const REPO_ROOT = path.resolve(__dirname, '../..');
const ARTIFACT_SCREENSHOT_DIR = process.env.E53_SCREENSHOT_DIR
    || path.join(REPO_ROOT, 'artifacts', 'E5.3', 'screenshots');

const BROWSER_CANDIDATES = [
    process.env.E53_BROWSER_PATH,
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    process.env.LOCALAPPDATA ? path.join(process.env.LOCALAPPDATA, 'Vivaldi', 'Application', 'vivaldi.exe') : null,
    'C:\\Program Files\\Vivaldi\\Application\\vivaldi.exe',
].filter(Boolean);

const BROWSER_PATH = BROWSER_CANDIDATES.find((p) => fs.existsSync(p));
if (!BROWSER_PATH) {
    throw new Error('No Chromium/Edge executable found for E5.3 browser E2E verification.');
}

fs.mkdirSync(ARTIFACT_SCREENSHOT_DIR, { recursive: true });

class CdpClient {
    constructor(wsUrl) {
        this.wsUrl = wsUrl;
        this.ws = null;
        this.nextId = 1;
        this.pending = new Map();
        this.eventHandlers = new Map();
    }

    async connect() {
        await new Promise((resolve, reject) => {
            const ws = new WebSocket(this.wsUrl);
            this.ws = ws;
            ws.addEventListener('open', () => resolve());
            ws.addEventListener('error', (err) => reject(err));
            ws.addEventListener('message', (evt) => {
                const msg = JSON.parse(evt.data);
                if (msg.id) {
                    const p = this.pending.get(msg.id);
                    if (p) {
                        this.pending.delete(msg.id);
                        if (msg.error) {
                            p.reject(new Error(`${msg.error.code}: ${msg.error.message}`));
                        } else {
                            p.resolve(msg.result);
                        }
                    }
                } else if (msg.method) {
                    const key = `${msg.sessionId || ''}:${msg.method}`;
                    const handlers = this.eventHandlers.get(key) || [];
                    handlers.forEach((h) => h(msg.params));
                }
            });
        });
    }

    on(method, handler, sessionId = '') {
        const key = `${sessionId}:${method}`;
        const list = this.eventHandlers.get(key) || [];
        list.push(handler);
        this.eventHandlers.set(key, list);
    }

    once(method, handler, sessionId = '') {
        const key = `${sessionId}:${method}`;
        const wrapper = (params) => {
            const list = this.eventHandlers.get(key) || [];
            this.eventHandlers.set(key, list.filter((h) => h !== wrapper));
            handler(params);
        };
        this.on(method, wrapper, sessionId);
    }

    send(method, params = {}, sessionId = undefined) {
        const id = this.nextId++;
        const payload = { id, method, params };
        if (sessionId) {
            payload.sessionId = sessionId;
        }
        return new Promise((resolve, reject) => {
            this.pending.set(id, { resolve, reject });
            this.ws.send(JSON.stringify(payload));
        });
    }

    close() {
        if (this.ws) {
            this.ws.close();
        }
    }
}

function sleep(ms) {
    return new Promise((r) => setTimeout(r, ms));
}

function renderBlade(scenario, baseUrl) {
    return execFileSync('php', [path.join(__dirname, 'render-fixtures.php'), scenario, baseUrl], {
        cwd: REPO_ROOT,
        encoding: 'utf8',
    });
}

function serveStatic(reqPath, res) {
    const cleanRel = reqPath.replace(/^\/+/, '').replace(/\.\./g, '');
    const fullPath = path.join(REPO_ROOT, 'public', cleanRel);
    if (!fullPath.startsWith(path.join(REPO_ROOT, 'public')) || !fs.existsSync(fullPath)) {
        res.writeHead(404);
        res.end('Not found');
        return;
    }
    const ext = path.extname(fullPath).toLowerCase();
    const mime = {
        '.css': 'text/css; charset=utf-8',
        '.js': 'application/javascript; charset=utf-8',
        '.png': 'image/png',
        '.jpg': 'image/jpeg',
        '.svg': 'image/svg+xml',
    }[ext] || 'application/octet-stream';
    res.writeHead(200, { 'Content-Type': mime });
    fs.createReadStream(fullPath).pipe(res);
}

function cloneItems(items) {
    return JSON.parse(JSON.stringify(items));
}

async function runSuite() {
    let browserProc = null;
    let browserExitPromise = null;
    let browserExited = false;
    let tempProfileDir = null;
    let cdp = null;
    let server = null;

    let currentMockHandler = null;
    let requestsLog = [];
    let blockedExternalRequests = [];
    let consoleErrors = [];
    let pageErrors = [];
    let failNextPostViaCdp = false;
    let cdpAbortedPosts = 0;

    try {
        server = http.createServer(async (req, res) => {
            const urlObj = new URL(req.url, `http://${req.headers.host}`);
            const pathname = urlObj.pathname;

            if (pathname.startsWith('/css/') || pathname.startsWith('/js/') || pathname.startsWith('/img/')) {
                serveStatic(pathname, res);
                return;
            }

            if (pathname === '/mock-midtrans-3ds.js') {
                res.writeHead(200, { 'Content-Type': 'application/javascript; charset=utf-8' });
                res.end(`
                    window.MidtransNew3ds = {
                        clientKey: 'SB-Mid-client-SYNTHETIC-TEST',
                        getCardToken: function(cardData, opts) {
                            opts.onSuccess({ token_id: 'synth-card-tok-e2e-001' });
                        },
                        authenticate: function(redirectUrl, opts) {
                            window.__last3dsRedirectUrl = redirectUrl;
                            opts.onPending();
                        }
                    };
                `);
                return;
            }

            const chunks = [];
            for await (const chunk of req) {
                chunks.push(chunk);
            }
            const rawBody = Buffer.concat(chunks).toString('utf8');

            const isApi = pathname.startsWith('/donatur/subscriptions')
                || (pathname === '/donatur/donasi/campaign-checkout-sintetis/uang' && req.method === 'POST')
                || pathname === '/auth/login'
                || pathname === '/donatur/riwayat';

            if (isApi) {
                requestsLog.push({
                    method: req.method,
                    path: pathname,
                    headers: req.headers,
                    body: rawBody,
                    ts: Date.now(),
                });
            }

            if (pathname === '/auth/login') {
                res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
                res.end('<html><body><h1>Halaman Login</h1></body></html>');
                return;
            }

            if (pathname === '/donatur/riwayat') {
                res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
                res.end(`<html><body><h1 id="riwayatPage">Riwayat Donasi</h1><div id="query">${urlObj.search}</div></body></html>`);
                return;
            }

            if (currentMockHandler && isApi) {
                const handled = await currentMockHandler({ req, res, pathname, urlObj, rawBody });
                if (handled) {
                    return;
                }
            }

            if (pathname === '/donatur/settings' && req.method === 'GET') {
                const html = renderBlade(urlObj.searchParams.get('scenario') || 'settings-full', `http://${req.headers.host}`);
                res.writeHead(200, {
                    'Content-Type': 'text/html; charset=utf-8',
                    'Set-Cookie': 'wecare_session=synthetic-cookie-value; Path=/; SameSite=Lax',
                });
                res.end(html);
                return;
            }

            if (pathname === '/donatur/donasi/campaign-checkout-sintetis/uang' && req.method === 'GET') {
                let html = renderBlade('checkout-recurring', `http://${req.headers.host}`);
                html = html.replace(
                    'https://api.sandbox.midtrans.com/v2/assets/js/midtrans-new-3ds.min.js',
                    `http://${req.headers.host}/mock-midtrans-3ds.js`
                );
                res.writeHead(200, {
                    'Content-Type': 'text/html; charset=utf-8',
                    'Set-Cookie': 'wecare_session=synthetic-cookie-value; Path=/; SameSite=Lax',
                });
                res.end(html);
                return;
            }

            res.writeHead(404, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ message: 'Not mocked' }));
        });

        await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
        const { port } = server.address();
        const baseUrl = `http://127.0.0.1:${port}`;

        tempProfileDir = fs.mkdtempSync(path.join(os.tmpdir(), 'wecare-e53-browser-profile-'));

        browserProc = spawn(
            BROWSER_PATH,
            [
                `--user-data-dir=${tempProfileDir}`,
                '--headless=new',
                '--disable-gpu',
                '--no-first-run',
                '--no-default-browser-check',
                '--disable-extensions',
                '--disable-sync',
                '--remote-debugging-port=0',
                'about:blank',
            ],
            { stdio: ['ignore', 'ignore', 'pipe'] }
        );

        browserExitPromise = new Promise((resolve) => {
            browserProc.once('exit', (code, signal) => {
                browserExited = true;
                resolve({ code, signal });
            });
            browserProc.once('error', () => {
                browserExited = true;
                resolve({ code: null, signal: null });
            });
        });

        const wsEndpoint = await new Promise((resolve, reject) => {
            const timeout = setTimeout(() => reject(new Error('Timed out waiting for DevTools WebSocket URL')), 15000);
            let stderrBuf = '';
            browserProc.stderr.on('data', (d) => {
                stderrBuf += d.toString();
                const m = stderrBuf.match(/DevTools listening on (ws:\/\/[^\s]+)/);
                if (m) {
                    clearTimeout(timeout);
                    resolve(m[1]);
                }
            });
            browserProc.on('exit', (code) => {
                clearTimeout(timeout);
                reject(new Error(`Browser exited prematurely with code ${code}: ${stderrBuf}`));
            });
        });

        cdp = new CdpClient(wsEndpoint);
        await cdp.connect();

        const { browserContextId } = await cdp.send('Target.createBrowserContext');
        const { targetId } = await cdp.send('Target.createTarget', { url: 'about:blank', browserContextId });
        const { sessionId } = await cdp.send('Target.attachToTarget', { targetId, flatten: true });

        await cdp.send('Page.enable', {}, sessionId);
        await cdp.send('Page.bringToFront', {}, sessionId);
        await cdp.send('Runtime.enable', {}, sessionId);
        await cdp.send('Network.enable', {}, sessionId);
        await cdp.send('Fetch.enable', {
            patterns: [{ urlPattern: '*', requestStage: 'Request' }],
        }, sessionId);

        cdp.on('Fetch.requestPaused', async (params) => {
            const url = params.request.url;
            const allowed = url.startsWith(baseUrl)
                || url.startsWith('https://cdn.jsdelivr.net/')
                || url.startsWith('data:')
                || url.startsWith('about:');
            if (!allowed) {
                blockedExternalRequests.push(url);
                await cdp.send('Fetch.failRequest', { requestId: params.requestId, errorReason: 'Aborted' }, sessionId).catch(() => {});
                return;
            }
            if (failNextPostViaCdp && params.request.method === 'POST' && url.includes('/donatur/subscriptions/')) {
                failNextPostViaCdp = false;
                cdpAbortedPosts += 1;
                await cdp.send('Fetch.failRequest', { requestId: params.requestId, errorReason: 'Failed' }, sessionId).catch(() => {});
                return;
            }
            await cdp.send('Fetch.continueRequest', { requestId: params.requestId }, sessionId).catch(() => {});
        }, sessionId);

        cdp.on('Runtime.consoleAPICalled', (params) => {
            if (params.type === 'error') {
                consoleErrors.push(params.args.map((a) => a.value ?? a.description).join(' '));
            }
        }, sessionId);

        cdp.on('Runtime.exceptionThrown', (params) => {
            pageErrors.push(params.exceptionDetails?.exception?.description || params.exceptionDetails?.text || 'Unknown exception');
        }, sessionId);

        async function setViewport(width, height) {
            await cdp.send('Emulation.setDeviceMetricsOverride', {
                width,
                height,
                deviceScaleFactor: 1,
                mobile: width < 768,
            }, sessionId);
        }

        async function navigate(url, clearStorage = true) {
            if (clearStorage) {
                await evalJs(`try { window.sessionStorage.clear(); } catch {}`).catch(() => {});
            }
            const loaded = new Promise((resolve) => {
                cdp.once('Page.loadEventFired', () => resolve(), sessionId);
            });
            await cdp.send('Page.navigate', { url }, sessionId);
            await loaded;
            await sleep(100);
        }

        async function evalJs(expression) {
            const res = await cdp.send('Runtime.evaluate', {
                expression,
                awaitPromise: true,
                returnByValue: true,
            }, sessionId);
            if (res.exceptionDetails) {
                throw new Error(`Page eval error: ${res.exceptionDetails.exception?.description || res.exceptionDetails.text}`);
            }
            return res.result?.value;
        }

        async function waitUntil(fnExpr, timeoutMs = 4000) {
            const start = Date.now();
            while (Date.now() - start < timeoutMs) {
                const ok = await evalJs(fnExpr);
                if (ok) {
                    return true;
                }
                await sleep(30);
            }
            throw new Error(`waitUntil timeout after ${timeoutMs}ms: ${fnExpr}`);
        }

        async function waitModalOpen() {
            await waitUntil(`(() => {
                const m = document.getElementById('subscriptionConfirmModal');
                const inst = m && window.bootstrap?.Modal?.getInstance(m);
                return Boolean(inst && inst._isShown && !inst._isTransitioning && m.classList.contains('show'));
            })()`);
            await sleep(50);
        }

        async function waitModalClosed() {
            await waitUntil(`(() => {
                const m = document.getElementById('subscriptionConfirmModal');
                const inst = m && window.bootstrap?.Modal?.getInstance(m);
                const backdrop = document.querySelector('.modal-backdrop');
                return Boolean(!backdrop && m && !m.classList.contains('show') && window.getComputedStyle(m).display === 'none' && (!inst || (!inst._isShown && !inst._isTransitioning)));
            })()`);
            await sleep(50);
        }

        async function saveScreenshot(fileName) {
            const { data } = await cdp.send('Page.captureScreenshot', { format: 'png' }, sessionId);
            const outPath = path.join(ARTIFACT_SCREENSHOT_DIR, fileName);
            fs.writeFileSync(outPath, Buffer.from(data, 'base64'));
            return outPath;
        }

        function resetLogs() {
            requestsLog = [];
        }

        function countRequests(method, pathPrefix = null) {
            return requestsLog.filter((r) => r.method === method && (!pathPrefix || r.path.startsWith(pathPrefix))).length;
        }

        const scenarioResults = [];
        function recordScenario(name, details = {}) {
            scenarioResults.push({
                name,
                postCount: details.postCount ?? countRequests('POST'),
                getCount: details.getCount ?? countRequests('GET', '/donatur/subscriptions'),
                passed: true,
            });
        }

        function jsonRes(res, status, obj) {
            res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' });
            res.end(JSON.stringify(obj));
        }

        const BASE_LIST_ITEMS = JSON.parse(
            renderBlade('settings-full', baseUrl).match(/<script type="application\/json" id="subscriptionInitialData">(.*?)<\/script>/s)[1]
        ).items;

        await setViewport(1440, 900);

        // ============================================================
        // 1. REAL BOOTSTRAP, BASIC MATRIX, XSS SAFETY & NO-ONLOAD REQUESTS
        // ============================================================
        resetLogs();
        await navigate(`${baseUrl}/donatur/settings`);

        const bootstrapLoaded = await evalJs(`typeof window.bootstrap !== 'undefined' && typeof window.bootstrap.Modal === 'function'`);
        assert.equal(bootstrapLoaded, true, 'Real Bootstrap 5 Modal must be loaded');
        assert.equal(countRequests('POST'), 0, 'Page load must send 0 POST requests');
        assert.equal(countRequests('GET', '/donatur/subscriptions'), 0, 'Page load must send 0 GET refresh requests');
        assert.equal(await evalJs(`Boolean(window.__xssTriggered)`), false, 'XSS payload must not execute');

        const cardChecks = await evalJs(`(() => {
            const getCard = (id) => {
                const el = document.querySelector('.wc-sub-item[data-subscription-id="' + id + '"]');
                return {
                    text: el ? el.textContent : '',
                    badge: el?.querySelector('[data-role="status-badge"]')?.textContent?.trim(),
                    sched: el?.querySelector('[data-role="schedule-text"]')?.textContent?.trim(),
                    actions: Array.from(el?.querySelectorAll('button[data-sub-action]') || []).map(b => ({
                        action: b.dataset.subAction,
                        text: b.textContent.trim(),
                        disabled: b.disabled
                    }))
                };
            };
            return {
                c901: getCard(901), c902: getCard(902), c903: getCard(903),
                c904: getCard(904), c905: getCard(905), c906: getCard(906),
                c907: getCard(907), c908: getCard(908), c909: getCard(909),
                c910: getCard(910), c911: getCard(911), c912: getCard(912),
                htmlText: document.body.innerHTML
            };
        })()`);

        assert.equal(cardChecks.c901.badge, 'Aktif');
        assert.equal(cardChecks.c901.sched, 'Jadwal terakhir tercatat: 24 Desember 2026, 13:23:36 WIB');
        assert.deepEqual(cardChecks.c901.actions.map((a) => a.action), ['pause', 'cancel']);
        assert.equal((cardChecks.c901.sched.match(/WIB/g) || []).length, 1, 'No duplicate WIB label');

        assert.equal(cardChecks.c902.badge, 'Dijeda');
        assert.equal(cardChecks.c902.sched, 'Donasi rutin sedang dijeda.');
        assert.deepEqual(cardChecks.c902.actions.map((a) => a.action), ['resume', 'cancel']);

        assert.equal(cardChecks.c903.badge, 'Dibatalkan');
        assert.equal(cardChecks.c903.sched, 'Tidak ada penagihan otomatis berikutnya.');
        assert.equal(cardChecks.c903.actions.length, 0);

        assert.equal(cardChecks.c904.badge, 'Menunggu Verifikasi');
        assert.deepEqual(cardChecks.c904.actions.map((a) => a.action), ['reconcile']);

        assert.equal(cardChecks.c905.badge, 'Aktif');
        assert.equal(cardChecks.c905.sched, 'Jadwal berikutnya belum terverifikasi.');
        assert.equal(cardChecks.c906.sched, 'Jadwal menunggu verifikasi.');
        assert.equal(cardChecks.c907.sched, 'Jadwal menunggu verifikasi.');
        assert.equal(cardChecks.c908.sched, 'Jadwal belum dapat dipastikan.');
        assert.equal(cardChecks.c908.text.includes('Campaign tidak tersedia'), true);
        assert.equal(cardChecks.c909.badge, 'Status Belum Dipastikan');
        assert.deepEqual(cardChecks.c909.actions.map((a) => a.action), ['reconcile']);
        assert.equal(cardChecks.c910.badge, 'Status Belum Dipastikan');
        assert.equal(cardChecks.c910.actions.length, 0);
        assert.equal(cardChecks.c911.actions.every((a) => a.disabled === true), true, 'Occupied guard disables actions on load');
        assert.equal(cardChecks.htmlText.includes('donasi-rutin'), false, 'No legacy endpoints');
        assert.equal(cardChecks.c912.text.includes('<img'), true, 'XSS title rendered safely as plain text');
        recordScenario('Basic states, schedules, XSS safety & zero onload calls');

        // ============================================================
        // 2. CONFIRMATION MODALS (PAUSE, RESUME, CANCEL, ESCAPE, RAPID CLICKS)
        // ============================================================
        resetLogs();
        // Open Pause modal & dismiss via Escape -> 0 POST + focus restored
        await evalJs(`(() => {
            const btn = document.querySelector('.wc-sub-item[data-subscription-id="901"] button[data-sub-action="pause"]');
            btn.focus();
            btn.click();
        })()`);
        await waitModalOpen();
        assert.equal(await evalJs(`document.getElementById('subscriptionConfirmTitle').textContent.trim()`), 'Jeda Donasi Rutin?');
        await evalJs(`document.getElementById('subscriptionConfirmCancelBtn').click()`);
        await waitModalClosed();
        assert.equal(countRequests('POST'), 0);

        // Resume modal copy check & Escape key dismiss + focus restoration
        await evalJs(`(() => {
            const btn = document.querySelector('.wc-sub-item[data-subscription-id="902"] button[data-sub-action="resume"]');
            btn.focus();
            btn.click();
        })()`);
        await waitModalOpen();
        const resumeBody = await evalJs(`document.getElementById('subscriptionConfirmBody').textContent`);
        assert.equal(resumeBody.includes('tidak otomatis membuat tagihan baru seketika'), true);
        await evalJs(`document.getElementById('subscriptionConfirmModal').focus()`);
        await cdp.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27, nativeVirtualKeyCode: 27 }, sessionId);
        await cdp.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27, nativeVirtualKeyCode: 27 }, sessionId);
        await waitModalClosed();
        await sleep(60);
        const focusedAttr = await evalJs(`document.activeElement?.dataset?.subAction + ':' + document.activeElement?.dataset?.subscriptionId`);
        assert.equal(focusedAttr, 'resume:902', 'Focus must restore to Resume button after Escape dismiss');
        assert.equal(countRequests('POST'), 0);

        // Cancel modal copy & confirmation + rapid double click = 1 POST, transport check
        let stateItems = cloneItems(BASE_LIST_ITEMS);
        currentMockHandler = async ({ req, res, pathname }) => {
            if (req.method === 'POST' && pathname === '/donatur/subscriptions/901/pause') {
                await sleep(120);
                stateItems[0].status = 'inactive';
                stateItems[0].management_state = 'paused';
                stateItems[0].schedule.status = 'paused_not_scheduled';
                stateItems[0].schedule.is_displayable_upcoming = false;
                stateItems[0].schedule.display_wib = null;
                jsonRes(res, 200, { success: true, status: 'inactive', management_state: 'paused', operation_in_progress: false, message: 'Donasi rutin berhasil dijeda.' });
                return true;
            }
            if (req.method === 'GET' && pathname === '/donatur/subscriptions') {
                jsonRes(res, 200, { success: true, subscriptions: stateItems });
                return true;
            }
            return false;
        };

        // Open Cancel modal first for copy check & screenshot
        await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="901"] button[data-sub-action="cancel"]').click()`);
        await waitModalOpen();
        const cancelBody = await evalJs(`document.getElementById('subscriptionConfirmBody').textContent`);
        assert.equal(cancelBody.includes('bersifat final'), true);
        assert.equal(cancelBody.includes('tidak otomatis dikembalikan'), true);
        const ssCancelModal = await saveScreenshot('03-cancel-confirmation-modal.png');
        await evalJs(`document.getElementById('subscriptionConfirmCancelBtn').click()`);
        await waitModalClosed();

        // Confirm Pause with rapid repeated confirm clicks -> exactly 1 POST + 1 GET, valid headers
        await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="901"] button[data-sub-action="pause"]').click()`);
        await waitModalOpen();
        await evalJs(`(() => {
            const confirm = document.getElementById('subscriptionConfirmProceedBtn');
            confirm.click();
            confirm.click();
            confirm.click();
        })()`);
        await waitUntil(`document.querySelector('.wc-sub-item[data-subscription-id="901"] [data-role="status-badge"]').textContent.trim() === 'Dijeda'`);
        assert.equal(countRequests('POST', '/donatur/subscriptions/901/pause'), 1, 'Rapid repeated clicks must send exactly 1 POST');
        assert.equal(countRequests('GET', '/donatur/subscriptions'), 1);
        const pauseReq = requestsLog.find((r) => r.path === '/donatur/subscriptions/901/pause');
        assert.equal(pauseReq.headers['x-csrf-token'], 'e2e-synthetic-csrf-token-001');
        assert.equal(pauseReq.headers['accept'], 'application/json');
        assert.equal(pauseReq.headers['x-requested-with'], 'XMLHttpRequest');
        assert.equal(String(pauseReq.headers['cookie'] || '').includes('wecare_session='), true);
        recordScenario('Modals, rapid clicks, focus restore & Active->Paused transition');

        // ============================================================
        // 3. F52.1: HTTP 200 SUCCESS WITH FAILED REFRESH VARIATIONS
        // ============================================================
        for (const failMode of ['503', 'network', 'non_json', 'invalid_schema']) {
            resetLogs();
            await navigate(`${baseUrl}/donatur/settings`);
            currentMockHandler = async ({ req, res, pathname }) => {
                if (req.method === 'POST' && pathname === '/donatur/subscriptions/901/pause') {
                    jsonRes(res, 200, { success: true, status: 'inactive', management_state: 'paused', message: 'Donasi rutin berhasil dijeda.' });
                    return true;
                }
                if (req.method === 'GET' && pathname === '/donatur/subscriptions') {
                    if (failMode === '503') {
                        jsonRes(res, 503, { message: 'Service unavailable' });
                    } else if (failMode === 'network') {
                        req.socket.destroy();
                    } else if (failMode === 'non_json') {
                        res.writeHead(200, { 'Content-Type': 'text/html' });
                        res.end('<html>Bad Gateway</html>');
                    } else {
                        jsonRes(res, 200, { success: true, subscriptions: [{ broken: true }] });
                    }
                    return true;
                }
                return false;
            };

            await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="901"] button[data-sub-action="pause"]').click()`);
            await waitModalOpen();
            await evalJs(`document.getElementById('subscriptionConfirmProceedBtn').click()`);
            await waitModalClosed();
            await waitUntil(`document.querySelector('.wc-sub-item[data-subscription-id="901"] [data-role="stale-lock-notice"]') !== null`);

            const lockedInfo = await evalJs(`(() => {
                const card = document.querySelector('.wc-sub-item[data-subscription-id="901"]');
                return {
                    feedback: document.getElementById('subscriptionFeedback').textContent,
                    notice: card.querySelector('[data-role="stale-lock-notice"]').textContent,
                    allDisabled: Array.from(card.querySelectorAll('button[data-sub-action]')).every(b => b.disabled)
                };
            })()`);
            assert.equal(lockedInfo.allDisabled, true, `F52.1 (${failMode}): actions must stay disabled`);
            assert.equal(lockedInfo.feedback.includes('daftar terbaru gagal dimuat'), true);
            assert.equal(countRequests('POST'), 1);

            if (failMode === '503') {
                await saveScreenshot('05-failed-refresh-disabled-actions.png');
            }

            // Recover with manual Muat Ulang
            const updatedItems = cloneItems(BASE_LIST_ITEMS);
            updatedItems[0].status = 'inactive';
            updatedItems[0].management_state = 'paused';
            currentMockHandler = async ({ req, res, pathname }) => {
                if (req.method === 'GET' && pathname === '/donatur/subscriptions') {
                    jsonRes(res, 200, { success: true, subscriptions: updatedItems });
                    return true;
                }
                return false;
            };
            await evalJs(`document.getElementById('refreshSubscriptionsBtn').click()`);
            await waitUntil(`document.querySelector('.wc-sub-item[data-subscription-id="901"] [data-role="status-badge"]').textContent.trim() === 'Dijeda'`);
            recordScenario(`F52.1 HTTP 200 + GET failure (${failMode}) & manual recovery`);
        }

        // ============================================================
        // 4. F52.2 UNIFIED RESPONSE MATRIX + PAUSED->ACTIVE, CANCEL, RECONCILE
        // ============================================================
        // 4a: Paused -> Active, Active -> Cancelled, Reconcile -> Verified, Reconcile -> Pending
        resetLogs();
        await navigate(`${baseUrl}/donatur/settings`);
        let matrixItems = cloneItems(BASE_LIST_ITEMS);
        currentMockHandler = async ({ req, res, pathname }) => {
            if (req.method === 'POST' && pathname === '/donatur/subscriptions/902/resume') {
                matrixItems[1].status = 'active';
                matrixItems[1].management_state = 'active';
                jsonRes(res, 200, { success: true, status: 'active', management_state: 'active', message: 'Donasi rutin berhasil dilanjutkan.' });
                return true;
            }
            if (req.method === 'POST' && pathname === '/donatur/subscriptions/902/cancel') {
                matrixItems[1].status = 'inactive';
                matrixItems[1].management_state = 'cancelled';
                jsonRes(res, 200, { success: true, status: 'inactive', management_state: 'cancelled', message: 'Donasi rutin berhasil dibatalkan.' });
                return true;
            }
            if (req.method === 'POST' && pathname === '/donatur/subscriptions/904/reconcile') {
                matrixItems[3].status = 'inactive';
                matrixItems[3].management_state = 'paused';
                jsonRes(res, 200, { success: true, status: 'inactive', management_state: 'paused', message: 'Reconciliation selesai.' });
                return true;
            }
            if (req.method === 'GET' && pathname === '/donatur/subscriptions') {
                jsonRes(res, 200, { success: true, subscriptions: matrixItems });
                return true;
            }
            return false;
        };
        await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="902"] button[data-sub-action="resume"]').click()`);
        await waitModalOpen();
        await evalJs(`document.getElementById('subscriptionConfirmProceedBtn').click()`);
        await waitModalClosed();
        await waitUntil(`document.querySelector('.wc-sub-item[data-subscription-id="902"] [data-role="status-badge"]').textContent.trim() === 'Aktif'`);

        await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="902"] button[data-sub-action="cancel"]').click()`);
        await waitModalOpen();
        await evalJs(`document.getElementById('subscriptionConfirmProceedBtn').click()`);
        await waitModalClosed();
        await waitUntil(`document.querySelector('.wc-sub-item[data-subscription-id="902"] [data-role="status-badge"]').textContent.trim() === 'Dibatalkan'`);
        assert.equal(await evalJs(`document.querySelectorAll('.wc-sub-item[data-subscription-id="902"] button[data-sub-action]').length`), 0);

        await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="904"] button[data-sub-action="reconcile"]').click()`);
        await waitUntil(`document.querySelector('.wc-sub-item[data-subscription-id="904"] [data-role="status-badge"]').textContent.trim() === 'Dijeda'`);
        recordScenario('Paused->Active, Active->Cancelled & Reconcile->Verified');

        // 4b: HTTP 202 + failed GET, HTTP 200 success=false, HTTP 200 malformed, HTTP 400, 403, 404, 422, 503, 500, network
        const errorProbes = [
            { name: 'HTTP 202 + failed GET', postStatus: 202, postBody: { success: false, management_state: 'reconciliation', message: 'Pending' }, getFail: true, expectLocked: true },
            { name: 'HTTP 200 success=false + unchanged GET', postStatus: 200, postBody: { success: false, message: 'Belum pasti' }, getFail: false, expectReconcileOnly: true },
            { name: 'HTTP 200 malformed JSON', postRaw: '{bad json', postStatus: 200, getFail: false, expectReconcileOnly: true },
            { name: 'HTTP 400 unexpected JSON', postStatus: 400, postBody: { message: 'Bad request' }, getFail: false, expectReconcileOnly: true },
            { name: 'HTTP 403 forbidden rejected', postStatus: 403, postBody: { success: false, message: 'Akses ditolak.' }, getFail: false, expectReconcileOnly: false },
            { name: 'HTTP 404 not found rejected', postStatus: 404, postBody: { success: false, message: 'Langganan tidak ditemukan.' }, getFail: false, expectReconcileOnly: false },
            { name: 'HTTP 422 validation rejected', postStatus: 422, postBody: { success: false, message: 'Tidak dapat dijeda' }, getFail: false, expectReconcileOnly: false },
            { name: 'HTTP 503 provider unavailable', postStatus: 503, postBody: { success: false, message: 'Provider down' }, getFail: false, expectReconcileOnly: true },
            { name: 'HTTP 500 server error', postStatus: 500, postBody: { message: 'Server error' }, getFail: false, expectReconcileOnly: true },
            { name: 'Network error on POST', postNetworkFail: true, getFail: false, expectReconcileOnly: true },
        ];

        for (const probe of errorProbes) {
            resetLogs();
            cdpAbortedPosts = 0;
            failNextPostViaCdp = Boolean(probe.postNetworkFail);
            await navigate(`${baseUrl}/donatur/settings`);
            currentMockHandler = async ({ req, res, pathname }) => {
                if (req.method === 'POST' && pathname === '/donatur/subscriptions/901/pause') {
                    if (probe.postRaw !== undefined) {
                        res.writeHead(probe.postStatus, { 'Content-Type': 'application/json' });
                        res.end(probe.postRaw);
                    } else {
                        jsonRes(res, probe.postStatus, probe.postBody);
                    }
                    return true;
                }
                if (req.method === 'GET' && pathname === '/donatur/subscriptions') {
                    if (probe.getFail) {
                        jsonRes(res, 503, { message: 'Refresh error' });
                    } else {
                        jsonRes(res, 200, { success: true, subscriptions: cloneItems(BASE_LIST_ITEMS) });
                    }
                    return true;
                }
                return false;
            };

            await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="901"] button[data-sub-action="pause"]').click()`);
            await waitModalOpen();
            await evalJs(`document.getElementById('subscriptionConfirmProceedBtn').click()`);
            await waitModalClosed();
            await waitUntil(`document.getElementById('subscriptionFeedback').textContent.trim().length > 0 && !document.querySelector('.wc-sub-item[data-subscription-id="901"] [data-role="loading-notice"]')`);

            const probeUi = await evalJs(`(() => {
                const c = document.querySelector('.wc-sub-item[data-subscription-id="901"]');
                return {
                    actions: Array.from(c.querySelectorAll('button[data-sub-action]')).map(b => ({ a: b.dataset.subAction, d: b.disabled })),
                    feedback: document.getElementById('subscriptionFeedback').textContent
                };
            })()`);

            const effectivePostCount = probe.postNetworkFail ? cdpAbortedPosts + countRequests('POST') : countRequests('POST');
            assert.equal(effectivePostCount, 1, `${probe.name}: never auto-retry POST`);
            if (probe.expectLocked) {
                assert.equal(probeUi.actions.every((x) => x.d === true), true, `${probe.name}: must stay locked`);
            } else if (probe.expectReconcileOnly) {
                assert.deepEqual(probeUi.actions.map((x) => x.a), ['reconcile'], `${probe.name}: unchanged active local state must only allow safe Periksa Status`);
            } else {
                assert.deepEqual(probeUi.actions.map((x) => x.a), ['pause', 'cancel'], `${probe.name}: definitive rejection restores actions after clean refresh`);
            }
            recordScenario(`F52.2 ${probe.name}`, { postCount: effectivePostCount });
        }

        // 4c: HTTP 401, Login Redirect, and HTTP 419 block further requests without retrying
        for (const authProbe of [
            { name: 'HTTP 401 unauthorized', status: 401 },
            { name: 'HTTP 419 CSRF expired', status: 419 },
            { name: 'Login redirect 302', redirectLogin: true },
        ]) {
            resetLogs();
            await navigate(`${baseUrl}/donatur/settings`);
            currentMockHandler = async ({ req, res, pathname }) => {
                if (req.method === 'POST' && pathname === '/donatur/subscriptions/901/pause') {
                    if (authProbe.redirectLogin) {
                        res.writeHead(302, { Location: `${baseUrl}/auth/login` });
                        res.end();
                    } else {
                        jsonRes(res, authProbe.status, { message: 'Auth/CSRF error' });
                    }
                    return true;
                }
                return false;
            };
            await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="901"] button[data-sub-action="pause"]').click()`);
            await waitModalOpen();
            await evalJs(`document.getElementById('subscriptionConfirmProceedBtn').click()`);
            await waitUntil(`document.querySelector('.wc-sub-item[data-subscription-id="901"] [data-role="session-notice"]') !== null`);

            assert.equal(await evalJs(`document.getElementById('refreshSubscriptionsBtn').disabled`), true);
            assert.equal(await evalJs(`Array.from(document.querySelectorAll('button[data-sub-action]')).every(b => b.disabled)`), true);
            await evalJs(`document.getElementById('refreshSubscriptionsBtn').click()`);
            await sleep(50);
            assert.equal(countRequests('POST'), 1);
            assert.equal(countRequests('GET', '/donatur/subscriptions'), 0);
            recordScenario(`F52.2 ${authProbe.name}`);
        }

        // 4d: AbortController timeout test (Clock acceleration via quick setTimeout shim before navigate/action)
        resetLogs();
        await navigate(`${baseUrl}/donatur/settings`);
        await evalJs(`(() => {
            const origSetTimeout = window.setTimeout;
            window.setTimeout = function(fn, delay, ...args) {
                if (delay >= 15000) {
                    return origSetTimeout.call(window, fn, 60, ...args);
                }
                return origSetTimeout.call(window, fn, delay, ...args);
            };
        })()`);
        currentMockHandler = async ({ req, res, pathname }) => {
            if (req.method === 'POST' && pathname === '/donatur/subscriptions/901/pause') {
                await sleep(300);
                if (!res.writableEnded) {
                    jsonRes(res, 200, { success: true });
                }
                return true;
            }
            if (req.method === 'GET' && pathname === '/donatur/subscriptions') {
                jsonRes(res, 200, { success: true, subscriptions: cloneItems(BASE_LIST_ITEMS) });
                return true;
            }
            return false;
        };
        await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="901"] button[data-sub-action="pause"]').click()`);
        await waitModalOpen();
        await evalJs(`document.getElementById('subscriptionConfirmProceedBtn').click()`);
        await waitUntil(`document.querySelector('.wc-sub-item[data-subscription-id="901"] [data-role="uncertain-notice"]') !== null`);
        assert.equal(countRequests('POST'), 1);
        recordScenario('F52.2 AbortController POST timeout');

        // ============================================================
        // 5. F52.3 DEFERRED CONCURRENCY COORDINATION (ALL 6 SCENARIOS)
        // ============================================================
        // Scenario F52.3-1 & 3: Stale manual GET #1 starts before POST pause, resolves AFTER POST + failed GET #2 -> MUST NOT overwrite or unlock!
        resetLogs();
        await navigate(`${baseUrl}/donatur/settings`);
        let releaseStaleGet = null;
        let getCallNo = 0;
        currentMockHandler = async ({ req, res, pathname }) => {
            if (req.method === 'GET' && pathname === '/donatur/subscriptions') {
                getCallNo += 1;
                if (getCallNo === 1) {
                    await new Promise((r) => { releaseStaleGet = r; });
                    jsonRes(res, 200, { success: true, subscriptions: cloneItems(BASE_LIST_ITEMS) });
                } else if (getCallNo === 2) {
                    jsonRes(res, 503, { message: 'GET 2 failed' });
                } else {
                    const fresh = cloneItems(BASE_LIST_ITEMS);
                    fresh[0].status = 'inactive';
                    fresh[0].management_state = 'paused';
                    jsonRes(res, 200, { success: true, subscriptions: fresh });
                }
                return true;
            }
            if (req.method === 'POST' && pathname === '/donatur/subscriptions/901/pause') {
                jsonRes(res, 200, { success: true, status: 'inactive', management_state: 'paused', message: 'Dijeda' });
                return true;
            }
            return false;
        };

        await evalJs(`document.getElementById('refreshSubscriptionsBtn').click()`);
        while (!releaseStaleGet) { await sleep(15); }
        await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="901"] button[data-sub-action="pause"]').click()`);
        await waitModalOpen();
        await evalJs(`document.getElementById('subscriptionConfirmProceedBtn').click()`);
        await waitUntil(`document.querySelector('.wc-sub-item[data-subscription-id="901"] [data-role="stale-lock-notice"]') !== null`);
        releaseStaleGet();
        await sleep(120);

        const afterStaleGetLocked = await evalJs(`(() => {
            const c = document.querySelector('.wc-sub-item[data-subscription-id="901"]');
            return {
                hasLock: c.querySelector('[data-role="stale-lock-notice"]') !== null,
                disabled: Array.from(c.querySelectorAll('button[data-sub-action]')).every(b => b.disabled)
            };
        })()`);
        assert.equal(afterStaleGetLocked.hasLock, true, 'F52.3: stale GET started before POST must not clear lock');
        assert.equal(afterStaleGetLocked.disabled, true, 'F52.3: buttons stay disabled after stale GET arrives');
        recordScenario('F52.3-1 & 3 Stale manual GET before POST resolves late');

        // Scenario F52.3-4: Failed refresh for 901 + unrelated 902 mutation triggers GET updating 902; verify 901 recovers only via GET strictly newer than 901's barrier while earlier GET cannot unlock 901
        resetLogs();
        await navigate(`${baseUrl}/donatur/settings`);
        let sub901RefreshAttempts = 0;
        let f523Items = cloneItems(BASE_LIST_ITEMS);
        let releaseEarlyManualGet = null;
        currentMockHandler = async ({ req, res, pathname }) => {
            if (req.method === 'POST' && pathname === '/donatur/subscriptions/901/pause') {
                f523Items[0].status = 'inactive';
                f523Items[0].management_state = 'paused';
                jsonRes(res, 200, { success: true, status: 'inactive', management_state: 'paused' });
                return true;
            }
            if (req.method === 'POST' && pathname === '/donatur/subscriptions/902/resume') {
                f523Items[1].status = 'active';
                f523Items[1].management_state = 'active';
                jsonRes(res, 200, { success: true, status: 'active', management_state: 'active' });
                return true;
            }
            if (req.method === 'GET' && pathname === '/donatur/subscriptions') {
                sub901RefreshAttempts += 1;
                if (sub901RefreshAttempts === 1) {
                    // Older manual GET held in-flight
                    await new Promise((r) => { releaseEarlyManualGet = r; });
                    jsonRes(res, 200, { success: true, subscriptions: cloneItems(BASE_LIST_ITEMS) });
                } else if (sub901RefreshAttempts === 2) {
                    // 901 refresh fails!
                    jsonRes(res, 503, { message: 'Temporary error' });
                } else {
                    // 902's refresh is newer than BOTH 901 & 902 -> valid global snapshot
                    jsonRes(res, 200, { success: true, subscriptions: cloneItems(f523Items) });
                }
                return true;
            }
            return false;
        };
        await evalJs(`document.getElementById('refreshSubscriptionsBtn').click()`);
        while (!releaseEarlyManualGet) { await sleep(15); }
        await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="901"] button[data-sub-action="pause"]').click()`);
        await waitModalOpen();
        await evalJs(`document.getElementById('subscriptionConfirmProceedBtn').click()`);
        await waitModalClosed();
        await waitUntil(`document.querySelector('.wc-sub-item[data-subscription-id="901"] [data-role="stale-lock-notice"]') !== null`);
        // Resolving old GET #1 keeps 901 locked!
        releaseEarlyManualGet();
        await sleep(100);
        assert.equal(await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="901"] [data-role="stale-lock-notice"]') !== null`), true);
        // Unrelated operation on 902 fetches fresh authoritative full snapshot #3 -> unlocks cleanly with fresh state
        await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="902"] button[data-sub-action="resume"]').click()`);
        await waitModalOpen();
        await evalJs(`document.getElementById('subscriptionConfirmProceedBtn').click()`);
        await waitModalClosed();
        await waitUntil(`document.querySelector('.wc-sub-item[data-subscription-id="902"] [data-role="status-badge"]').textContent.trim() === 'Aktif'`);
        assert.equal(await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="901"] [data-role="status-badge"]').textContent.trim()`), 'Dijeda');
        recordScenario('F52.3-4 Failed refresh barrier vs newer full refresh');

        // Scenario F52.3-2: Two subscriptions operated concurrently (901 Pause while 902 Resume)
        resetLogs();
        await navigate(`${baseUrl}/donatur/settings`);
        let concItems = cloneItems(BASE_LIST_ITEMS);
        let release901 = null;
        currentMockHandler = async ({ req, res, pathname }) => {
            if (req.method === 'POST' && pathname === '/donatur/subscriptions/901/pause') {
                await new Promise((r) => { release901 = r; });
                concItems[0].status = 'inactive';
                concItems[0].management_state = 'paused';
                jsonRes(res, 200, { success: true, status: 'inactive', management_state: 'paused' });
                return true;
            }
            if (req.method === 'POST' && pathname === '/donatur/subscriptions/902/resume') {
                concItems[1].status = 'active';
                concItems[1].management_state = 'active';
                jsonRes(res, 200, { success: true, status: 'active', management_state: 'active' });
                return true;
            }
            if (req.method === 'GET' && pathname === '/donatur/subscriptions') {
                jsonRes(res, 200, { success: true, subscriptions: cloneItems(concItems) });
                return true;
            }
            return false;
        };
        await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="901"] button[data-sub-action="pause"]').click()`);
        await waitModalOpen();
        await evalJs(`document.getElementById('subscriptionConfirmProceedBtn').click()`);
        while (!release901) { await sleep(15); }
        // 901 is inFlight -> 902 still usable concurrently
        await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="902"] button[data-sub-action="resume"]').click()`);
        await waitModalOpen();
        await evalJs(`document.getElementById('subscriptionConfirmProceedBtn').click()`);
        await waitUntil(`document.querySelector('.wc-sub-item[data-subscription-id="902"] [data-role="status-badge"]').textContent.trim() === 'Aktif'`);
        // While 901 is still inFlight, 901 must still show busy notice
        assert.equal(await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="901"] [data-role="loading-notice"]') !== null`), true);
        release901();
        await waitUntil(`document.querySelector('.wc-sub-item[data-subscription-id="901"] [data-role="status-badge"]').textContent.trim() === 'Dijeda'`);
        recordScenario('F52.3-2 Concurrent operations across 2 subscriptions');

        // Scenario F52.3-4 & 5: Modal stays open while state changes via refresh -> Confirm sends 0 POSTs!
        resetLogs();
        await navigate(`${baseUrl}/donatur/settings`);
        let changedItems = cloneItems(BASE_LIST_ITEMS);
        changedItems[0].status = 'inactive';
        changedItems[0].management_state = 'cancelled';
        currentMockHandler = async ({ req, res, pathname }) => {
            if (req.method === 'GET' && pathname === '/donatur/subscriptions') {
                jsonRes(res, 200, { success: true, subscriptions: changedItems });
                return true;
            }
            return false;
        };
        await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="901"] button[data-sub-action="pause"]').click()`);
        await waitModalOpen();
        // Trigger background refresh while modal open
        await evalJs(`document.getElementById('refreshSubscriptionsBtn').click()`);
        await waitUntil(`document.querySelector('.wc-sub-item[data-subscription-id="901"] [data-role="status-badge"]').textContent.trim() === 'Dibatalkan'`);
        // Confirm stale modal -> revalidation blocks POST!
        await evalJs(`document.getElementById('subscriptionConfirmProceedBtn').click()`);
        await sleep(80);
        assert.equal(countRequests('POST'), 0, 'F52.3-5: stale modal must send 0 POST requests after state changed to cancelled');
        recordScenario('F52.3-5 Open modal revalidates against refreshed state (0 POST)');

        // ============================================================
        // 6. F52.4: HTTP 409 OCCUPIED GUARD + RECOVERY VIA RECONCILE
        // ============================================================
        resetLogs();
        await navigate(`${baseUrl}/donatur/settings`);
        let f524Step = 1;
        currentMockHandler = async ({ req, res, pathname }) => {
            if (req.method === 'POST' && pathname === '/donatur/subscriptions/901/pause') {
                jsonRes(res, 409, {
                    success: false,
                    status: 'active',
                    management_state: 'active',
                    operation_in_progress: true,
                    conflict_reason: 'guard_occupied',
                    message: 'Operasi subscription sedang berjalan atau baru saja dimulai.',
                });
                return true;
            }
            if (req.method === 'POST' && pathname === '/donatur/subscriptions/901/reconcile') {
                const updated = cloneItems(BASE_LIST_ITEMS);
                jsonRes(res, 200, { success: true, status: 'active', management_state: 'active', operation_in_progress: false, message: 'Status aktif konsisten.' });
                f524Step = 3;
                return true;
            }
            if (req.method === 'GET' && pathname === '/donatur/subscriptions') {
                const items = cloneItems(BASE_LIST_ITEMS);
                if (f524Step === 1) {
                    items[0].operation_in_progress = true;
                } else {
                    items[0].operation_in_progress = false;
                }
                jsonRes(res, 200, { success: true, subscriptions: items });
                return true;
            }
            return false;
        };

        await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="901"] button[data-sub-action="pause"]').click()`);
        await waitModalOpen();
        await evalJs(`document.getElementById('subscriptionConfirmProceedBtn').click()`);
        await waitUntil(`document.querySelector('.wc-sub-item[data-subscription-id="901"] [data-role="guard-notice"]') !== null`);
        assert.equal(await evalJs(`Array.from(document.querySelectorAll('.wc-sub-item[data-subscription-id="901"] button[data-sub-action]')).every(b => b.disabled)`), true);

        // Guard releases on backend (GET shows operation_in_progress=false, active) -> UI MUST NOT blindly re-enable Pause/Cancel; offers Periksa Status!
        f524Step = 2;
        await evalJs(`document.getElementById('refreshSubscriptionsBtn').click()`);
        await waitUntil(`document.querySelector('.wc-sub-item[data-subscription-id="901"] [data-role="uncertain-notice"]') !== null`);
        const afterGuardReleaseActions = await evalJs(`Array.from(document.querySelectorAll('.wc-sub-item[data-subscription-id="901"] button[data-sub-action]')).map(b => b.dataset.subAction)`);
        assert.deepEqual(afterGuardReleaseActions, ['reconcile']);

        // Safe Periksa Status confirms -> restores Pause + Cancel!
        await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="901"] button[data-sub-action="reconcile"]').click()`);
        await waitUntil(`document.querySelector('.wc-sub-item[data-subscription-id="901"] button[data-sub-action="pause"]') !== null`);
        recordScenario('F52.4 Guard occupied 409 -> release -> Periksa Status recovery');

        // ============================================================
        // 7. F52.5: REAL donasi-uang.blade.php RECURRING CHECKOUT VALIDATION
        // ============================================================
        const checkoutTests = [
            {
                name: 'F52.5 malformed JSON -> no subscription_created redirect & locked',
                respond: (res) => { res.writeHead(200, { 'Content-Type': 'application/json' }); res.end('{broken'); },
                expectRedirect: null, expectLocked: true,
            },
            {
                name: 'F52.5 200 success=false -> no redirect & locked',
                respond: (res) => jsonRes(res, 200, { success: false, message: 'Gagal sementara' }),
                expectRedirect: null, expectLocked: true,
            },
            {
                name: 'F52.5 200 missing subscription_id -> no redirect & locked',
                respond: (res) => jsonRes(res, 200, { success: true, status: 'active' }),
                expectRedirect: null, expectLocked: true,
            },
            {
                name: 'F52.5 ambiguous 422 -> locked with safe guidance',
                respond: (res) => jsonRes(res, 422, { success: false, ambiguous: true, message: 'Status pembuatan langganan ambigu.' }),
                expectRedirect: null, expectLocked: true,
            },
            {
                name: 'F52.5 checkout AbortController timeout -> no redirect & locked',
                accelerateTimeout: true,
                respond: async (res) => {
                    await sleep(250);
                    if (!res.writableEnded) {
                        jsonRes(res, 200, { success: true, subscription_id: 'late', status: 'active' });
                    }
                },
                expectRedirect: null, expectLocked: true,
            },
            {
                name: 'F52.5 valid 3DS response -> redirects via MidtransNew3ds.authenticate',
                respond: (res) => jsonRes(res, 200, { success: true, requires_3ds: true, redirect_url: 'https://sandbox.example.test/3ds', order_id: 'INIT-SUB-3DS-1' }),
                expectRedirect: 'status=waiting_payment', expectLocked: false,
            },
            {
                name: 'F52.5 valid non-3DS creation -> redirects to subscription_created',
                respond: (res) => jsonRes(res, 201, { success: true, subscription_id: 'sub-ok-1', status: 'active', frequency: 'bulanan', nominal: 100000 }),
                expectRedirect: 'status=subscription_created', expectLocked: false,
            },
        ];

        for (const ct of checkoutTests) {
            resetLogs();
            await navigate(`${baseUrl}/donatur/donasi/campaign-checkout-sintetis/uang`);
            if (ct.accelerateTimeout) {
                await evalJs(`(() => {
                    const orig = window.setTimeout;
                    window.setTimeout = function(fn, delay, ...args) {
                        if (delay >= 20000) {
                            return orig.call(window, fn, 60, ...args);
                        }
                        return orig.call(window, fn, delay, ...args);
                    };
                })()`);
            }
            currentMockHandler = async ({ req, res, pathname }) => {
                if (req.method === 'POST' && pathname === '/donatur/donasi/campaign-checkout-sintetis/uang') {
                    await ct.respond(res);
                    return true;
                }
                return false;
            };
            await evalJs(`(() => {
                document.getElementById('nominalInput').value = '100000';
                document.getElementById('rutin').checked = true;
                document.getElementById('recurringConsent').checked = true;
                document.getElementById('card_number_input').value = '4811111111111114';
                document.getElementById('card_exp_month_input').value = '12';
                document.getElementById('card_exp_year_input').value = '28';
                document.getElementById('card_cvv_input').value = '123';
                document.getElementById('submitBtn').click();
            })()`);

            if (ct.expectRedirect) {
                await waitUntil(`Boolean(document.getElementById('riwayatPage'))`);
                const q = await evalJs(`document.getElementById('query').textContent`);
                assert.equal(q.includes(ct.expectRedirect), true);
            } else {
                await waitUntil(`!document.getElementById('cardTokenError').classList.contains('d-none')`);
                const locked = await evalJs(`document.getElementById('submitBtn').disabled`);
                assert.equal(locked, ct.expectLocked);
            }
            assert.equal(countRequests('POST', '/donatur/donasi/'), 1);
            recordScenario(ct.name);
        }

        // ============================================================
        // 7B. E5.4-A: UNCERTAINTY PERSISTENCE & AUTHORITATIVE CLEARANCE
        // ============================================================

        // E5.4-A 1: Unchanged management state with changed schedule MUST NOT clear uncertainty
        resetLogs();
        await navigate(`${baseUrl}/donatur/settings`);
        currentMockHandler = async ({ req, res, pathname }) => {
            if (req.method === 'POST' && pathname === '/donatur/subscriptions/901/pause') {
                jsonRes(res, 500, { message: 'Server error sementara' });
                return true;
            }
            if (req.method === 'GET' && pathname === '/donatur/subscriptions') {
                const changedScheduleOnly = cloneItems(BASE_LIST_ITEMS);
                changedScheduleOnly[0].next_execution_at = '2027-01-15T06:00:00Z';
                changedScheduleOnly[0].schedule.status = 'stale_preserved';
                changedScheduleOnly[0].schedule.is_displayable_upcoming = false;
                changedScheduleOnly[0].schedule.display_wib = null;
                jsonRes(res, 200, { success: true, subscriptions: changedScheduleOnly });
                return true;
            }
            return false;
        };
        await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="901"] button[data-sub-action="pause"]').click()`);
        await waitModalOpen();
        await evalJs(`document.getElementById('subscriptionConfirmProceedBtn').click()`);
        await waitModalClosed();
        await waitUntil(`document.querySelector('.wc-sub-item[data-subscription-id="901"] [data-role="uncertain-notice"]') !== null`);
        assert.deepEqual(
            await evalJs(`Array.from(document.querySelectorAll('.wc-sub-item[data-subscription-id="901"] button[data-sub-action]')).map(b => b.dataset.subAction)`),
            ['reconcile'],
            'Schedule-only change must NOT restore Pause/Cancel'
        );
        recordScenario('E5.4-A 4.2 Unchanged management state with changed schedule keeps Periksa Status');

        // E5.4-A 2: Guard release with changed schedule but unchanged active state MUST require Periksa Status until reconcile confirms
        resetLogs();
        await navigate(`${baseUrl}/donatur/settings`);
        let guardPhase = 1;
        currentMockHandler = async ({ req, res, pathname }) => {
            if (req.method === 'POST' && pathname === '/donatur/subscriptions/901/pause') {
                jsonRes(res, 409, { success: false, status: 'active', management_state: 'active', operation_in_progress: true, message: 'Operasi lain sedang berjalan.' });
                return true;
            }
            if (req.method === 'POST' && pathname === '/donatur/subscriptions/901/reconcile') {
                guardPhase = 3;
                jsonRes(res, 200, { success: true, status: 'active', management_state: 'active', operation_in_progress: false, message: 'Status aktif terkonfirmasi.' });
                return true;
            }
            if (req.method === 'GET' && pathname === '/donatur/subscriptions') {
                const items = cloneItems(BASE_LIST_ITEMS);
                items[0].next_execution_at = '2027-01-20T06:00:00Z';
                items[0].schedule.status = 'stale_preserved';
                items[0].operation_in_progress = guardPhase === 1;
                jsonRes(res, 200, { success: true, subscriptions: items });
                return true;
            }
            return false;
        };
        await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="901"] button[data-sub-action="pause"]').click()`);
        await waitModalOpen();
        await evalJs(`document.getElementById('subscriptionConfirmProceedBtn').click()`);
        await waitModalClosed();
        await waitUntil(`document.querySelector('.wc-sub-item[data-subscription-id="901"] [data-role="guard-notice"]') !== null`);
        guardPhase = 2;
        await evalJs(`document.getElementById('refreshSubscriptionsBtn').click()`);
        await waitUntil(`document.querySelector('.wc-sub-item[data-subscription-id="901"] [data-role="uncertain-notice"]') !== null`);
        assert.deepEqual(
            await evalJs(`Array.from(document.querySelectorAll('.wc-sub-item[data-subscription-id="901"] button[data-sub-action]')).map(b => b.dataset.subAction)`),
            ['reconcile']
        );
        await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="901"] button[data-sub-action="reconcile"]').click()`);
        await waitUntil(`document.querySelector('.wc-sub-item[data-subscription-id="901"] button[data-sub-action="pause"]') !== null`);
        recordScenario('E5.4-A 4.2 Guard release with changed schedule requires reconcile before restore');

        // E5.4-A 3: Reload after uncertain outcome keeps Periksa Status; explicit reconcile restores cleanly across subsequent reload
        resetLogs();
        await navigate(`${baseUrl}/donatur/settings`);
        currentMockHandler = async ({ req, res, pathname }) => {
            if (req.method === 'POST' && pathname === '/donatur/subscriptions/901/pause') {
                jsonRes(res, 500, { message: 'Kendala penyedia.' });
                return true;
            }
            if (req.method === 'POST' && pathname === '/donatur/subscriptions/901/reconcile') {
                jsonRes(res, 200, { success: true, status: 'active', management_state: 'active', operation_in_progress: false, message: 'Status aktif terkonfirmasi.' });
                return true;
            }
            if (req.method === 'GET' && pathname === '/donatur/subscriptions') {
                jsonRes(res, 200, { success: true, subscriptions: cloneItems(BASE_LIST_ITEMS) });
                return true;
            }
            return false;
        };
        await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="901"] button[data-sub-action="pause"]').click()`);
        await waitModalOpen();
        await evalJs(`document.getElementById('subscriptionConfirmProceedBtn').click()`);
        await waitModalClosed();
        await waitUntil(`document.querySelector('.wc-sub-item[data-subscription-id="901"] [data-role="uncertain-notice"]') !== null`);
        // Reload WITHOUT clearing sessionStorage
        await navigate(`${baseUrl}/donatur/settings`, false);
        assert.equal(await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="901"] [data-role="uncertain-notice"]') !== null`), true, 'Reload must keep uncertainty notice');
        assert.deepEqual(
            await evalJs(`Array.from(document.querySelectorAll('.wc-sub-item[data-subscription-id="901"] button[data-sub-action]')).map(b => b.dataset.subAction)`),
            ['reconcile'],
            'Reload after uncertain pause must NOT silently restore Pause/Cancel'
        );
        // Explicit reconcile succeeds -> restores Pause/Cancel + persists clear across another reload
        await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="901"] button[data-sub-action="reconcile"]').click()`);
        await waitUntil(`document.querySelector('.wc-sub-item[data-subscription-id="901"] button[data-sub-action="pause"]') !== null`);
        await navigate(`${baseUrl}/donatur/settings`, false);
        assert.equal(await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="901"] [data-role="uncertain-notice"]')`), null);
        assert.deepEqual(
            await evalJs(`Array.from(document.querySelectorAll('.wc-sub-item[data-subscription-id="901"] button[data-sub-action]')).map(b => b.dataset.subAction)`),
            ['pause', 'cancel']
        );
        recordScenario('E5.4-A 4.1 Reload preserves uncertainty until reconcile clears it');

        // E5.4-A 4: Reload after HTTP 200 + failed GET renders authoritative Paused; durable reconciliation stays Periksa Status
        resetLogs();
        await navigate(`${baseUrl}/donatur/settings`);
        currentMockHandler = async ({ req, res, pathname }) => {
            if (req.method === 'POST' && pathname === '/donatur/subscriptions/901/pause') {
                jsonRes(res, 200, { success: true, status: 'inactive', management_state: 'paused', message: 'Donasi rutin berhasil dijeda.' });
                return true;
            }
            if (req.method === 'GET' && pathname === '/donatur/subscriptions') {
                jsonRes(res, 503, { message: 'Gagal sementara' });
                return true;
            }
            return false;
        };
        await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="901"] button[data-sub-action="pause"]').click()`);
        await waitModalOpen();
        await evalJs(`document.getElementById('subscriptionConfirmProceedBtn').click()`);
        await waitModalClosed();
        await waitUntil(`document.querySelector('.wc-sub-item[data-subscription-id="901"] [data-role="stale-lock-notice"]') !== null`);
        await navigate(`${baseUrl}/donatur/settings?scenario=settings-901-paused`, false);
        assert.equal(await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="901"] [data-role="status-badge"]').textContent.trim()`), 'Dijeda');
        assert.deepEqual(
            await evalJs(`Array.from(document.querySelectorAll('.wc-sub-item[data-subscription-id="901"] button[data-sub-action]')).map(b => b.dataset.subAction)`),
            ['resume', 'cancel']
        );
        await navigate(`${baseUrl}/donatur/settings?scenario=settings-901-reconciliation`, false);
        assert.equal(await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="901"] [data-role="status-badge"]').textContent.trim()`), 'Menunggu Verifikasi');
        assert.deepEqual(
            await evalJs(`Array.from(document.querySelectorAll('.wc-sub-item[data-subscription-id="901"] button[data-sub-action]')).map(b => b.dataset.subAction)`),
            ['reconcile']
        );
        recordScenario('E5.4-A 4.1 Reload renders authoritative paused and durable reconciliation states');

        // E5.4-A 5: Terminal cancelled state clears uncertainty cleanly
        resetLogs();
        await navigate(`${baseUrl}/donatur/settings`);
        let cancelPhase = 1;
        currentMockHandler = async ({ req, res, pathname }) => {
            if (req.method === 'POST' && pathname === '/donatur/subscriptions/901/pause') {
                cancelPhase = 2;
                jsonRes(res, 500, { message: 'Gangguan sementara' });
                return true;
            }
            if (req.method === 'GET' && pathname === '/donatur/subscriptions') {
                const items = cloneItems(BASE_LIST_ITEMS);
                if (cancelPhase === 2) {
                    items[0].status = 'inactive';
                    items[0].management_state = 'cancelled';
                    items[0].next_execution_at = null;
                    items[0].schedule.status = 'cancelled_no_upcoming_charge';
                }
                jsonRes(res, 200, { success: true, subscriptions: items });
                return true;
            }
            return false;
        };
        await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="901"] button[data-sub-action="pause"]').click()`);
        await waitModalOpen();
        await evalJs(`document.getElementById('subscriptionConfirmProceedBtn').click()`);
        await waitModalClosed();
        await waitUntil(`document.querySelector('.wc-sub-item[data-subscription-id="901"] [data-role="status-badge"]').textContent.trim() === 'Dibatalkan'`);
        assert.equal(await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="901"] [data-role="uncertain-notice"]')`), null);
        assert.equal(await evalJs(`document.querySelectorAll('.wc-sub-item[data-subscription-id="901"] button[data-sub-action]').length`), 0);
        recordScenario('E5.4-A 4.2 Terminal cancelled state clears uncertainty');

        // ============================================================
        // 8. VISUAL SCREENSHOTS (DESKTOP 1440, MOBILE 390, DARK MODE, RECONCILIATION, EMPTY)
        // ============================================================
        await setViewport(1440, 900);
        await navigate(`${baseUrl}/donatur/settings?scenario=settings-screenshot`);
        await saveScreenshot('01-desktop-settings-subscriptions.png');

        // Toggle dark mode to verify dark theme legibility
        await evalJs(`document.querySelector('[data-theme-toggle]')?.click()`);
        await sleep(120);
        await saveScreenshot('07-desktop-settings-dark-mode.png');
        await evalJs(`document.querySelector('[data-theme-toggle]')?.click()`);
        await sleep(120);

        // Mobile viewport 390x844
        await setViewport(390, 844);
        await navigate(`${baseUrl}/donatur/settings?scenario=settings-screenshot`);
        await evalJs(`document.getElementById('donasiRutinCard')?.scrollIntoView({ block: 'start' })`);
        await sleep(80);
        await saveScreenshot('02-mobile-settings-subscriptions.png');

        // Reconciliation pending state after 202
        await setViewport(1440, 900);
        await navigate(`${baseUrl}/donatur/settings?scenario=settings-screenshot`);
        let reconItems = JSON.parse(
            renderBlade('settings-screenshot', baseUrl).match(/<script type="application\/json" id="subscriptionInitialData">(.*?)<\/script>/s)[1]
        ).items;
        currentMockHandler = async ({ req, res, pathname }) => {
            if (req.method === 'POST' && pathname === '/donatur/subscriptions/904/reconcile') {
                jsonRes(res, 202, { success: false, status: 'active', management_state: 'reconciliation', message: 'Pemeriksaan penyedia masih tertunda. Gunakan Periksa Status kembali beberapa saat lagi.' });
                return true;
            }
            if (req.method === 'GET' && pathname === '/donatur/subscriptions') {
                jsonRes(res, 200, { success: true, subscriptions: reconItems });
                return true;
            }
            return false;
        };
        await evalJs(`document.querySelector('.wc-sub-item[data-subscription-id="904"] button[data-sub-action="reconcile"]').click()`);
        await waitUntil(`document.getElementById('subscriptionFeedback').textContent.includes('tertunda')`);
        await saveScreenshot('04-reconciliation-pending.png');

        // Empty state
        await navigate(`${baseUrl}/donatur/settings?scenario=settings-empty`);
        assert.equal(await evalJs(`document.querySelector('[data-role="empty-state"]').textContent.trim()`), 'Belum ada donasi rutin terdaftar.');
        await saveScreenshot('06-empty-state.png');

        // Final safety & console assertions
        assert.equal(blockedExternalRequests.length, 0, `Unexpected external requests attempted: ${blockedExternalRequests.join(', ')}`);
        assert.equal(pageErrors.length, 0, `Uncaught page errors: ${pageErrors.join(' | ')}`);

        console.log(JSON.stringify({
            browserPath: BROWSER_PATH,
            bootstrapRealLoaded: true,
            totalScenarios: scenarioResults.length,
            passedScenarios: scenarioResults.length,
            blockedExternalRequests: blockedExternalRequests.length,
            pageErrors: pageErrors.length,
            consoleErrorsCount: consoleErrors.length,
            screenshotsDir: ARTIFACT_SCREENSHOT_DIR,
            screenshots: fs.readdirSync(ARTIFACT_SCREENSHOT_DIR).sort(),
            scenarios: scenarioResults,
        }, null, 2));
    } finally {
        if (cdp) {
            try {
                await Promise.race([
                    cdp.send('Browser.close'),
                    sleep(1000),
                ]);
            } catch {
                // Ignore errors while closing CDP session during teardown
            }
            cdp.close();
        }

        if (browserProc && !browserExited && browserExitPromise) {
            await Promise.race([browserExitPromise, sleep(1500)]);
            if (!browserExited) {
                try {
                    browserProc.kill();
                } catch {
                    // Process already exited
                }
                await Promise.race([browserExitPromise, sleep(2000)]);
            }
        }

        if (server) {
            server.close();
        }

        if (tempProfileDir) {
            const expectedParent = path.resolve(os.tmpdir());
            const resolvedProfile = path.resolve(tempProfileDir);
            if (
                path.dirname(resolvedProfile) === expectedParent
                && path.basename(resolvedProfile).startsWith('wecare-e53-browser-profile-')
                && fs.existsSync(resolvedProfile)
            ) {
                for (let attempt = 0; attempt < 5; attempt += 1) {
                    try {
                        fs.rmSync(resolvedProfile, { recursive: true, force: true });
                        break;
                    } catch {
                        await sleep(200);
                    }
                }
            }
        }
    }
}

runSuite().catch((err) => {
    console.error(err);
    process.exit(1);
});
