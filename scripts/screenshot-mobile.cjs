const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const baseUrl = process.env.SCREENSHOT_BASE_URL || 'http://127.0.0.1:8765';
const output = path.join(__dirname, '..', 'public', 'marketing', 'screens-mobile');
fs.mkdirSync(output, { recursive: true });

async function revealPage(page) {
    await page.evaluate(async () => {
        for (let y = 0; y < document.body.scrollHeight; y += 500) {
            window.scrollTo(0, y);
            await new Promise(resolve => setTimeout(resolve, 90));
        }
        window.scrollTo(0, 0);
    });
    await page.waitForTimeout(300);
}

(async () => {
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 414, height: 896 }, deviceScaleFactor: 2, isMobile: true });
    const page = await context.newPage();

    for (const [name, url] of [['landing', '/'], ['docs', '/docs'], ['login', '/login']]) {
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
    await page.locator('button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded');
    for (const [name, url] of [['dashboard', '/dashboard'], ['report-business', '/reports/bisnis'], ['approvals', '/approvals']]) {
        await page.goto(baseUrl + url, { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(700);
        await revealPage(page);
        await page.screenshot({ path: path.join(output, `${name}.png`), fullPage: true });
    }

    await page.goto(baseUrl + '/dashboard', { waitUntil: 'domcontentloaded' });
    await page.locator('.admin-menu-toggle').click();
    await page.waitForTimeout(300);
    await page.screenshot({ path: path.join(output, 'navigation-drawer.png'), fullPage: false });

    const customerPage = await context.newPage();
    await customerPage.goto(baseUrl + '/portal/login', { waitUntil: 'domcontentloaded' });
    await customerPage.fill('input[type="email"]', process.env.CUSTOMER_DEMO_EMAIL || 'customer@demo.local');
    await customerPage.fill('input[type="password"]', process.env.CUSTOMER_DEMO_PASSWORD || 'password');
    await customerPage.click('button[type="submit"]');
    await customerPage.waitForLoadState('domcontentloaded');
    for (const [name, url] of [['portal-dashboard', '/portal'], ['portal-invoices', '/portal/invoices']]) {
        await customerPage.goto(baseUrl + url, { waitUntil: 'domcontentloaded' });
        await customerPage.waitForTimeout(500);
        await customerPage.screenshot({ path: path.join(output, `${name}.png`), fullPage: true });
    }

    const adminPage = await context.newPage();
    await adminPage.goto(baseUrl + '/login', { waitUntil: 'domcontentloaded' });
    await adminPage.fill('input[type="email"]', process.env.PLATFORM_DEMO_EMAIL || 'admin@pandupos.local');
    await adminPage.fill('input[type="password"]', process.env.PLATFORM_DEMO_PASSWORD || 'ChangeMe123!');
    await adminPage.click('button[type="submit"]');
    await adminPage.waitForLoadState('domcontentloaded');
    for (const [name, url] of [['platform-tenants', '/platform/tenants'], ['platform-integrations', '/platform/integrations']]) {
        await adminPage.goto(baseUrl + url, { waitUntil: 'domcontentloaded' });
        await adminPage.waitForTimeout(500);
        await adminPage.screenshot({ path: path.join(output, `${name}.png`), fullPage: true });
    }

    await browser.close();
})();
