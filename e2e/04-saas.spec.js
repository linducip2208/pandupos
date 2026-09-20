import { test, expect } from 'playwright/test';
import { ADMIN, confirmSensitive } from './helpers.js';

test.use({ storageState: 'e2e/.auth/admin.json' });

test('platform admin changes a tenant plan with password confirmation', async ({ page }) => {
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/dashboard/);

    // Deterministic demo tenant from fresh seed.
    await page.goto('/platform/tenants');
    await expect(page.locator('body')).toContainText('Toko Demo');
    await page.goto('/platform/tenants/1');
    await expect(page).toHaveURL(/\/platform\/tenants\/1/);
    const currentPlan = (await page.locator('body').innerText()).match(/Plan:\s*([^(]+)/)?.[1]?.trim();

    // Pick a different plan; the id lives in the entitlements form action.
    await page.goto('/platform/plans');
    const cards = page.locator('.card', { has: page.locator('form[action*="/entitlements"]') });
    const count = await cards.count();
    expect(count).toBeGreaterThan(1);
    let target = null;
    for (let i = 0; i < count; i++) {
        const title = await cards.nth(i).locator('h3').innerText();
        const name = title.split('(')[0].trim();
        if (name && name !== currentPlan) {
            const action = await cards.nth(i).locator('form').getAttribute('action');
            target = { name, id: action.match(/\/platform\/plans\/(\d+)\/entitlements/)[1] };
            break;
        }
    }
    expect(target, 'a different plan must exist').toBeTruthy();

    // First submit is intercepted by fresh-password confirmation ...
    await page.goto('/platform/tenants/1');
    await page.locator('input[name="plan_id"]').fill(target.id);
    await page.getByRole('button', { name: 'Change' }).click();
    await confirmSensitive(page, ADMIN.password);

    // ... confirmation consumed the POST, so submit once more for effect.
    await expect(page).toHaveURL(/\/platform\/tenants\/1/);
    await page.locator('input[name="plan_id"]').fill(target.id);
    await page.getByRole('button', { name: 'Change' }).click();
    await expect(page).toHaveURL(/\/platform\/tenants\/1/);
    await expect(page.locator('body')).toContainText(target.name);
});
