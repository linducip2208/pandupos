<?php

namespace Tests\Feature;

use App\Models\AiProviderConfig;
use App\Models\AiUsage;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AI\FakeAiProvider;
use App\Services\AiService;
use App\Services\ModuleManager;
use App\Services\StockService;
use App\Services\TenantProvisioningService;
use App\Support\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * AI module: encrypted provider configs, metered completions with budgets,
 * deterministic anomaly detection, isolation, RBAC and API.
 */
class AiTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $name = 'Toko AI', bool $enableAi = true): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $owner);
        $owner->refresh();
        if ($enableAi) {
            app(ModuleManager::class)->enable($tenant->id, 'ai');
        }
        TenantContext::setId($tenant->id);

        return [$tenant, $owner];
    }

    public function test_module_gates_workspace_and_api(): void
    {
        [$tenant, $owner] = $this->context('Toko AI Gate', false);

        $this->actingAs($owner)->get('/ai')->assertForbidden();
        $this->actingAs($owner)->postJson('/api/v1/ai/ask', ['feature' => 'sales_assistant', 'prompt' => 'hi'], ['X-Tenant-ID' => $tenant->id])->assertForbidden();

        app(ModuleManager::class)->enable($tenant->id, 'ai');
        $this->actingAs($owner)->get('/ai')->assertOk()->assertSeeText('Provider');
    }

    public function test_config_encrypts_keys_and_validates_providers(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(AiService::class);

        $config = $svc->configure($tenant->id, [
            'provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'sk-live-secret', 'monthly_token_cap' => 1000,
        ], $owner->id);
        $raw = AiProviderConfig::withoutGlobalScopes()->find($config->id);
        $this->assertStringNotContainsString('sk-live-secret', $raw->getAttributes()['api_key']);
        $this->assertSame('sk-live-secret', Crypt::decryptString($raw->api_key));

        foreach ([
            ['provider' => 'skynet', 'model' => 'x', 'api_key' => 'k'],
            ['provider' => 'custom', 'model' => 'x', 'api_key' => 'k'],
            ['provider' => 'openai', 'model' => '', 'api_key' => 'k'],
        ] as $bad) {
            try {
                $svc->configure($tenant->id, $bad, $owner->id);
                $this->fail('Invalid provider config must be rejected: '.json_encode($bad));
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
    }

    public function test_ask_meters_usage_and_enforces_budget(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(AiService::class);
        $svc->configure($tenant->id, ['provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'k', 'monthly_token_cap' => 15], $owner->id);
        $fake = new FakeAiProvider('Jawaban');

        $answer = $svc->ask($tenant->id, 'sales_assistant', [['role' => 'user', 'content' => 'Promo apa?']], $owner->id, $fake);
        $this->assertStringContainsString('Jawaban', $answer);
        $this->assertSame(15, AiUsage::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sum('tokens_in') + AiUsage::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sum('tokens_out'));
        $budget = $svc->budget($tenant->id);
        $this->assertSame(15, $budget['used']);
        $this->assertSame(15, $budget['cap']);

        // Second call (15 more) would exceed the cap of 20 → refused before any provider call.
        $callsBefore = count($fake->calls);
        try {
            $svc->ask($tenant->id, 'sales_assistant', [['role' => 'user', 'content' => 'Lagi?']], $owner->id, $fake);
            $this->fail('Over-budget ask must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertSame($callsBefore, count($fake->calls));

        try {
            $svc->ask($tenant->id, 'teleportasi', [['role' => 'user', 'content' => 'x']], $owner->id, $fake);
            $this->fail('Unknown feature must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_anomaly_detection_uses_real_data(): void
    {
        [$tenant, $owner] = $this->context();
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Gudang AI', 'code' => 'WH-'.uniqid()]);
        $p = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Hampir Habis', 'sku' => 'LOW', 'product_type' => 'stock', 'alert_quantity' => 5, 'track_inventory' => true]);
        $v = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $p->id, 'name' => 'Default', 'sku' => 'LOW-V', 'purchase_price' => 10, 'sell_price' => 20]);
        app(StockService::class)->increase($tenant->id, $warehouse->id, $v->id, 2, 10, 'opening', null);

        $findings = app(AiService::class)->detectAnomalies($tenant->id);
        $types = array_column($findings, 'type');
        $this->assertContains('low_stock', $types);
        $this->assertContains('dead_stock', $types); // nothing sold in 30 days
        $low = collect($findings)->firstWhere('type', 'low_stock');
        $this->assertStringContainsString('LOW-V', $low['message']);
    }

    public function test_tenant_isolation_and_rbac(): void
    {
        [$tenantA] = $this->context('Toko AI A');
        [$tenantB, $ownerB] = $this->context('Toko AI B');
        app(AiService::class)->configure($tenantA->id, ['provider' => 'openai', 'model' => 'm', 'api_key' => 'secret-a'], null);

        TenantContext::setId($tenantB->id);
        $this->assertSame(0, AiProviderConfig::query()->count());
        $this->actingAs($ownerB)->get('/ai')->assertOk()->assertDontSee('secret-a');

        $member = User::factory()->create();
        Membership::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id, 'user_id' => $member->id,
            'branch_id' => Branch::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->value('id'),
            'is_active' => true,
        ]);
        $member->forceFill(['current_tenant_id' => $tenantB->id])->save();
        $this->actingAs($member)->get('/ai')->assertForbidden();
        $member->givePermissionTo('ai.view');
        $this->actingAs($member)->get('/ai')->assertOk();
        $this->actingAs($member)->post('/ai/ask', ['feature' => 'sales_assistant', 'prompt' => 'x'])->assertForbidden();
    }

    public function test_api_usage_and_anomalies(): void
    {
        [$tenant, $owner] = $this->context();
        $headers = ['X-Tenant-ID' => $tenant->id];

        $this->actingAs($owner)->getJson('/api/v1/ai/usage', $headers)->assertOk()->assertJsonPath('data.used', 0);
        $this->actingAs($owner)->getJson('/api/v1/ai/anomalies', $headers)->assertOk();
        // Ask without a configured provider: 404 (nothing to bill against).
        $this->actingAs($owner)->postJson('/api/v1/ai/ask', ['feature' => 'sales_assistant', 'prompt' => 'halo'], $headers)->assertNotFound();
    }

    public function test_workspace_flow(): void
    {
        [$tenant, $owner] = $this->context();

        $this->actingAs($owner)->post('/ai/config', [
            'provider' => 'openrouter', 'model' => 'x/y', 'api_key' => 'orkey',
            'base_url' => 'https://openrouter.ai/api/v1', 'monthly_token_cap' => 500,
        ])->assertRedirect();
        $this->assertDatabaseHas('ai_provider_configs', ['tenant_id' => $tenant->id, 'provider' => 'openrouter']);
        $this->actingAs($owner)->get('/ai')->assertOk()->assertSeeText('openrouter');
    }
}
