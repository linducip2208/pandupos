<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\EcommerceCart;
use App\Models\EcommerceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ecommerce: published catalog, email-keyed carts, checkout with stock
 * validation, payment with single stock deduction, shipment lifecycle.
 * Guest checkout is throttled at the route layer.
 */
final class EcommerceService
{
    public const SHIPPING_FEES = ['regular' => 10000, 'express' => 25000, 'sameday' => 50000];

    public function __construct(private StockService $stock, private AuditService $audit) {}

    public function publishProduct(int $tenantId, int $productId, bool $online, ?int $actorId = null): Product
    {
        $product = Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($productId);
        $before = $product->toArray();
        $product->update(['is_online' => $online]);
        $this->audit->log($tenantId, $actorId, $online ? 'ecommerce.product.published' : 'ecommerce.product.unpublished', Product::class, $product->id, $before, $product->fresh()->toArray());

        return $product->fresh();
    }

    /** @return array<int, array> */
    public function catalog(int $tenantId): array
    {
        return Product::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->where('is_active', true)->where('is_online', true)->where('product_type', 'stock')
            ->with('variants')->orderBy('name')->get()->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku,
                'variants' => $p->variants->map(fn ($v) => ['id' => $v->id, 'name' => $v->name, 'sku' => $v->sku, 'price' => (float) $v->sell_price])->all(),
            ])->all();
    }

    /** @return array{lines:array,total:float} */
    public function cartContents(int $tenantId, string $email): array
    {
        $cart = EcommerceCart::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('email', strtolower($email))->first();
        $lines = [];
        $total = 0.0;
        foreach ($cart?->lines ?? [] as $row) {
            $variant = ProductVariant::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($row['variant_id'] ?? 0);
            if (! $variant) {
                continue;
            }
            $qty = round((float) ($row['quantity'] ?? 0), 3);
            $price = round((float) $variant->sell_price, 2);
            $lines[] = ['variant_id' => $variant->id, 'sku' => $variant->sku, 'quantity' => $qty, 'unit_price' => $price, 'line_total' => round($qty * $price, 2)];
            $total = round($total + $qty * $price, 2);
        }

        return ['lines' => $lines, 'total' => $total];
    }

    public function addToCart(int $tenantId, string $email, int $variantId, float $qty): array
    {
        $email = strtolower(trim($email));
        abort_unless(filter_var($email, FILTER_VALIDATE_EMAIL), 422, 'Valid email is required.');
        $variant = ProductVariant::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($variantId);
        // Resolve the product tenant-explicitly: lazy relations honor the
        // ambient TenantContext, which is wrong for cross-context callers.
        $product = Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($variant->product_id);
        abort_unless($product && $product->is_online && $product->is_active, 422, 'Product is not sold online.');
        $qty = round($qty, 3);
        abort_if($qty <= 0, 422, 'Quantity must be positive.');

        $cart = EcommerceCart::withoutGlobalScopes()->firstOrCreate(['tenant_id' => $tenantId, 'email' => $email], ['lines' => []]);
        $lines = collect($cart->lines ?? []);
        if ($lines->firstWhere('variant_id', $variantId)) {
            $lines = $lines->map(fn ($row) => $row['variant_id'] === $variantId ? ['variant_id' => $variantId, 'quantity' => round((float) $row['quantity'] + $qty, 3)] : $row);
        } else {
            $lines->push(['variant_id' => $variantId, 'quantity' => $qty]);
        }
        $cart->update(['lines' => $lines->values()->all()]);

        return $this->cartContents($tenantId, $email);
    }

    public function checkout(int $tenantId, array $data, ?int $actorId = null): EcommerceOrder
    {
        return DB::transaction(function () use ($tenantId, $data, $actorId) {
            $email = strtolower(trim((string) ($data['email'] ?? '')));
            abort_unless(filter_var($email, FILTER_VALIDATE_EMAIL), 422, 'Valid email is required.');
            foreach (['recipient', 'phone', 'address'] as $field) {
                abort_if(trim((string) ($data[$field] ?? '')) === '', 422, ucfirst($field).' is required.');
            }
            $method = $data['shipping_method'] ?? 'regular';
            abort_unless(isset(self::SHIPPING_FEES[$method]), 422, 'Invalid shipping method.');
            $warehouseId = (int) ($data['warehouse_id'] ?? 0);
            $warehouse = Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId)->when($warehouseId, fn ($q) => $q->whereKey($warehouseId))->orderBy('id')->firstOrFail();

            $cart = $this->cartContents($tenantId, $email);
            abort_if($cart['lines'] === [], 422, 'Cart is empty.');
            // Stock is validated here; deducted once, at payment.
            foreach ($cart['lines'] as $row) {
                $available = $this->stock->onHand($tenantId, $warehouse->id, $row['variant_id']);
                abort_if($available + 0.0005 < $row['quantity'], 422, "Insufficient stock for [{$row['sku']}].");
            }
            $subtotal = $cart['total'];
            $shipping = self::SHIPPING_FEES[$method];
            $contact = Contact::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenantId, 'email' => $email],
                ['type' => 'customer', 'name' => $data['recipient'], 'phone' => $data['phone']]
            );
            $order = EcommerceOrder::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId, 'number' => $this->nextNumber(), 'contact_id' => $contact->id,
                'email' => $email, 'recipient' => $data['recipient'], 'phone' => $data['phone'],
                'address' => $data['address'], 'city' => $data['city'] ?? null, 'postal_code' => $data['postal_code'] ?? null,
                'shipping_method' => $method, 'shipping_fee' => $shipping,
                'status' => EcommerceOrder::PENDING, 'subtotal' => $subtotal, 'discount' => 0,
                'total' => round($subtotal + $shipping, 2),
            ]);
            foreach ($cart['lines'] as $row) {
                $order->lines()->create([
                    'tenant_id' => $tenantId, 'product_variant_id' => $row['variant_id'],
                    'quantity' => $row['quantity'], 'unit_price' => $row['unit_price'],
                ]);
            }
            EcommerceCart::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('email', $email)->delete();
            $this->audit->log($tenantId, $actorId, 'ecommerce.order.checkout', EcommerceOrder::class, $order->id, null, ['number' => $order->number, 'total' => $order->total]);

            return $order->load('lines');
        });
    }

    /** Payment deducts stock exactly once; over/underpayment refused. */
    public function markPaid(EcommerceOrder $order, float $amount, string $method, ?int $actorId = null): EcommerceOrder
    {
        return DB::transaction(function () use ($order, $amount, $method, $actorId) {
            $locked = EcommerceOrder::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);
            abort_if($locked->status !== EcommerceOrder::PENDING, 422, 'Only pending orders can be paid.');
            $amount = round($amount, 2);
            abort_if(abs($amount - (float) $locked->total) > 0.005, 422, 'Payment must equal the order total.');
            $warehouseId = Warehouse::withoutGlobalScopes()->where('tenant_id', $locked->tenant_id)->orderBy('id')->value('id');
            foreach ($locked->lines as $line) {
                $this->stock->decrease($locked->tenant_id, $warehouseId, $line->product_variant_id, (float) $line->quantity, 'ecommerce_sale', $locked->id);
            }
            $before = $locked->toArray();
            $locked->update(['paid' => $amount, 'payment_method' => $method, 'status' => EcommerceOrder::PAID, 'paid_at' => now()]);
            $this->audit->log($locked->tenant_id, $actorId, 'ecommerce.order.paid', EcommerceOrder::class, $locked->id, $before, $locked->fresh()->toArray());

            return $locked->fresh('lines');
        });
    }

    public function ship(EcommerceOrder $order, ?string $trackingNumber, ?int $actorId = null): EcommerceOrder
    {
        $locked = EcommerceOrder::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);
        abort_if($locked->status !== EcommerceOrder::PAID, 422, 'Only paid orders can ship.');
        $before = $locked->toArray();
        $locked->update(['status' => EcommerceOrder::SHIPPED, 'tracking_number' => $trackingNumber, 'shipped_at' => now()]);
        $this->audit->log($locked->tenant_id, $actorId, 'ecommerce.order.shipped', EcommerceOrder::class, $locked->id, $before, $locked->fresh()->toArray());

        return $locked->fresh();
    }

    public function deliver(EcommerceOrder $order, ?int $actorId = null): EcommerceOrder
    {
        $locked = EcommerceOrder::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);
        abort_if($locked->status !== EcommerceOrder::SHIPPED, 422, 'Only shipped orders can be delivered.');
        $before = $locked->toArray();
        $locked->update(['status' => EcommerceOrder::DELIVERED, 'delivered_at' => now()]);
        $this->audit->log($locked->tenant_id, $actorId, 'ecommerce.order.delivered', EcommerceOrder::class, $locked->id, $before, $locked->fresh()->toArray());

        return $locked->fresh();
    }

    public function cancel(EcommerceOrder $order, ?int $actorId = null): EcommerceOrder
    {
        $locked = EcommerceOrder::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);
        abort_if($locked->status !== EcommerceOrder::PENDING, 422, 'Only pending orders can be cancelled (paid orders need a refund flow).');
        $before = $locked->toArray();
        $locked->update(['status' => EcommerceOrder::CANCELLED]);
        $this->audit->log($locked->tenant_id, $actorId, 'ecommerce.order.cancelled', EcommerceOrder::class, $locked->id, $before, $locked->fresh()->toArray());

        return $locked->fresh();
    }

    private function nextNumber(): string
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $no = 'EC-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));
            if (! EcommerceOrder::withoutGlobalScopes()->where('number', $no)->exists()) {
                return $no;
            }
        }

        return 'EC-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8));
    }
}
