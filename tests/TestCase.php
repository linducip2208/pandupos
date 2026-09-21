<?php

namespace Tests;

use App\Support\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->guardTestDatabase();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /**
     * Fail fast before any test touches a non-test database. Allowed targets:
     * the canonical MySQL test database, the E2E/drill databases, or sqlite
     * :memory: (explicit opt-in for light isolated tests only — never
     * production evidence).
     */
    private function guardTestDatabase(): void
    {
        abort_if(app()->isProduction(), 500, 'Tests must never run with APP_ENV=production.');
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $allowed = ['pandupos_test', 'pandupos_e2e', 'pandupos_drill', ':memory:'];
        abort_unless(in_array($database, $allowed, true), 500, "Refusing to run tests against database [{$database}] on connection [{$connection}].");
    }
}
