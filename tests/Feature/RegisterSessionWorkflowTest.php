<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashSession;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Register;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\RegisterSessionService;
use App\Services\SaleService;
use App\Services\StockService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RegisterSessionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_session_tracks_cash_movements_cash_sales_and_audited_closure_once(): void
    {
        [$owner, $tenant, $branch, $warehouse, $register, $variant] = $this->context('Register Flow');
        $this->actingAs($owner);
        $service = app(RegisterSessionService::class);

        $session = $service->open($tenant->id, $register->id, $owner->id, 100000);
        $service->movement($session, $owner->id, 'cash_in', 10000, 'Float tambahan');
        $service->movement($session, $owner->id, 'cash_out', 5000, 'Beli kebutuhan kasir');
        app(SaleService::class)->checkout($tenant->id, $branch->id, $warehouse->id, null, [
            ['variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 25000],
        ], [['method' => 'cash', 'amount' => 25000]], 'register-sale', $session->id);

        $closed = $service->close($session, $owner->id, 130000, [100000 => 1, 20000 => 1, 10000 => 1], 'Cocok');
        $this->assertSame('closed', $closed->status);
        $this->assertSame('130000.00', $closed->expected_amount);
        $this->assertSame('0.00', $closed->variance_amount);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'register.session.closed']);
        $this->expectException(HttpException::class);
        $service->close($closed, $owner->id, 130000);
    }

    public function test_register_close_accepts_blank_optional_denomination_fields(): void
    {
        [$owner, $tenant, , , $register] = $this->context('Register Close Blank');
        $this->actingAs($owner);
        $service = app(RegisterSessionService::class);

        $session = $service->open($tenant->id, $register->id, $owner->id, 50000);

        // The workspace UI always submits every denomination input; blanks
        // arrive as null and must be accepted (breakdown is optional), while a
        // provided breakdown must still equal the actual cash.
        $this->post(route('registers.close', $session), [
            'actual_amount' => 50000,
            'denominations' => [100000 => null, 50000 => null, 20000 => ''],
        ])->assertRedirect();

        $this->assertSame('closed', $session->refresh()->status);
        $this->assertSame('0.00', $session->refresh()->variance_amount);
    }

    public function test_session_enforces_single_open_per_register_owner_closure_and_tenant_boundary(): void
    {
        [$owner, $tenant, , , $register] = $this->context('Register A');
        [$otherOwner, $otherTenant, , , $otherRegister] = $this->context('Register B');
        $this->actingAs($owner);
        $service = app(RegisterSessionService::class);
        $session = $service->open($tenant->id, $register->id, $owner->id, 0);

        try {
            $service->open($tenant->id, $register->id, $owner->id, 0);
            $this->fail('A register must not have two open sessions.');
        } catch (HttpException) {
            $this->assertTrue(true);
        }
        try {
            $service->close($session, $otherOwner->id, 0);
            $this->fail('A different cashier cannot close another cashier session.');
        } catch (HttpException) {
            $this->assertTrue(true);
        }

        $this->actingAs($owner)->post(route('registers.open', $otherRegister), ['opening_amount' => 0])->assertNotFound();
        $this->actingAs($owner)->post(route('registers.close', CashSession::withoutGlobalScopes()->create([
            'tenant_id' => $otherTenant->id, 'register_id' => $otherRegister->id, 'opened_by' => $otherOwner->id, 'opening_amount' => 0, 'status' => 'open',
        ])), ['actual_amount' => 0])->assertNotFound();
    }

    public function test_register_workspace_requires_register_permissions_and_pos_requires_open_owned_session(): void
    {
        [$owner, $tenant, $branch, $warehouse, $register, $variant] = $this->context('Register Permissions');
        $owner->revokePermissionTo(['register.manage', 'register.open', 'register.close']);
        $this->actingAs($owner)->get(route('registers.index'))->assertForbidden();
        $owner->givePermissionTo(['register.open', 'register.close']);
        $this->actingAs($owner)->post(route('registers.open', $register), ['opening_amount' => 10])->assertRedirect();
        $session = CashSession::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        $foreign = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $foreign->memberships()->create(['tenant_id' => $tenant->id, 'role' => 'cashier', 'status' => 'active']);
        $foreign->givePermissionTo('pos.sale.create');
        $this->actingAs($foreign);
        $this->expectException(HttpException::class);
        app(SaleService::class)->checkout($tenant->id, $branch->id, $warehouse->id, null, [['variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 100]], [['method' => 'cash', 'amount' => 100]], 'foreign-register', $session->id);
    }

    private function context(string $name): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $owner);
        $owner->refresh();
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Gudang '.$name, 'code' => 'WH-'.uniqid()]);
        $register = Register::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Kasir '.$name]);
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Produk '.$name, 'sku' => 'P-'.uniqid(), 'product_type' => 'stock', 'track_inventory' => true]);
        $variant = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'Default', 'sku' => 'V-'.uniqid(), 'purchase_price' => 50, 'sell_price' => 100]);
        app(StockService::class)->increase($tenant->id, $warehouse->id, $variant->id, 5, 50, 'opening', null);

        return [$owner, $tenant, $branch, $warehouse, $register, $variant];
    }
}
