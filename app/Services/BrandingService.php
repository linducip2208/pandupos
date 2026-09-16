<?php

namespace App\Services;

/** Centralized branding — no hardcoded platform name in business views. */
final class BrandingService
{
    public function get(int $tenantId, string $key, ?string $default = null): ?string
    {
        $row = \DB::table('tenant_settings')->where('tenant_id', $tenantId)->where('key', "branding.{$key}")->first();

        return $row ? (string) $row->value : $default;
    }

    public function set(int $tenantId, string $key, ?string $value): void
    {
        \DB::table('tenant_settings')->updateOrInsert(
            ['tenant_id' => $tenantId, 'key' => "branding.{$key}"],
            ['value' => $value, 'updated_at' => now(), 'created_at' => now()]
        );
    }
}
