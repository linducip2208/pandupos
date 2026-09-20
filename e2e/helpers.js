export const OWNER = {
    email: process.env.E2E_OWNER_EMAIL || 'owner@demo.local',
    password: process.env.E2E_OWNER_PASSWORD || 'password',
};

export const ADMIN = {
    email: process.env.E2E_ADMIN_EMAIL || 'admin@e2e.local',
    password: process.env.E2E_ADMIN_PASSWORD || 'E2eAdmin123!',
};

export const stamp = Date.now().toString(36).toUpperCase();

export async function login(page, { email, password }) {
    await page.goto('/login');
    await page.locator('input[type="email"]').fill(email);
    await page.locator('input[type="password"]').fill(password);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL('**/dashboard');
}

export async function confirmSensitive(page, password) {
    await page.waitForURL('**/confirm-sensitive');
    await page.locator('input[type="password"]').fill(password);
    await page.locator('button[type="submit"]').click();
}

export async function waitLivewire(page) {
    // Livewire v4 serves updates from a hashed endpoint (livewire-<hash>/update).
    await page.waitForResponse(
        (r) => r.url().includes('/livewire') && r.url().includes('/update') && r.request().method() === 'POST',
        { timeout: 20000 },
    ).catch(() => {});
}
