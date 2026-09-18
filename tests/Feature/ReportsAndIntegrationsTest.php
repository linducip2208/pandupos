<?php

namespace Tests\Feature;

use App\Integrations\Adapters\OpenAICompatibleAdapter;
use App\Models\ApprovalRequest;
use App\Models\IntegrationProvider;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Warehouse;
use App\Payments\Adapters\FormatPaymentAdapter;
use App\Services\ApprovalService;
use App\Services\SaleService;
use App\Services\StockService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ReportsAndIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_business_report_pages_and_exports_are_available(): void
    {
        $this->seed(PlatformSeeder::class);
        $user = User::factory()->create();
        app(TenantProvisioningService::class)->provision('Tenant Laporan', $user);

        foreach (['bisnis', 'keuangan', 'operasional'] as $type) {
            $this->actingAs($user)->get(route('reports.show', $type))->assertOk()->assertSee('Excel');
        }

        $this->actingAs($user)->get(route('reports.csv', 'bisnis'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->actingAs($user)->get(route('reports.pdf', 'keuangan'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $excel = $this->actingAs($user)->get(route('reports.xlsx', 'operasional'));
        $excel->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $workbook = IOFactory::load($excel->baseResponse->getFile()->getPathname());
        $this->assertSame('Laporan Operasional', $workbook->getActiveSheet()->getCell('A1')->getValue());
        $this->assertSame('Ringkasan', $workbook->getActiveSheet()->getCell('A6')->getValue());
        $this->assertIsFloat($workbook->getActiveSheet()->getCell('B7')->getValue());
        $workbook->disconnectWorksheets();
    }

    public function test_provider_key_is_encrypted_hidden_and_adapter_is_format_based(): void
    {
        $provider = IntegrationProvider::create([
            'integration_type' => 'payment',
            'name' => 'Gateway Milik Pengguna',
            'api_format' => 'redirect',
            'api_key_encrypted' => 'sangat-rahasia',
            'is_active' => true,
        ]);

        $raw = DB::table('integration_providers')->where('id', $provider->id)->value('api_key_encrypted');
        $this->assertNotSame('sangat-rahasia', $raw);
        $this->assertArrayNotHasKey('api_key_encrypted', $provider->toArray());

        $adapter = new FormatPaymentAdapter($provider);
        $payload = '{"invoice":"INV-1"}';
        $signature = hash_hmac('sha256', $payload, 'secret');
        $this->assertTrue($adapter->verifyWebhookSignature($payload, $signature, 'secret'));
        $this->assertFalse($adapter->verifyWebhookSignature($payload, 'invalid', 'secret'));
    }

    public function test_openai_compatible_model_discovery_uses_operator_endpoint(): void
    {
        Http::fake(['https://operator.example/*' => Http::response(['data' => [['id' => 'model-a'], ['id' => 'model-b']]])]);
        $provider = IntegrationProvider::create([
            'integration_type' => 'ai', 'name' => 'Provider Operator', 'api_format' => 'openai_compatible',
            'base_url' => 'https://operator.example', 'api_key_encrypted' => 'secret', 'is_active' => true,
        ]);

        $this->assertSame(['model-a', 'model-b'], (new OpenAICompatibleAdapter($provider))->discoverModels());
        Http::assertSent(fn ($request) => $request->url() === 'https://operator.example/v1/models' && $request->hasHeader('Authorization', 'Bearer secret'));
    }

    public function test_large_sale_waits_for_approval_before_mutating_stock(): void
    {
        $this->seed(PlatformSeeder::class);
        $user = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Tenant Approval', $user);
        $branch = $tenant->branches()->first();
        $warehouse = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Gudang', 'code' => 'GDG']);
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Produk', 'sku' => 'SKU-APP']);
        $variant = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'Default', 'sku' => 'SKU-APP-V', 'purchase_price' => 50, 'sell_price' => 200]);
        app(StockService::class)->increase($tenant->id, $warehouse->id, $variant->id, 10, 50, 'opening', null);
        SystemSetting::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'key' => 'approval_threshold', 'value' => 100]);
        $this->actingAs($user);

        $invoice = app(SaleService::class)->checkout($tenant->id, $branch->id, $warehouse->id, null, [
            ['variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 200],
        ], [['method' => 'cash', 'amount' => 200]], 'approval-flow');

        $this->assertSame('pending_approval', $invoice->status);
        $this->assertSame(10.0, $this->stock($tenant->id, $warehouse->id, $variant->id));
        $approval = $invoice->tenant_id ? ApprovalRequest::withoutGlobalScopes()->where('subject_id', $invoice->id)->firstOrFail() : null;
        app(ApprovalService::class)->approve($approval, $user->id);
        $this->assertSame('final', $invoice->refresh()->status);
        $this->assertSame(9.0, $this->stock($tenant->id, $warehouse->id, $variant->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'transaction.approved', 'subject_id' => $invoice->id]);
    }

    private function stock(int $tenantId, int $warehouseId, int $variantId): float
    {
        return (float) DB::table('stock_movements')->where('tenant_id', $tenantId)->where('warehouse_id', $warehouseId)->where('product_variant_id', $variantId)
            ->selectRaw("SUM(CASE WHEN movement_type='in' THEN quantity ELSE -quantity END) as qty")->value('qty');
    }
}
