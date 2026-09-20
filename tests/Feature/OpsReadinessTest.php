<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        // phpunit sqlite uses :memory: → command must return FAILURE, not throw.
        if (DB::getDriverName() !== 'sqlite' || config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Only applies to in-memory sqlite runs.');
        }
        $code = Artisan::call('backup:database');
        $this->assertEquals(1, $code);
    }

    public function test_backup_command_succeeds_and_verifies_on_mysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL backup path only.');
        }
        if ($this->binaryPath('mysqldump') === null) {
            $this->markTestSkipped('mysqldump binary not available.');
        }
        $disk = Storage::disk('local');
        $before = collect($disk->files('backups'));

        $this->assertSame(0, Artisan::call('backup:database'));
        $fresh = collect($disk->files('backups'))->diff($before);
        $this->assertTrue($fresh->contains(fn ($f) => str_ends_with($f, '.sql')), 'Backup must produce a mysql artifact.');
        $this->assertTrue($fresh->contains(fn ($f) => str_ends_with($f, '.manifest.json')), 'Backup must produce a manifest.');

        $disk->delete($fresh->all());
    }

    private function binaryPath(string $bin): ?string
    {
        $found = trim((string) shell_exec(($this->isWindows() ? 'where' : 'command -v').' '.$bin.' 2>NUL'));
        $first = preg_split('/\R/', $found)[0] ?? '';

        return $first !== '' && is_file($first) ? $first : null;
    }

    private function isWindows(): bool
    {
        return DIRECTORY_SEPARATOR === '\\';
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
