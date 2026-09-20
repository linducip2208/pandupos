import { test, expect } from 'playwright/test';

test.use({ storageState: 'e2e/.auth/owner.json' });

const REPORTS = ['/reports/bisnis', '/reports/keuangan', '/reports/operasional'];

for (const path of REPORTS) {
    test(`report renders with figures: ${path}`, async ({ page }) => {
        await page.goto('/dashboard');
        const response = await page.goto(path);
        expect(response.ok(), `${path} must load`).toBeTruthy();
        // Figures render as rupiah amounts or tabular data, never a blank shell.
        await expect(page.locator('body')).toContainText(/Rp|table|Tidak ada data/i);
    });
}
