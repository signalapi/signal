/**
 * signal_browser — Playwright-backed page automation for flow "browser" steps.
 *
 * POST /run  {url, successUrlPattern?, actions?, timeoutMs?}
 *   Opens the URL in Chromium and drives it until the page lands on a URL
 *   matching successUrlPattern (or the navigation settles, if no pattern).
 *
 *   PSP challenge pages are handled by DETECTION, not configuration: each
 *   handler looks for the fingerprints of a known sandbox simulator (in every
 *   frame) and performs its completion gesture. New PSPs = new handler entry.
 *
 *   actions (optional) run before the handler loop, for pages that need a
 *   bespoke gesture: [{type: 'fill'|'click', selector, value?}, ...]
 *
 * Response: {ok, sessionStatus: 'completed'|'timeout'|'error', finalUrl, log: [...], durationMs}
 *
 * GET /health → {ok: true}
 */
const http = require('http');
const { chromium } = require('playwright');

const PORT = Number(process.env.PORT || 7300);
const DEFAULT_TIMEOUT_MS = 45000;
const POLL_MS = 500;

/**
 * Known PSP sandbox/simulator handlers, tried in order in every frame.
 * detect: a selector (string) that identifies the page.
 * act: completes the challenge inside that frame.
 */
const HANDLERS = [
    {
        name: 'checkout.com',
        detect: 'input[type="password"], input[name="password"]',
        act: async (frame) => {
            const input = frame.locator('input[type="password"], input[name="password"]').first();
            await input.fill(process.env.CHECKOUT_3DS_PASSWORD || 'Checkout1!');
            const submit = frame.locator('button[type="submit"], input[type="submit"], #txtButton, button').first();
            await submit.click();
        },
    },
    {
        name: 'stripe',
        detect: '#test-source-authorize-3ds, button:has-text("Complete authentication"), button:has-text("COMPLETE")',
        act: async (frame) => {
            await frame.locator('#test-source-authorize-3ds, button:has-text("Complete authentication"), button:has-text("COMPLETE")').first().click();
        },
    },
    {
        name: 'adyen',
        detect: 'input[name="answer"]',
        act: async (frame) => {
            await frame.locator('input[name="answer"]').fill(process.env.ADYEN_3DS_PASSWORD || 'password');
            await frame.locator('button[type="submit"], input[type="submit"]').first().click();
        },
    },
    {
        name: 'generic-success-button',
        detect: 'button:has-text("Success"), button:has-text("Approve"), button:has-text("Authorize"), a:has-text("Success"), button:has-text("Onayla"), button:has-text("Devam"), input[type="submit"][value*="ucce"]',
        act: async (frame) => {
            await frame.locator('button:has-text("Success"), button:has-text("Approve"), button:has-text("Authorize"), a:has-text("Success"), button:has-text("Onayla"), button:has-text("Devam"), input[type="submit"][value*="ucce"]').first().click();
        },
    },
];

let browserPromise = null;
async function getBrowser() {
    if (!browserPromise) {
        browserPromise = chromium.launch({ channel: 'chromium', args: ['--no-sandbox', '--disable-dev-shm-usage'] });
    }
    return browserPromise;
}

