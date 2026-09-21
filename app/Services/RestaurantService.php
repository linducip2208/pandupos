<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\KitchenItem;
use App\Models\KitchenTicket;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RestaurantBooking;
use App\Models\RestaurantFloor;
use App\Models\RestaurantTable;
use App\Models\TenantSetting;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Restaurant (optional business feature): tables, bookings, modifiers,
 * kitchen tickets and cashier close-out. Gated by the tenant-aware
 * `features.restaurant` setting (default OFF); retail flows never call here.
 * Closing posts a normal sale through SaleService, so stock, money and
 * audit behave exactly like retail.
 */
final class RestaurantService
{
    public const SETTING_KEY = 'features.restaurant';

    public const ORDER_TYPES = ['dine_in', 'takeaway', 'delivery'];

    public function __construct(private AuditService $audit) {}

    public function isEnabled(?int $tenantId): bool
    {
        if (! $tenantId) {
            return false;
        }

        return TenantSetting::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('key', self::SETTING_KEY)->value('value') === '1';
    }

    public function setEnabled(int $tenantId, bool $on, ?int $actorId = null): void
    {
        TenantSetting::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenantId, 'key' => self::SETTING_KEY],
            ['value' => $on ? '1' : '0']
        );
        $this->audit->log($tenantId, $actorId, $on ? 'restaurant.enabled' : 'restaurant.disabled', TenantSetting::class, null, null, ['key' => self::SETTING_KEY]);
    }

    public function createFloor(int $tenantId, string $name, ?int $actorId = null): RestaurantFloor
    {
        $name = trim($name);
        abort_if($name === '', 422, 'Floor name is required.');
        abort_if(RestaurantFloor::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('name', $name)->exists(), 422, 'Floor already exists.');
        $max = (int) RestaurantFloor::withoutGlobalScopes()->where('tenant_id', $tenantId)->max('sort_order');

        return RestaurantFloor::withoutGlobalScopes()->create(['tenant_id' => $tenantId, 'name' => $name, 'sort_order' => $max + 1]);
    }

    public function createTable(int $tenantId, array $data, ?int $actorId = null): RestaurantTable
    {
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        abort_if($code === '', 422, 'Table code is required.');
        abort_if(RestaurantTable::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('code', $code)->exists(), 422, 'Table code already exists.');
        $floorId = isset($data['floor_id']) ? (int) $data['floor_id'] : null;
        if ($floorId) {
            abort_unless(RestaurantFloor::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($floorId)->exists(), 422, 'Floor does not belong to tenant.');
        }
        $seats = (int) ($data['seats'] ?? 2);
        abort_if($seats <= 0, 422, 'Seats must be positive.');

        $table = RestaurantTable::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'floor_id' => $floorId, 'code' => $code,
            'name' => $data['name'] ?? $code, 'seats' => $seats, 'status' => RestaurantTable::AVAILABLE,
        ]);
        $this->audit->log($tenantId, $actorId, 'restaurant.table.created', RestaurantTable::class, $table->id, null, ['code' => $code]);

        return $table;
    }

    public function bookTable(int $tenantId, array $data, ?int $actorId = null): RestaurantBooking
    {
        $table = RestaurantTable::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail((int) ($data['table_id'] ?? 0));
        $name = trim((string) ($data['customer_name'] ?? ''));
        abort_if($name === '', 422, 'Customer name is required.');
        abort_if(empty($data['starts_at']), 422, 'Start time is required.');
        $start = strtotime($data['starts_at']);
        abort_if($start < time() - 60, 422, 'Bookings cannot start in the past.');
        // 2-hour visit window conflict guard against live bookings.
        $conflict = RestaurantBooking::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('table_id', $table->id)
            ->whereIn('status', [RestaurantBooking::BOOKED, RestaurantBooking::SEATED])
            ->where('starts_at', '<', date('Y-m-d H:i:s', $start + 7200))
            ->where('starts_at', '>', date('Y-m-d H:i:s', $start - 7200))->exists();
        abort_if($conflict, 422, 'Table already has a booking overlapping this slot.');

        $booking = RestaurantBooking::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'table_id' => $table->id, 'customer_name' => $name,
            'phone' => $data['phone'] ?? null, 'starts_at' => date('Y-m-d H:i:s', $start),
            'party_size' => max(1, (int) ($data['party_size'] ?? 1)),
            'status' => RestaurantBooking::BOOKED, 'notes' => $data['notes'] ?? null,
        ]);
        $this->audit->log($tenantId, $actorId, 'restaurant.booking.created', RestaurantBooking::class, $booking->id, null, ['table' => $table->code]);

        return $booking;
    }

    public function transitionBooking(RestaurantBooking $booking, string $to, ?int $actorId = null): RestaurantBooking
    {
        $allowed = [
            RestaurantBooking::BOOKED => [RestaurantBooking::SEATED, RestaurantBooking::CANCELLED, RestaurantBooking::NO_SHOW],
            RestaurantBooking::SEATED => [],
            RestaurantBooking::CANCELLED => [],
            RestaurantBooking::NO_SHOW => [],
        ];
        abort_unless(in_array($to, [RestaurantBooking::BOOKED, RestaurantBooking::SEATED, RestaurantBooking::CANCELLED, RestaurantBooking::NO_SHOW], true), 422, 'Invalid booking status.');
        abort_unless(in_array($to, $allowed[$booking->status] ?? [], true), 422, "Booking cannot move from [{$booking->status}] to [{$to}].");
        $before = $booking->toArray();
        $booking->update(['status' => $to]);
        if ($to === RestaurantBooking::SEATED) {
            $booking->table()->update(['status' => RestaurantTable::OCCUPIED]);
        }
        $this->audit->log($booking->tenant_id, $actorId, 'restaurant.booking.transitioned', RestaurantBooking::class, $booking->id, $before, $booking->fresh()->toArray());

        return $booking->fresh();
    }

    public function createModifierGroup(int $tenantId, array $data, ?int $actorId = null): ModifierGroup
    {
        $name = trim((string) ($data['name'] ?? ''));
        abort_if($name === '', 422, 'Group name is required.');
        abort_if(ModifierGroup::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('name', $name)->exists(), 422, 'Group already exists.');
        $min = max(0, (int) ($data['min_select'] ?? 0));
        $max = max(1, (int) ($data['max_select'] ?? 1));
        abort_if($min > $max, 422, 'min_select cannot exceed max_select.');

        return ModifierGroup::withoutGlobalScopes()->create(['tenant_id' => $tenantId, 'name' => $name, 'min_select' => $min, 'max_select' => $max]);
    }

    public function createModifier(int $tenantId, int $groupId, array $data, ?int $actorId = null): Modifier
    {
        $group = ModifierGroup::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($groupId);
        $name = trim((string) ($data['name'] ?? ''));
        abort_if($name === '', 422, 'Modifier name is required.');

        return Modifier::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'group_id' => $group->id, 'name' => $name,
            'price_delta' => round((float) ($data['price_delta'] ?? 0), 2), 'is_active' => true,
        ]);
    }

    public function linkProduct(int $tenantId, int $productId, int $groupId, ?int $actorId = null): void
    {
        Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($productId);
        ModifierGroup::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($groupId);
        DB::table('product_modifier_group')->updateOrInsert(
            ['tenant_id' => $tenantId, 'product_id' => $productId, 'group_id' => $groupId],
            ['created_at' => now(), 'updated_at' => now()]
        );
    }

    /**
     * @param  array<int, array{variant_id:int,quantity:float,modifiers?:array<int, array{group_id:int,modifier_id:int}>}>  $items
     */
    public function fireOrder(int $tenantId, array $data, ?int $actorId = null): KitchenTicket
    {
        return DB::transaction(function () use ($tenantId, $data, $actorId) {
            $type = $data['order_type'] ?? null;
            abort_unless(in_array($type, self::ORDER_TYPES, true), 422, 'Invalid order type.');
            $table = null;
            if ($type === 'dine_in') {
                // Tables coordinate via bookings; firing accepts an available
                // or already-occupied table (additive orders) and marks it
                // occupied. It is freed when no active ticket remains.
                $table = RestaurantTable::withoutGlobalScopes()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail((int) ($data['table_id'] ?? 0));
            }
            $items = $data['items'] ?? [];
            abort_if(! is_array($items) || $items === [], 422, 'Order needs at least one item.');
            $resolved = [];
            $total = 0.0;
            foreach ($items as $row) {
                $resolved[] = $this->resolveLine($tenantId, $row);
                $last = end($resolved);
                $total = round($total + $last['quantity'] * $last['unit_price'], 2);
            }
            $ticket = KitchenTicket::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId, 'number' => $this->nextNumber(),
                'table_id' => $table?->id, 'order_type' => $type,
                'customer_name' => $data['customer_name'] ?? null,
                'status' => KitchenTicket::QUEUED, 'total' => $total,
                'accepted_at' => now(), 'created_by' => $actorId, 'notes' => $data['notes'] ?? null,
            ]);
            foreach ($resolved as $line) {
                $ticket->items()->create([
                    'tenant_id' => $tenantId, 'product_variant_id' => $line['variant_id'],
                    'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'],
                    'modifiers' => $line['modifiers'], 'status' => KitchenTicket::QUEUED,
                ]);
            }
            $table?->update(['status' => RestaurantTable::OCCUPIED]);
            $this->audit->log($tenantId, $actorId, 'restaurant.ticket.fired', KitchenTicket::class, $ticket->id, null, ['number' => $ticket->number, 'total' => $total]);

            return $ticket->load('items');
        });
    }

    public function advanceTicket(KitchenTicket $ticket, string $to, ?int $actorId = null): KitchenTicket
    {
        $allowed = [
            KitchenTicket::QUEUED => [KitchenTicket::PREPARING, KitchenTicket::CANCELLED],
            KitchenTicket::PREPARING => [KitchenTicket::READY],
            KitchenTicket::READY => [KitchenTicket::SERVED],
            KitchenTicket::SERVED => [],
            KitchenTicket::CANCELLED => [],
        ];
        abort_unless(in_array($to, [KitchenTicket::QUEUED, KitchenTicket::PREPARING, KitchenTicket::READY, KitchenTicket::SERVED, KitchenTicket::CANCELLED], true), 422, 'Invalid ticket status.');
        abort_unless(in_array($to, $allowed[$ticket->status] ?? [], true), 422, "Ticket cannot move from [{$ticket->status}] to [{$to}].");
        $before = $ticket->toArray();
        $ticket->update(['status' => $to]);
        if (in_array($to, [KitchenTicket::PREPARING, KitchenTicket::READY, KitchenTicket::SERVED], true)) {
            $ticket->items()->update(['status' => $to]);
        }
        if (in_array($to, [KitchenTicket::SERVED, KitchenTicket::CANCELLED], true)) {
            $this->freeTableIfIdle($ticket->refresh());
        }
        $this->audit->log($ticket->tenant_id, $actorId, 'restaurant.ticket.advanced', KitchenTicket::class, $ticket->id, $before, $ticket->fresh()->toArray());

        return $ticket->fresh('items');
    }

    public function refireItem(KitchenItem $item, string $reason, ?int $actorId = null): KitchenItem
    {
        abort_if(trim($reason) === '', 422, 'Refire reason is required.');
        $item = KitchenItem::withoutGlobalScopes()->where('tenant_id', $item->tenant_id)->findOrFail($item->id);
        abort_unless(in_array($item->ticket->status, [KitchenTicket::PREPARING, KitchenTicket::READY], true), 422, 'Items can only be re-fired while the ticket is being prepared or ready.');
        $before = $item->toArray();
        $item->update(['refired' => true, 'refire_reason' => $reason, 'status' => KitchenTicket::PREPARING]);
        $this->audit->log($item->tenant_id, $actorId, 'restaurant.item.refired', KitchenItem::class, $item->id, $before, $item->fresh()->toArray());

        return $item->fresh();
    }

    /**
     * Cashier close-out: re-validates stock, posts a normal retail sale with
     * full payment, links the invoice, serves the ticket and frees the table.
     *
     * @param  array<int, array{method:string,amount:float}>  $payments
     */
    public function closeTicket(KitchenTicket $ticket, array $payments, ?int $actorId = null): KitchenTicket
    {
        return DB::transaction(function () use ($ticket, $payments, $actorId) {
            $locked = KitchenTicket::withoutGlobalScopes()->with('items')->lockForUpdate()->findOrFail($ticket->id);
            abort_unless(in_array($locked->status, [KitchenTicket::READY, KitchenTicket::PREPARING], true), 422, 'Only prepared or ready tickets can close.');
            abort_if($locked->sales_invoice_id !== null, 422, 'Ticket is already closed.');
            $branchId = Branch::withoutGlobalScopes()->where('tenant_id', $locked->tenant_id)->orderBy('id')->value('id');
            $warehouseId = Warehouse::withoutGlobalScopes()->where('tenant_id', $locked->tenant_id)->orderBy('id')->value('id');
            abort_unless($branchId && $warehouseId, 422, 'Tenant needs a branch and warehouse to close sales.');
            $lines = [];
            foreach ($locked->items as $item) {
                $lines[] = ['variant_id' => $item->product_variant_id, 'quantity' => (float) $item->quantity, 'unit_price' => (float) $item->unit_price, 'discount' => 0];
            }
            $invoice = app(SaleService::class)->checkout(
                $locked->tenant_id, $branchId, $warehouseId, null, $lines, $payments,
                'restaurant-'.$locked->id.'-'.Str::random(6), null, 0
            );
            $before = $locked->toArray();
            $locked->update(['status' => KitchenTicket::SERVED, 'sales_invoice_id' => $invoice->id]);
            $locked->items()->update(['status' => KitchenTicket::SERVED]);
            $this->freeTableIfIdle($locked->refresh());
            $this->audit->log($locked->tenant_id, $actorId, 'restaurant.ticket.closed', KitchenTicket::class, $locked->id, $before, $locked->fresh()->toArray());

            return $locked->fresh('items');
        });
    }

    /** @return array{variant_id:int,quantity:float,unit_price:float,modifiers:array} */
    private function resolveLine(int $tenantId, array $row): array
    {
        $variant = ProductVariant::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail((int) ($row['variant_id'] ?? 0));
        $product = Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($variant->product_id);
        abort_unless($product && $product->is_active, 422, 'Variant product is not sellable.');
        $qty = round((float) ($row['quantity'] ?? 0), 3);
        abort_if($qty <= 0, 422, 'Item quantity must be positive.');
        $price = round((float) $variant->sell_price, 2);
        $chosen = [];
        foreach ($row['modifiers'] ?? [] as $pick) {
            $group = ModifierGroup::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail((int) ($pick['group_id'] ?? 0));
            abort_unless(DB::table('product_modifier_group')->where('tenant_id', $tenantId)->where('product_id', $variant->product_id)->where('group_id', $group->id)->exists(), 422, "Modifier group [{$group->name}] does not apply to this product.");
            $option = Modifier::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('group_id', $group->id)->where('is_active', true)->findOrFail((int) ($pick['modifier_id'] ?? 0));
            $chosen[] = ['group_id' => $group->id, 'group' => $group->name, 'option' => $option->name, 'delta' => (float) $option->price_delta];
            $price = round($price + (float) $option->price_delta, 2);
        }
        // Enforce per-group min/max across ALL groups linked to the product
        // (absent groups count as zero picks, so required groups cannot be skipped).
        $linkedGroupIds = DB::table('product_modifier_group')->where('tenant_id', $tenantId)->where('product_id', $variant->product_id)->pluck('group_id')->all();
        $chosenByGroup = [];
        foreach ($chosen as $c) {
            $chosenByGroup[$c['group_id']] = ($chosenByGroup[$c['group_id']] ?? 0) + 1;
        }
        foreach ($linkedGroupIds as $groupId) {
            $group = ModifierGroup::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($groupId);
            $count = $chosenByGroup[$groupId] ?? 0;
            abort_if($count < $group->min_select || $count > $group->max_select, 422, "Modifier group [{$group->name}] allows {$group->min_select}–{$group->max_select} picks.");
        }

        return ['variant_id' => $variant->id, 'quantity' => $qty, 'unit_price' => $price, 'modifiers' => $chosen];
    }

    private function freeTableIfIdle(KitchenTicket $ticket): void
    {
        if (! $ticket->table_id) {
            return;
        }
        $busy = KitchenTicket::withoutGlobalScopes()->where('tenant_id', $ticket->tenant_id)->where('table_id', $ticket->table_id)
            ->whereIn('status', [KitchenTicket::QUEUED, KitchenTicket::PREPARING, KitchenTicket::READY])->exists();
        if (! $busy) {
            RestaurantTable::withoutGlobalScopes()->whereKey($ticket->table_id)->update(['status' => RestaurantTable::AVAILABLE]);
        }
    }

    private function nextNumber(): string
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $no = 'RS-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));
            if (! KitchenTicket::withoutGlobalScopes()->where('number', $no)->exists()) {
                return $no;
            }
        }

        return 'RS-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8));
    }
}
