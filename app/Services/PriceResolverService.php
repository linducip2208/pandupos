<?php

namespace App\Services;

use App\Models\PriceListItem;
use App\Models\ProductVariant;
use Carbon\CarbonInterface;

final class PriceResolverService
{
    public function resolve(
        int $tenantId,
        int $variantId,
        float $quantity = 1,
        ?int $branchId = null,
        ?int $customerGroupId = null,
        ?CarbonInterface $at = null,
    ): float {
        return $this->resolveDetails($tenantId, $variantId, $quantity, $branchId, $customerGroupId, $at)['price'];
    }

    /** @return array{price:float,source:string,price_list_id:?int,scope:string} */
    public function resolveDetails(
        int $tenantId,
        int $variantId,
        float $quantity = 1,
        ?int $branchId = null,
        ?int $customerGroupId = null,
        ?CarbonInterface $at = null,
    ): array {
        $at ??= now();
        $variant = ProductVariant::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->findOrFail($variantId);

        $item = PriceListItem::query()
            ->where('product_variant_id', $variantId)
            ->where('minimum_quantity', '<=', $quantity)
            ->whereHas('priceList', function ($query) use ($tenantId, $branchId, $customerGroupId, $at) {
                $query->withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true)
                    ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
                    ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $at))
                    ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
                    ->where(fn ($q) => $q->whereNull('customer_group_id')->orWhere('customer_group_id', $customerGroupId));
            })
            ->join('price_lists', 'price_lists.id', '=', 'price_list_items.price_list_id')
            ->orderByDesc('price_lists.priority')
            ->orderByRaw('(CASE WHEN price_lists.branch_id IS NULL THEN 0 ELSE 1 END + CASE WHEN price_lists.customer_group_id IS NULL THEN 0 ELSE 1 END) DESC')
            ->orderByDesc('price_list_items.minimum_quantity')
            ->select('price_list_items.*', 'price_lists.name as source_name', 'price_lists.scope as source_scope')
            ->first();

        return [
            'price' => (float) ($item?->price ?? $variant->sell_price),
            'source' => $item?->source_name ?? 'Harga dasar varian',
            'price_list_id' => $item?->price_list_id,
            'scope' => $item?->source_scope ?? 'base',
        ];
    }

    public function auditChange(int $tenantId, int $priceListId, ?array $before, array $after, ?int $actorId): void
    {
        app(AuditService::class)->log($tenantId, $actorId, 'price_list.changed', 'price_list', $priceListId, $before, $after);
    }
}