async function runSession({ url, successUrlPattern, actions, timeoutMs }) {
    const started = Date.now();
    const deadline = started + Math.min(Number(timeoutMs) || DEFAULT_TIMEOUT_MS, 120000);
    const log = [];
    const browser = await getBrowser();
    const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 800 }, userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36' });
    const page = await context.newPage();
    const pattern = successUrlPattern ? new RegExp(successUrlPattern, 'i') : null;
    // A handler that acted gets a cooldown so a slow post-click navigation
    // isn't interrupted by an immediate re-fire on the same fingerprint.
    const lastActed = new Map();

    const isDone = () => (pattern ? pattern.test(page.url()) : false);

        const debugSnapshot = async () => {
            try {
                const frames = page.frames().map((f) => f.url().slice(0, 100));
                log.push(`frames: ${frames.join(' | ')}`);
                for (const f of page.frames()) {
                    const texts = await f.locator('button, input[type=submit]').allTextContents().catch(() => []);
                    if (texts.length) log.push(`buttons@${f.url().slice(0, 60)}: ${texts.map((t) => t.trim()).filter(Boolean).slice(0, 8).join(' / ')}`);
                }
            } catch (e) { log.push(`debug error ${String(e.message || e).slice(0, 80)}`); }
        };


    try {
        await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
        log.push(`goto ${url}`);

        for (const a of actions || []) {
            const loc = page.locator(a.selector).first();
            if (a.type === 'fill') await loc.fill(String(a.value ?? ''));
            else await loc.click();
            log.push(`action ${a.type} ${a.selector}`);
        }

        let quietSince = Date.now();
        let lastUrl = page.url();

        while (Date.now() < deadline) {
            if (isDone()) {
                log.push(`success-url ${page.url()}`);
                return { ok: true, sessionStatus: 'completed', finalUrl: page.url(), log, durationMs: Date.now() - started };
            }

            if (page.url() !== lastUrl) {
                log.push(`nav ${page.url()}`);
                lastUrl = page.url();
                quietSince = Date.now();
            }

            let acted = false;
            for (const frame of page.frames()) {
                for (const h of HANDLERS) {
                    const cooldownUntil = lastActed.get(h.name) || 0;
                    if (Date.now() < cooldownUntil) continue;
                    try {
                        const found = await frame.locator(h.detect).first().isVisible({ timeout: 100 }).catch(() => false);
                        if (!found) continue;
                        await h.act(frame);
                        lastActed.set(h.name, Date.now() + 4000);
                        log.push(`handler ${h.name} @ ${frame.url().slice(0, 120)}`);
                        acted = true;
                        quietSince = Date.now();
                        break;
                    } catch (e) {
                        log.push(`handler ${h.name} error: ${String(e.message || e).slice(0, 160)}`);
                    }
                }
                if (acted) break;
            }

            // No pattern given: consider the flow settled after a quiet spell —
            // but never while a challenge-looking frame is present (those can
            // take a long time to render their confirm button).
            const challengePresent = page.frames().some((f) => /challenge|three-?ds|3d_secure|acs|secure\//i.test(f.url()));
            if (!pattern && !acted && !challengePresent && Date.now() - quietSince > 9000) {
                await debugSnapshot();
                return { ok: true, sessionStatus: 'completed', finalUrl: page.url(), log, durationMs: Date.now() - started };
            }

            await page.waitForTimeout(POLL_MS);
        }

        await debugSnapshot();
        return { ok: false, sessionStatus: 'timeout', finalUrl: page.url(), log, durationMs: Date.now() - started };
    } catch (e) {
        return { ok: false, sessionStatus: 'error', finalUrl: page.url(), error: String(e.message || e).slice(0, 300), log, durationMs: Date.now() - started };
    } finally {
        await context.close().catch(() => {});
    }
}

http.createServer((req, res) => {
    if (req.method === 'GET' && req.url === '/health') {
        res.writeHead(200, { 'content-type': 'application/json' });
        res.end(JSON.stringify({ ok: true }));
        return;
    }
    if (req.method !== 'POST' || req.url !== '/run') {
        res.writeHead(404);
        res.end();
        return;
    }
    let body = '';
    req.on('data', (c) => { body += c; if (body.length > 1e6) req.destroy(); });
    req.on('end', async () => {
        try {
            const params = JSON.parse(body || '{}');
            if (!params.url) throw new Error('url is required');
            const result = await runSession(params);
            res.writeHead(200, { 'content-type': 'application/json' });
            res.end(JSON.stringify(result));
        } catch (e) {
            res.writeHead(400, { 'content-type': 'application/json' });
            res.end(JSON.stringify({ ok: false, sessionStatus: 'error', error: String(e.message || e) }));
        }
    });
}).listen(PORT, () => console.log(`signal_browser listening on :${PORT}`));
