<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Operations readiness: backup/restore/health/alert/monitoring commands.
 */
class OpsReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_check_reports_all_dimensions(): void
    {
        $code = Artisan::call('health:check');
        $out = Artisan::output();
        $this->assertContains($code, [0, 1]);
        foreach (['app', 'database', 'cache', 'queue', 'failed_jobs', 'storage_writable', 'disk', 'backup_freshness', 'mail', 'offline_sync'] as $key) {
            $this->assertStringContainsString($key, $out);
        }
    }

    public function test_alert_check_emits_testable_paths(): void
    {
        $code = Artisan::call('alert:check');
        $out = Artisan::output();
        $this->assertEquals(0, $code);
        $this->assertStringContainsString('backup', strtolower($out));
        $this->assertStringContainsString('scheduler', strtolower($out));
    }

    public function test_backup_command_fails_safely_on_memory(): void
    {
        // phpunit uses :memory: → command must return FAILURE, not throw.
        $code = Artisan::call('backup:database');
        $this->assertEquals(1, $code);
    }

    public function test_restore_refuses_missing_artifact(): void
    {
        $code = Artisan::call('backup:restore', ['file' => 'backups/does-not-exist.sqlite', '--force' => true]);
        $this->assertEquals(1, $code);
    }

    public function test_monitoring_does_not_leak_secrets(): void
    {
        Artisan::call('health:check', ['--json' => true]);
        $out = Artisan::output();
        foreach (['APP_KEY', 'DB_PASSWORD', 'MYSQL_PWD', 'sk_live'] as $needle) {
            $this->assertStringNotContainsString($needle, $out);
        }
    }
}
