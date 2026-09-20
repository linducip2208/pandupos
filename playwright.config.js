import { defineConfig } from 'playwright/test';

export default defineConfig({
    testDir: './e2e',
    timeout: 90000,
    expect: { timeout: 15000 },
    workers: 1,
    fullyParallel: false,
    reporter: [['list']],
    use: {
        baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8766',
        headless: true,
        viewport: { width: 1440, height: 900 },
    },
    // One login per role; journey specs reuse the saved session so the run
    // stays far under the login throttle (throttle:10,1 is production
    // behavior and must not be weakened for tests).
    projects: [
        { name: 'setup', testMatch: /.*\.setup\.js/ },
        { name: 'journeys', testIgnore: [/.*\.setup\.js/, /01-auth\.spec\.js/], dependencies: ['setup'] },
        { name: 'auth', testMatch: /01-auth\.spec\.js/ },
    ],
});
