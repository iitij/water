import http from 'node:http';
import puppeteer from 'puppeteer';

// Keep Node/Puppeteer loaded as well as Chromium. Starting either for every
// invoice causes heavy disk paging on the small production server.
let browser;
let renderPage;
let busy = false;
async function connect() {
    if (!browser?.connected) {
        browser = await puppeteer.connect({ browserURL: 'http://127.0.0.1:9222' });
        renderPage = undefined;
    }
    return browser;
}
const server = http.createServer(async (request, response) => {
    if (request.method === 'GET' && request.url === '/health') {
        response.writeHead(browser?.connected ? 200 : 503).end();
        return;
    }
    if (request.method !== 'POST' || request.url !== '/render') {
        response.writeHead(404).end();
        return;
    }
    if (busy) {
        response.writeHead(503, { 'Retry-After': '5' }).end();
        return;
    }
    busy = true;
    let page;
    try {
        const chunks = [];
        let size = 0;
        for await (const chunk of request) {
            size += chunk.length;
            if (size > 5 * 1024 * 1024) {
                response.writeHead(413).end();
                return;
            }
            chunks.push(chunk);
        }
        const { html, headerHtml, footerHtml, options = {} } = JSON.parse(Buffer.concat(chunks).toString());
        if (typeof html !== 'string') {
            response.writeHead(422).end();
            return;
        }
        await connect();
        if (!renderPage || renderPage.isClosed()) {
            renderPage = await browser.newPage();
            await renderPage.setRequestInterception(true);
            renderPage.on('request', (asset) => {
            // All fonts and images are embedded. Never wait on external sites
            // or a nested HTTP request to the same small PHP worker pool.
            if (/^(data:|about:)/.test(asset.url())) asset.continue();
            else asset.abort();
            });
        }
        page = renderPage;
        await page.setContent(html, { waitUntil: 'load', timeout: 15000 });
        await page.evaluate(() => document.fonts.ready);
        if (options.waitForReady) {
            await page.waitForFunction(options.waitForReady, { timeout: options.waitForReadyTimeout ?? 15000 });
        }
        const margin = options.margins;
        const paper = options.paperSize;
        const bytes = await page.pdf({
            format: paper ? undefined : (options.format || 'A4'),
            ...(paper ? { width: `${paper.width}${paper.unit || 'mm'}`, height: `${paper.height}${paper.unit || 'mm'}` } : {}),
            landscape: options.orientation === 'landscape',
            ...(margin ? { margin: Object.fromEntries(['top', 'right', 'bottom', 'left'].map((side) => [side, `${margin[side] ?? 0}${margin.unit || 'mm'}`])) } : {}),
            printBackground: true,
            preferCSSPageSize: true,
            scale: options.scale ?? 1,
            pageRanges: options.pageRanges ?? '',
            tagged: options.tagged ?? false,
            displayHeaderFooter: Boolean(headerHtml || footerHtml),
            headerTemplate: headerHtml || '<span></span>',
            footerTemplate: footerHtml || '<span></span>',
            timeout: 20000,
        });
        // Keep one warm renderer, but release the previous document and the
        // busy flag before replying so the next request can begin immediately.
        await page.setContent('<!doctype html><html><body></body></html>');
        page = undefined;
        busy = false;
        response.writeHead(200, { 'Content-Type': 'application/pdf' }).end(Buffer.from(bytes));
    } catch (error) {
        // Do not write the document, account data or HTML to logs.
        console.error(`PDF render failed: ${error.name}`);
        if (!response.headersSent) response.writeHead(503);
        response.end();
    } finally {
        if (page) {
            await page.close().catch(() => {});
            renderPage = undefined;
        }
        busy = false;
    }
});
server.requestTimeout = 45000;
server.listen(9310, '127.0.0.1');
connect().catch((error) => console.error(`PDF browser connection: ${error.name}`));
