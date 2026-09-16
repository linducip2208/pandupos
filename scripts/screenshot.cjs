const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const baseUrl = process.env.SCREENSHOT_BASE_URL || 'http://127.0.0.1:8765';
const output = path.join(__dirname, '..', 'public', 'marketing', 'screens');
fs.mkdirSync(output, { recursive: true });

async function revealPage(page) {
    await page.evaluate(async () => {
        for (let y = 0; y < document.body.scrollHeight; y += 650) {
            window.scrollTo(0, y);
            await new Promise(resolve => setTimeout(resolve, 80));
        }
        window.scrollTo(0, 0);
    });
    await page.waitForTimeout(300);
}

(async () => {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });

    for (const [name, url] of [['landing', '/'], ['docs', '/docs'], ['blog', '/blog'], ['login', '/login'], ['faq', '/faq'], ['contact', '/contact'], ['portal-login', '/portal/login']]) {
        await page.goto(baseUrl + url, { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(700);
        await revealPage(page);
        await page.screenshot({ path: path.join(output, `${name}.png`), fullPage: true });
    }

    await page.goto(baseUrl + '/login', { waitUntil: 'domcontentloaded' });
    await page.locator('input[type="email"]').fill('');
    await page.type('input[type="email"]', process.env.DEMO_EMAIL || 'owner@demo.local', { delay: 50 });
    await page.locator('input[type="password"]').fill('');
    await page.type('input[type="password"]', process.env.DEMO_PASSWORD || 'password', { delay: 50 });
    await page.waitForTimeout(500);
    await page.locator('button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded');

    for (const [name, url] of [['dashboard', '/dashboard'], ['pos', '/pos'], ['report-business', '/reports/bisnis'], ['report-finance', '/reports/keuangan'], ['report-operations', '/reports/operasional'], ['approvals', '/approvals']]) {
        await page.goto(baseUrl + url, { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(700);
        await revealPage(page);
        await page.screenshot({ path: path.join(output, `${name}.png`), fullPage: true });
    }

    const customerContext = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
    const customerPage = await customerContext.newPage();
    await customerPage.goto(baseUrl + '/portal/login', { waitUntil: 'domcontentloaded' });
    await customerPage.fill('input[type="email"]', process.env.CUSTOMER_DEMO_EMAIL || 'customer@demo.local');
    await customerPage.fill('input[type="password"]', process.env.CUSTOMER_DEMO_PASSWORD || 'password');
    await customerPage.click('button[type="submit"]');
    await customerPage.waitForLoadState('domcontentloaded');
    for (const [name, url] of [['portal-dashboard', '/portal'], ['portal-orders', '/portal/orders'], ['portal-invoices', '/portal/invoices']]) {
        await customerPage.goto(baseUrl + url, { waitUntil: 'domcontentloaded' });
        await customerPage.waitForTimeout(500);
        await customerPage.screenshot({ path: path.join(output, `${name}.png`), fullPage: true });
    }

    const adminContext = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
    const adminPage = await adminContext.newPage();
    await adminPage.goto(baseUrl + '/login', { waitUntil: 'domcontentloaded' });
    await adminPage.fill('input[type="email"]', process.env.PLATFORM_DEMO_EMAIL || 'admin@pandupos.local');
    await adminPage.fill('input[type="password"]', process.env.PLATFORM_DEMO_PASSWORD || 'ChangeMe123!');
    await adminPage.click('button[type="submit"]');
    await adminPage.waitForLoadState('domcontentloaded');
    for (const [name, url] of [
        ['platform-dashboard', '/platform/dashboard'], ['platform-tenants', '/platform/tenants'],
        ['platform-plans', '/platform/plans'], ['platform-modules', '/platform/modules'],
        ['platform-billing', '/platform/billing'], ['platform-subscriptions', '/platform/subscriptions'],
        ['platform-audit', '/platform/audit'], ['platform-blog', '/platform/blog'],
        ['platform-integrations', '/platform/integrations'],
    ]) {
        await adminPage.goto(baseUrl + url, { waitUntil: 'domcontentloaded' });
        await adminPage.waitForTimeout(500);
        await adminPage.screenshot({ path: path.join(output, `${name}.png`), fullPage: true });
    }

    await browser.close();
})();
