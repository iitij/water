import assert from 'node:assert/strict';
import puppeteer from 'puppeteer';

const browser = await puppeteer.launch({
    executablePath: process.env.CHROME_BIN,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
});

try {
    const page = await browser.newPage();
    const pageErrors = [];
    const panelAssets = new Map();
    page.on('pageerror', error => pageErrors.push(error.message));
    page.on('response', response => {
        const path = new URL(response.url()).pathname;
        if (path.startsWith('/panel-assets/')) panelAssets.set(path, response.status());
    });
    const response = await page.goto('http://127.0.0.1:8799/admin/login', { waitUntil: 'networkidle0' });
    assert.equal(response.status(), 200);
    const password = 'input[autocomplete="current-password"]';
    assert.equal(await page.$eval(password, input => input.type), 'password');
    await page.click('[x-ref="showPasswordAction"]');
    await page.waitForFunction(selector => document.querySelector(selector)?.type === 'text', {}, password);
    await page.click('[x-ref="hidePasswordAction"]');
    await page.waitForFunction(selector => document.querySelector(selector)?.type === 'password', {}, password);
    for (const path of [
        '/panel-assets/js/filament/forms/components/text-input/password-reveal.js',
        '/panel-assets/js/filament/support/support.js',
        '/panel-assets/css/filament/filament/app.css',
    ]) assert.equal(panelAssets.get(path), 200, path);
    assert.deepEqual([...panelAssets].filter(([, status]) => status >= 400), []);
    assert.deepEqual(pageErrors, []);
    console.log('Packaged login rendered and toggled password visibility using the published Filament assets.');
} finally {
    await browser.close();
}
