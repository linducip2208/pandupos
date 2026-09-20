import { test, expect } from 'playwright/test';
import { OWNER, login } from './helpers.js';

test('wrong password stays on login with an error', async ({ page }) => {
    await page.goto('/login');
    await page.locator('input[type="email"]').fill(OWNER.email);
    await page.locator('input[type="password"]').fill('salah-salah-salah');
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/login/);
    await expect(page.locator('.login-alert')).toBeVisible();
});

test('demo owner can log in and reaches the dashboard', async ({ page }) => {
    await login(page, OWNER);
    await expect(page).toHaveURL(/\/dashboard/);
    await expect(page.locator('body')).toContainText('Toko Demo');
});
