<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Membership;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\ZatcaDocument;
use App\Services\ModuleManager;
use App\Services\SaleService;
use App\Services\StockService;
use App\Services\TenantProvisioningService;
use App\Services\ZatcaService;
use App\Support\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * ZATCA module: TLV roundtrip, UBL XML, hashes, invoice generation,
 * credit/debit notes, reporting ledger, isolation, RBAC and API.
 */
class ZatcaTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $name = 'Toko ZATCA', bool $enableZatca = true): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $owner);
        $owner->refresh();
        if ($enableZatca) {
            app(ModuleManager::class)->enable($tenant->id, 'zatca');
        }
        TenantContext::setId($tenant->id);

        return [$tenant, $owner];
    }

    private function seller(): array
    {
        return ['name' => 'Toko Contoh', 'vat_number' => '312345678900003'];
    }

    private function salesInvoice($tenant, $owner): SalesInvoice
    {
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Gudang Z', 'code' => 'WH-'.uniqid()]);
        $p = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Barang', 'sku' => 'BRG', 'product_type' => 'stock', 'track_inventory' => true]);
        $v = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $p->id, 'name' => 'Default', 'sku' => 'BRG-V', 'purchase_price' => 50, 'sell_price' => 100]);
        app(StockService::class)->increase($tenant->id, $warehouse->id, $v->id, 10, 50, 'opening', null);

        return app(SaleService::class)->checkout($tenant->id, $branch->id, $warehouse->id, null, [
            ['variant_id' => $v->id, 'quantity' => 2, 'unit_price' => 100],
        ], [['method' => 'cash', 'amount' => 230]], 'zatca-sale-1', null, 30);
    }

    public function test_module_gates_workspace_and_api(): void
    {
        [$tenant, $owner] = $this->context('Toko ZATCA Gate', false);

        $this->actingAs($owner)->get('/zatca')->assertForbidden();
        $this->actingAs($owner)->getJson('/api/v1/zatca/documents', ['X-Tenant-ID' => $tenant->id])->assertForbidden();

        app(ModuleManager::class)->enable($tenant->id, 'zatca');
        $this->actingAs($owner)->get('/zatca')->assertOk()->assertSeeText('ZATCA');
    }

    public function test_tlv_roundtrip_and_qr_render(): void
    {
        $svc = app(ZatcaService::class);
        $tlv = $svc->buildTlv('Toko Contoh', '312345678900003', '2026-09-20T10:00:00Z', 230.00, 30.00);
        $fields = ZatcaService::decodeTlv($tlv);
        $this->assertSame([1, 2, 3, 4, 5], array_column($fields, 'tag'));
        $this->assertSame('Toko Contoh', $fields[0]['value']);
        $this->assertSame('312345678900003', $fields[1]['value']);
        $this->assertSame('230.00', $fields[3]['value']);
        $this->assertSame('30.00', $fields[4]['value']);

        $svg = $svc->qrSvg($tlv);
        $this->assertStringContainsString('<svg', $svg);

        try {
            ZatcaService::decodeTlv('!!!not-base64!!!');
            $this->fail('Corrupt TLV must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_generate_from_sale_builds_xml_hash_and_is_idempotent(): void
    {
        [$tenant, $owner] = $this->context();
        $invoice = $this->salesInvoice($tenant, $owner);
        $svc = app(ZatcaService::class);

        $doc = $svc->generateFromSalesInvoice($tenant->id, $invoice->id, $this->seller(), $owner->id);
        $this->assertSame('generated', $doc->status);
        $this->assertSame(230.0, (float) $doc->total);
        $this->assertSame(30.0, (float) $doc->vat_total);
        $this->assertSame(64, strlen($doc->hash));
        // XML is well-formed with the required nodes.
        $xml = simplexml_load_string($doc->xml);
        $this->assertNotFalse($xml);
        $xml->registerXPathNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $this->assertSame('388', (string) $xml->xpath('//cbc:InvoiceTypeCode')[0]);
        $this->assertSame('312345678900003', (string) $xml->xpath('//cbc:CompanyID')[0]);
        // TLV decodes back to the document figures.
        $fields = ZatcaService::decodeTlv($doc->qr_tlv);
        $this->assertSame('230.00', collect($fields)->firstWhere('tag', 4)['value']);

        // Regenerating the same invoice returns the same document.
        $again = $svc->generateFromSalesInvoice($tenant->id, $invoice->id, $this->seller(), $owner->id);
        $this->assertSame($doc->id, $again->id);
        $this->assertSame(1, ZatcaDocument::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());

        // Bad VAT rejected.
        try {
            $svc->generateFromSalesInvoice($tenant->id, $invoice->id, ['name' => 'X', 'vat_number' => '123'], $owner->id);
            $this->fail('Short VAT must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_credit_debit_notes_and_reporting(): void
    {
        [$tenant, $owner] = $this->context();
        $invoice = $this->salesInvoice($tenant, $owner);
        $svc = app(ZatcaService::class);
        $doc = $svc->generateFromSalesInvoice($tenant->id, $invoice->id, $this->seller(), $owner->id);

        $credit = $svc->issueNote($tenant->id, $doc->id, 'credit_note', 115, 15, $owner->id);
        $this->assertSame($doc->id, $credit->references_document_id);
        $xml = simplexml_load_string($credit->xml);
        $xml->registerXPathNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $this->assertSame('381', (string) $xml->xpath('//cbc:InvoiceTypeCode')[0]);

        $reported = $svc->markReported($doc, 'CLEAR-123', $owner->id);
        $this->assertSame('reported', $reported->status);
        $this->assertSame('CLEAR-123', $reported->clearance_id);
        try {
            $svc->markReported($reported, 'CLEAR-124', $owner->id);
            $this->fail('Double reporting must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'zatca.reported']);
    }

    public function test_tenant_isolation_and_rbac(): void
    {
        [$tenantA, $ownerA] = $this->context('Toko ZATCA A');
        [$tenantB, $ownerB] = $this->context('Toko ZATCA B');
        $invoiceA = $this->salesInvoice($tenantA, $ownerA);
        app(ZatcaService::class)->generateFromSalesInvoice($tenantA->id, $invoiceA->id, $this->seller(), null);

        TenantContext::setId($tenantB->id);
        $this->assertSame(0, ZatcaDocument::query()->count());
        $this->actingAs($ownerB)->get('/zatca')->assertOk()->assertDontSee('312345678900003');

        $member = User::factory()->create();
        Membership::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id, 'user_id' => $member->id,
            'branch_id' => Branch::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->value('id'),
            'is_active' => true,
        ]);
        $member->forceFill(['current_tenant_id' => $tenantB->id])->save();
        $this->actingAs($member)->get('/zatca')->assertForbidden();
        $member->givePermissionTo('zatca.view');
        $this->actingAs($member)->get('/zatca')->assertOk();
        $this->actingAs($member)->post('/zatca/generate', ['sales_invoice_id' => 1])->assertForbidden();
    }

    public function test_api_lifecycle(): void
    {
        [$tenant, $owner] = $this->context();
        $headers = ['X-Tenant-ID' => $tenant->id];
        $invoice = $this->salesInvoice($tenant, $owner);

        $created = $this->actingAs($owner)->postJson('/api/v1/zatca/documents', [
            'sales_invoice_id' => $invoice->id, 'seller_name' => 'Toko Contoh', 'seller_vat' => '312345678900003',
        ], $headers)->assertCreated();
        $id = $created->json('data.id');

        $this->actingAs($owner)->getJson("/api/v1/zatca/documents/{$id}", $headers)->assertOk()
            ->assertJsonPath('data.hash', $created->json('data.hash'))
            ->assertJsonStructure(['data' => ['qr_svg']]);
        $this->actingAs($owner)->postJson("/api/v1/zatca/documents/{$id}/report", ['clearance_id' => 'API-1'], $headers)->assertOk()->assertJsonPath('data.status', 'reported');
    }

    public function test_workspace_flow(): void
    {
        [$tenant, $owner] = $this->context();
        $invoice = $this->salesInvoice($tenant, $owner);

        $this->actingAs($owner)->post('/zatca/generate', [
            'sales_invoice_id' => $invoice->id, 'seller_name' => 'Toko Contoh', 'seller_vat' => '312345678900003',
        ])->assertRedirect();
        $doc = ZatcaDocument::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->actingAs($owner)->post('/zatca/notes', [
            'references_document_id' => $doc->id, 'type' => 'credit_note', 'total' => 115, 'vat_total' => 15,
        ])->assertRedirect();
        $this->actingAs($owner)->post("/zatca/documents/{$doc->id}/report", ['clearance_id' => 'WEB-1'])->assertRedirect();
        $this->assertSame('reported', $doc->refresh()->status);
        $this->actingAs($owner)->get('/zatca')->assertOk()->assertSeeText('WEB-1');
    }
}
