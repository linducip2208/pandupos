<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ModuleManager;
use App\Services\ModuleRegistry;
use App\Services\TenantProvisioningService;
use App\Support\ModuleManifestValidator;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\POS\Providers\POSServiceProvider;
use Tests\TestCase;

class ModuleCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_module_list_runs(): void
    {
        $this->seed(PlatformSeeder::class);
        $this->artisan('platform:module:list')->assertOk();
    }

    public function test_module_health_runs_and_single_slug(): void
    {
        $this->seed(PlatformSeeder::class);
        $this->artisan('platform:module:health')->assertOk();
        $this->artisan('platform:module:health', ['slug' => 'POS'])->assertOk();
    }

    public function test_manifest_validator_detects_errors(): void
    {
        $this->assertNotEmpty(ModuleManifestValidator::validate([], 'test'));
        $this->assertNotEmpty(ModuleManifestValidator::validate(['name' => 'X', 'slug' => 'BAD SLUG', 'version' => '1.0.0'], 'test'));
        $this->assertEmpty(ModuleManifestValidator::validate([
            'name' => 'POS', 'slug' => 'pos', 'version' => '1.0.0',
            'dependencies' => ['inventory'],
            'permissions' => ['pos.sale.create'],
            'entitlements' => ['pos.access'],
            'navigation' => [['label' => 'POS', 'route' => 'pos.index']],
            'provider' => POSServiceProvider::class,
        ], 'pos'));

        $setErrors = ModuleManifestValidator::validateSet([
            'a' => ['slug' => 'a', 'dependencies' => ['b']],
            'b' => ['slug' => 'b', 'dependencies' => ['a']],
        ]);
        $this->assertNotEmpty($setErrors);
    }

    public function test_enable_disable_does_not_delete_data(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko Cmd', $owner);
        $manager = app(ModuleManager::class);

        $manager->disable($tenant->id, 'pos');
        $this->assertFalse(app(ModuleRegistry::class)->isEnabled($tenant->id, 'pos'));

        $manager->enable($tenant->id, 'pos');
        $this->assertTrue(app(ModuleRegistry::class)->isEnabled($tenant->id, 'pos'));

        // Data retained: tenant row still exists.
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
    }
}
