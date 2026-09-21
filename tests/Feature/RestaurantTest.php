<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\KitchenTicket;
use App\Models\Membership;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\RestaurantService;
use App\Services\SaleService;
use App\Services\StockService;
use App\Services\TenantProvisioningService;
use App\Support\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Restaurant is OPTIONAL and tenant-aware (default OFF, retail default).
 * Flag-off hides menu and blocks web/API at the middleware; flag-on runs
 * the full dine-in flow; tenants never leak the flag to each other.
 */
class RestaurantTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $name = 'Toko Resto'): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $owner);
        $owner->refresh();
        TenantContext::setId($tenant->id);

        return [$tenant, $owner];
    }

    /** @return array{Warehouse, variant} */
    private function stocked(string $sku, $tenant, int $qty = 20): array
    {
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Gudang '.$sku, 'code' => 'WH-'.uniqid()]);
        $p = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => $sku, 'sku' => $sku, 'product_type' => 'stock', 'track_inventory' => true]);
        $variant = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $p->id, 'name' => 'Default', 'sku' => $sku.'-V', 'purchase_price' => 10000, 'sell_price' => 25000]);
        app(StockService::class)->increase($tenant->id, $warehouse->id, $variant->id, $qty, 10000, 'opening', null);

        return [$warehouse, $variant, $p];
    }

    public function test_flag_defaults_off_and_blocks_everything(): void
    {
        [$tenant, $owner] = $this->context();
        $this->assertFalse(app(RestaurantService::class)->isEnabled($tenant->id));

        // Menu hidden, routes and API refused — permission alone grants nothing.
        $this->actingAs($owner)->get('/dashboard')->assertOk()->assertDontSee('nav-restaurant');
        $this->actingAs($owner)->get('/restaurant')->assertForbidden();
        $this->actingAs($owner)->get('/restaurant/kitchen')->assertForbidden();
        $this->actingAs($owner)->getJson('/api/v1/restaurant/tickets', ['X-Tenant-ID' => $tenant->id])->assertForbidden();
        $this->actingAs($owner)->postJson('/api/v1/restaurant/tickets/fire', [], ['X-Tenant-ID' => $tenant->id])->assertForbidden();
    }

    public function test_owner_enables_via_settings_and_menu_appears(): void
    {
        [$tenant, $owner] = $this->context();

        $this->actingAs($owner)->get('/settings/business')->assertOk()->assertSeeText('Fitur Restaurant');
        $this->actingAs($owner)->post('/settings/business', ['restaurant_enabled' => 1])->assertRedirect();
        $this->assertTrue(app(RestaurantService::class)->isEnabled($tenant->id));

        $this->actingAs($owner)->get('/dashboard')->assertOk()->assertSee('nav-restaurant');
        $this->actingAs($owner)->get('/restaurant')->assertOk();
        $this->actingAs($owner)->get('/restaurant/kitchen')->assertOk();

        // Switching back off hides everything again without reinstall.
        $this->actingAs($owner)->post('/settings/business', ['restaurant_enabled' => 0])->assertRedirect();
        $this->actingAs($owner)->get('/dashboard')->assertOk()->assertDontSee('nav-restaurant');
        $this->actingAs($owner)->get('/restaurant')->assertForbidden();
    }

    public function test_full_dine_in_flow_with_modifiers(): void
    {
        [$tenant, $owner] = $this->context();
        app(RestaurantService::class)->setEnabled($tenant->id, true, $owner->id);
        [$warehouse, $variant, $product] = $this->stocked('NASI', $tenant);
        $svc = app(RestaurantService::class);
        $this->actingAs($owner);

        $floor = $svc->createFloor($tenant->id, 'Lt 1', $owner->id);
        $table = $svc->createTable($tenant->id, ['code' => 'A1', 'floor_id' => $floor->id, 'seats' => 4], $owner->id);
        $booking = $svc->bookTable($tenant->id, [
            'table_id' => $table->id, 'customer_name' => 'Tamu', 'starts_at' => now()->addHour()->toDateTimeString(),
        ], $owner->id);
        $svc->transitionBooking($booking, 'seated', $owner->id);

        $group = $svc->createModifierGroup($tenant->id, ['name' => 'Level Pedas', 'min_select' => 1, 'max_select' => 1], $owner->id);
        $opt = $svc->createModifier($tenant->id, $group->id, ['name' => 'Extra pedas', 'price_delta' => 5000], $owner->id);
        $svc->linkProduct($tenant->id, $product->id, $group->id, $owner->id);

        $ticket = $svc->fireOrder($tenant->id, [
            'order_type' => 'dine_in', 'table_id' => $table->id,
            'items' => [['variant_id' => $variant->id, 'quantity' => 2, 'modifiers' => [['group_id' => $group->id, 'modifier_id' => $opt->id]]]],
        ], $owner->id);
        // 25000 + 5000 modifier = 30000/unit × 2.
        $this->assertSame(60000.0, (float) $ticket->total);
        $this->assertSame('occupied', $table->refresh()->status);

        $svc->advanceTicket($ticket, 'preparing', $owner->id);
        $svc->advanceTicket($ticket->refresh(), 'ready', $owner->id);
        $closed = $svc->closeTicket($ticket->refresh(), [['method' => 'cash', 'amount' => 60000]], $owner->id);
        $this->assertSame('served', $closed->status);
        $this->assertNotNull($closed->sales_invoice_id);
        // Stock deducted once at close; table freed.
        $this->assertSame(18.0, app(StockService::class)->onHand($tenant->id, $warehouse->id, $variant->id));
        $this->assertSame('available', $table->refresh()->status);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'restaurant.ticket.closed']);
    }

    public function test_modifier_guards_and_booking_conflicts(): void
    {
        [$tenant, $owner] = $this->context();
        app(RestaurantService::class)->setEnabled($tenant->id, true, $owner->id);
        [$warehouse, $variant, $product] = $this->stocked('MIE', $tenant);
        $svc = app(RestaurantService::class);
        $table = $svc->createTable($tenant->id, ['code' => 'B1'], $owner->id);
        $slot = now()->addHours(2)->toDateTimeString();

        $svc->bookTable($tenant->id, ['table_id' => $table->id, 'customer_name' => 'A', 'starts_at' => $slot], $owner->id);
        try {
            $svc->bookTable($tenant->id, ['table_id' => $table->id, 'customer_name' => 'B', 'starts_at' => date('Y-m-d H:i:s', strtotime($slot.' +30 minutes'))], $owner->id);
            $this->fail('Overlapping booking must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // Unlinked modifier group rejected.
        $group = $svc->createModifierGroup($tenant->id, ['name' => 'Topping'], $owner->id);
        $opt = $svc->createModifier($tenant->id, $group->id, ['name' => 'Keju', 'price_delta' => 3000], $owner->id);
        try {
            $svc->fireOrder($tenant->id, [
                'order_type' => 'takeaway',
                'items' => [['variant_id' => $variant->id, 'quantity' => 1, 'modifiers' => [['group_id' => $group->id, 'modifier_id' => $opt->id]]]],
            ], $owner->id);
            $this->fail('Unlinked modifier group must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // min_select enforced.
        $svc->linkProduct($tenant->id, $product->id, $group->id, $owner->id);
        $group->update(['min_select' => 1]);
        try {
            $svc->fireOrder($tenant->id, [
                'order_type' => 'takeaway',
                'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
            ], $owner->id);
            $this->fail('Missing required modifier must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_takeaway_needs_no_table_and_cancel_only_when_queued(): void
    {
        [$tenant, $owner] = $this->context();
        app(RestaurantService::class)->setEnabled($tenant->id, true, $owner->id);
        [, $variant] = $this->stocked('TAKE', $tenant);
        $svc = app(RestaurantService::class);

        $ticket = $svc->fireOrder($tenant->id, [
            'order_type' => 'takeaway', 'customer_name' => 'Ojol',
            'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
        ], $owner->id);
        $this->assertNull($ticket->table_id);
        $svc->advanceTicket($ticket, 'preparing', $owner->id);
        try {
            $svc->advanceTicket($ticket->refresh(), 'cancelled', $owner->id);
            $this->fail('Cancelling a preparing ticket must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_flag_is_per_tenant(): void
    {
        [$tenantA, $ownerA] = $this->context('Toko Resto A');
        [$tenantB, $ownerB] = $this->context('Toko Resto B');
        app(RestaurantService::class)->setEnabled($tenantA->id, true, $ownerA->id);

        // B stays retail: menu hidden, routes refused.
        TenantContext::setId($tenantB->id);
        $this->actingAs($ownerB)->get('/dashboard')->assertOk()->assertDontSee('Restaurant');
        $this->actingAs($ownerB)->get('/restaurant')->assertForbidden();
        $this->actingAs($ownerB)->getJson('/api/v1/restaurant/tickets', ['X-Tenant-ID' => $tenantB->id])->assertForbidden();
        // A unaffected.
        TenantContext::setId($tenantA->id);
        $this->actingAs($ownerA)->get('/restaurant')->assertOk();
    }

    public function test_rbac_without_flag_and_without_permission(): void
    {
        [$tenant, $owner] = $this->context();
        app(RestaurantService::class)->setEnabled($tenant->id, true, $owner->id);
        $member = User::factory()->create();
        Membership::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'user_id' => $member->id,
            'branch_id' => Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('id'),
            'is_active' => true,
        ]);
        $member->forceFill(['current_tenant_id' => $tenant->id])->save();
        TenantContext::setId($tenant->id);

        // Flag on, but no permission: still denied everywhere.
        $this->actingAs($member)->get('/restaurant')->assertForbidden();
        $this->actingAs($member)->getJson('/api/v1/restaurant/tickets', ['X-Tenant-ID' => $tenant->id])->assertForbidden();
        $member->givePermissionTo('restaurant.view');
        $this->actingAs($member)->get('/restaurant')->assertOk();
        $this->actingAs($member)->post('/restaurant/tables', ['code' => 'X'])->assertForbidden();
    }

    public function test_api_lifecycle_and_refire(): void
    {
        [$tenant, $owner] = $this->context();
        app(RestaurantService::class)->setEnabled($tenant->id, true, $owner->id);
        [, $variant] = $this->stocked('API', $tenant);
        $headers = ['X-Tenant-ID' => $tenant->id];

        $fired = $this->actingAs($owner)->postJson('/api/v1/restaurant/tickets/fire', [
            'order_type' => 'delivery', 'customer_name' => 'Rumah',
            'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
        ], $headers)->assertCreated();
        $id = $fired->json('data.id');

        $this->actingAs($owner)->postJson("/api/v1/restaurant/tickets/{$id}/advance", ['to' => 'preparing'], $headers)->assertOk();
        $itemId = KitchenTicket::withoutGlobalScopes()->find($id)->items()->value('id');
        $this->actingAs($owner)->postJson("/api/v1/restaurant/items/{$itemId}/refire", ['reason' => 'Hangus'], $headers)->assertOk()->assertJsonPath('data.refired', true);
        $this->actingAs($owner)->postJson("/api/v1/restaurant/tickets/{$id}/advance", ['to' => 'ready'], $headers)->assertOk();
        $closed = $this->actingAs($owner)->postJson("/api/v1/restaurant/tickets/{$id}/close", [
            'payments' => [['method' => 'cash', 'amount' => 25000]],
        ], $headers)->assertOk();
        $this->assertNotEmpty($closed->json('invoice_no'));
    }

    public function test_retail_untouched_with_flag_off(): void
    {
        [$tenant, $owner] = $this->context();
        [$warehouse, $variant] = $this->stocked('RTL', $tenant);
        $this->actingAs($owner);
        // Plain retail checkout works with the flag OFF and leaves no restaurant trace.
        $invoice = app(SaleService::class)->checkout($tenant->id,
            Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('id'),
            $warehouse->id, null,
            [['variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 25000]],
            [['method' => 'cash', 'amount' => 25000]], 'retail-untouched-1');
        $this->assertSame('paid', $invoice->payment_status);
        $this->assertSame(0, KitchenTicket::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertFalse(app(RestaurantService::class)->isEnabled($tenant->id));
    }

    public function test_workspace_flow(): void
    {
        [$tenant, $owner] = $this->context();
        $this->actingAs($owner)->post('/settings/business', ['restaurant_enabled' => 1])->assertRedirect();

        $this->actingAs($owner)->post('/restaurant/floors', ['name' => 'Lt 1'])->assertRedirect();
        $this->actingAs($owner)->post('/restaurant/tables', ['code' => 'W1'])->assertRedirect();
        $table = RestaurantTable::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->actingAs($owner)->post('/restaurant/bookings', [
            'table_id' => $table->id, 'customer_name' => 'Web Tamu',
            'starts_at' => now()->addHours(3)->format('Y-m-d H:i'),
        ])->assertRedirect();
        $this->actingAs($owner)->get('/restaurant')->assertOk()->assertSeeText('W1');
        $this->actingAs($owner)->get('/restaurant/kitchen')->assertOk()->assertSeeText('Dapur kosong');
    }
}
