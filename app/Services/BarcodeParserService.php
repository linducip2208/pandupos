<?php

namespace App\Services;

use App\Models\BarcodeProfile;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

final class BarcodeParserService
{
    public function parse(int $tenantId, string $barcode): array
    {
        $barcode = trim($barcode);
        if ($barcode === '') {
            throw ValidationException::withMessages(['barcode' => 'Barcode is required.']);
        }

        $variant = ProductVariant::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('barcode', $barcode)->first();
        if ($variant) {
            return $this->result('normal', $variant, 1.0, null);
        }

        $product = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('barcode', $barcode)->with('variants')->first();
        if ($product?->variants->first()) {
            return $this->result('normal', $product->variants->first(), 1.0, null);
        }

        $profile = BarcodeProfile::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('is_active', true)
            ->get()->first(fn (BarcodeProfile $row) => str_starts_with($barcode, $row->prefix));

        if (! $profile || strlen($barcode) !== $profile->total_length || ! ctype_digit($barcode)) {
            throw ValidationException::withMessages(['barcode' => 'Barcode is unknown or does not match an active scale profile.']);
        }

        $itemCode = substr($barcode, $profile->item_start, $profile->item_length);
        $rawValue = substr($barcode, $profile->value_start, $profile->value_length);
        if ($itemCode === false || $rawValue === false || ! ctype_digit($rawValue)) {
            throw ValidationException::withMessages(['barcode' => 'Scale barcode fields are invalid.']);
        }

        $variant = ProductVariant::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where(fn ($query) => $query->where('barcode', $itemCode)->orWhere('sku', $itemCode))
            ->first();
        if (! $variant) {
            throw ValidationException::withMessages(['barcode' => 'Scale barcode item code does not match a variant.']);
        }

        $value = ((int) $rawValue) / (10 ** $profile->decimal_places);

        return $profile->value_type === 'price'
            ? $this->result('weighing_price', $variant, null, round($value, 2))
            : $this->result('weighing_weight', $variant, round($value, 6), null);
    }

    private function result(string $type, ProductVariant $variant, ?float $quantity, ?float $totalPrice): array
    {
        return [
            'type' => $type,
            'variant_id' => $variant->id,
            'quantity' => $quantity,
            'total_price' => $totalPrice,
        ];
    }
}
