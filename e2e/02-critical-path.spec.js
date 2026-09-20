import { test, expect } from 'playwright/test';
import { stamp, waitLivewire } from './helpers.js';

const productName = `E2E Kopi ${stamp}`;
const productSku = `E2E-${stamp}`;
const registerName = `E2E Register ${stamp}`;

test.describe.serial('critical path: catalog, purchase, register, POS', () => {
    test.use({ storageState: 'e2e/.auth/owner.json' });

    test('owner session reaches the dashboard', async ({ page }) => {
        await page.goto('/dashboard');
        await expect(page).toHaveURL(/\/dashboard/);
    });

    test('creates a product through the catalog UI', async ({ page }) => {
        await page.goto('/dashboard');
        await page.goto('/products/create');

        await page.locator('#name').fill(productName);
        await page.locator('#sku').fill(productSku);
        await page.locator('#unit_id').selectOption({ index: 1 });
        await page.locator('input[name="variants[0][sku]"]').fill(`${productSku}-V`);
        await page.locator('input[name="variants[0][purchase_price]"]').fill('2000');
        await page.locator('input[name="variants[0][sell_price]"]').fill('5000');
        await page.locator('input[name="warehouse_ids[]"]').first().check();
        await page.getByRole('button', { name: 'Buat produk' }).click();

        await expect(page).toHaveURL(/\/products/);
        await expect(page.locator('body')).toContainText(productName);
    });

    test('creates a purchase order and receives it into stock', async ({ page }) => {
        await page.goto('/dashboard');
        await page.goto('/purchasing/orders/create');

        await page.locator('select[name="contact_id"]').selectOption({ label: 'Supplier Demo' });
        await page.locator('select[name="warehouse_id"]').selectOption({ index: 1 });

        const variantSelect = page.locator('select[name="lines[0][product_variant_id]"]');
        const variantValue = await variantSelect.locator('option', { hasText: productName }).getAttribute('value');
        expect(variantValue, 'new product variant must be offered on the PO form').toBeTruthy();
        await variantSelect.selectOption(variantValue);
        await page.locator('input[name="lines[0][quantity]"]').fill('10');
        await page.locator('select[name="lines[0][unit_id]"]').selectOption({ index: 1 });
        await page.locator('input[name="lines[0][unit_cost]"]').fill('2000');
        await page.locator('button[value="submit"]').click();

        // storePurchase answers back() with a status flash (draft / approval / created).
        await expect(page.locator('body')).toContainText(/PO (draft disimpan|dibuat dan menunggu approval|berhasil dibuat)/);
        await page.goto('/purchasing');
        await expect(page.locator('body')).toContainText(productSku);

        // Drafts need an explicit submit; submitted POs may wait for approval.
        // Both actions answer back(), so assert on content rather than navigation.
        if (await page.getByRole('button', { name: 'Kirim PO' }).count() > 0) {
            await page.getByRole('button', { name: 'Kirim PO' }).first().click();
            await expect(page.locator('body')).toContainText(/PO dikirim|menunggu approval|ordered|partial/);
        }

        if (await page.getByRole('link', { name: 'Buka approval' }).count() > 0) {
            test.skip(true, 'PO routed to approval; covered by PurchaseApprovalTest at HTTP level');
        }

        // Receive the full remaining quantity of our line (scoped to our PO row).
        const variantSku = `${productSku}-V`;
        const row = page.locator('tr', { hasText: variantSku }).first();
        await row.locator('input[aria-label^="Jumlah terima"]').fill('10');
        await row.getByRole('button', { name: 'Posting receipt' }).click();
        await expect(row).toContainText('10,000/10,000');
    });

    test('opens a cash register session', async ({ page }) => {
        await page.goto('/dashboard');
        await page.goto('/registers');

        // Close any leftover open session so every run starts clean.
        const sessionsCard = page.locator('.card', { hasText: 'Sesi dan penutupan kas' });
        if (await sessionsCard.getByText('Buka', { exact: true }).count() > 0) {
            const openRow = sessionsCard.locator('tr', { hasText: 'Buka' }).first();
            const expText = await openRow.getByText(/Ekspektasi/).innerText();
            await openRow.locator('input[name="actual_amount"]').fill(expText.replace(/[^0-9]/g, ''));
            await openRow.getByRole('button', { name: 'Tutup sesi' }).click();
            await expect(sessionsCard.getByText('Buka', { exact: true })).toHaveCount(0);
        }

        await page.locator('input[name="name"]').fill(registerName);
        await page.locator('select[name="branch_id"]').selectOption({ index: 1 });
        await page.getByRole('button', { name: 'Buat register' }).click();
        await page.waitForURL('**/registers');

        const row = page.locator('.card', { hasText: 'Register aktif' }).locator('tr', { hasText: registerName });
        await row.locator('input[name="opening_amount"]').fill('50000');
        await row.getByRole('button', { name: 'Buka' }).click();
        await page.waitForURL('**/registers');
        await expect(row).toContainText(/Buka #\d+/);
    });

    test('sells the received product through POS checkout', async ({ page }) => {
        await page.goto('/dashboard');
        await page.goto('/pos');

        // Pick the session opened above (last open session of this user).
        const sessionSelect = page.locator('select[aria-label="Sesi register"]');
        const sessionValue = await sessionSelect.locator('option:last-child').getAttribute('value');
        expect(sessionValue, 'an open register session is required').toBeTruthy();
        await sessionSelect.selectOption(sessionValue);
        await waitLivewire(page);

        await page.locator('#pos-search').fill(productName);
        await waitLivewire(page);
        await page.getByRole('button', { name: new RegExp(productName) }).first().click();
        await waitLivewire(page);
        await expect(page.locator('body')).toContainText(productName);

        await page.getByPlaceholder('Nominal').fill('100000');
        await page.getByRole('button', { name: /Bayar/ }).click();
        await waitLivewire(page);
        await expect(page.locator('body')).toContainText(/Struk:/);
    });

    test('closes the register session with matching cash', async ({ page }) => {
        await page.goto('/dashboard');
        await page.goto('/registers');

        const sessionsCard = page.locator('.card', { hasText: 'Sesi dan penutupan kas' });
        const openRow = sessionsCard.locator('tr', { hasText: registerName }).first();
        const expText = await openRow.getByText(/Ekspektasi/).innerText();
        await openRow.locator('input[name="actual_amount"]').fill(expText.replace(/[^0-9]/g, ''));
        await openRow.getByRole('button', { name: 'Tutup sesi' }).click();
        await expect(sessionsCard.getByText('Buka', { exact: true })).toHaveCount(0);
        await expect(sessionsCard.locator('tr', { hasText: registerName }).first()).toContainText('Tutup');
        await expect(sessionsCard.locator('tr', { hasText: registerName }).first()).toContainText(/Selisih\s*Rp 0/);
    });
});
