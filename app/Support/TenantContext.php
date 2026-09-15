<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Holds the current tenant for the request / job lifecycle.
 * Never rely on session/auth directly inside models.
 */
final class TenantContext
{
    private static ?Tenant $tenant = null;

    private static ?int $tenantId = null;

    public static function set(?Tenant $tenant): void
    {
        self::$tenant = $tenant;
        self::$tenantId = $tenant?->getKey();
    }

    public static function setId(?int $id): void
    {
        self::$tenantId = $id;
        self::$tenant = null;
    }

    public static function get(): ?Tenant
    {
        if (self::$tenant !== null) {
            return self::$tenant;
        }

        if (self::$tenantId !== null) {
            self::$tenant = Tenant::find(self::$tenantId);
        }

        return self::$tenant;
    }

    public static function id(): ?int
    {
        return self::$tenantId ?? self::$tenant?->getKey();
    }

    public static function check(): bool
    {
        return self::id() !== null;
    }

    public static function clear(): void
    {
        self::$tenant = null;
        self::$tenantId = null;
    }

    /** Run a callback under a tenant without leaking state. */
    public static function runAs(Tenant|int $tenant, callable $callback): mixed
    {
        $prevTenant = self::$tenant;
        $prevId = self::$tenantId;

        if ($tenant instanceof Tenant) {
            self::set($tenant);
        } else {
            self::setId($tenant);
        }

        try {
            return $callback();
        } finally {
            self::$tenant = $prevTenant;
            self::$tenantId = $prevId;
        }
    }
}
