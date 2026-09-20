import { test as setup, expect } from 'playwright/test';
import { OWNER, ADMIN } from './helpers.js';

setup('authenticate as demo owner', async ({ page }) => {
    await page.goto('/login');
    await page.locator('input[type="email"]').fill(OWNER.email);
    await page.locator('input[type="password"]').fill(OWNER.password);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL('**/dashboard');
    await page.context().storageState({ path: 'e2e/.auth/owner.json' });
});

setup('authenticate as platform admin', async ({ page }) => {
    await page.goto('/login');
    await page.locator('input[type="email"]').fill(ADMIN.email);
    await page.locator('input[type="password"]').fill(ADMIN.password);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL('**/dashboard');
    await page.context().storageState({ path: 'e2e/.auth/admin.json' });
});
