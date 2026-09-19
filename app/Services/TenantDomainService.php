<?php

namespace App\Services;

use App\Models\TenantDomain;
use Illuminate\Support\Str;

/**
 * White-label domain lifecycle: secret-token ownership verification and safe
 * host resolution. A domain only ever maps to its owning tenant AFTER it has
 * been verified, and resolution performs exact-match only (never prefix/contains
 * matching), so a Host header can never hijack another tenant's context.
 */
final class TenantDomainService
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    /** Create a pending domain with a one-time secret verification token. */
    public function createForTenant(int $tenantId, string $domain, int $actorId): TenantDomain
    {
        $domain = $this->normalizeHost($domain);
        abort_if($domain === null, 422, 'Invalid domain.');

        // A domain may be claimed by exactly one tenant; duplicates fail at DB level (unique).
        return TenantDomain::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'domain' => $domain,
            'status' => self::STATUS_PENDING,
            'verification_token' => Str::random(64),
            'is_primary' => false,
        ]);
    }

    /** Rotate the token (revokes an in-flight verification). Returns new token. */
    public function rotateToken(TenantDomain $domain): string
    {
        $token = Str::random(64);
        TenantDomain::withoutGlobalScopes()->where('id', $domain->id)->update(['verification_token' => $token, 'updated_at' => now()]);

        return $token;
    }

    /** Public proof-of-ownership handshake. Only the holder of the secret token verifies. */
    public function verifyByToken(string $token): ?TenantDomain
    {
        if ($token === '' || strlen($token) > 64) {
            return null;
        }
        $domain = TenantDomain::withoutGlobalScopes()->where('verification_token', $token)->first();
        if (! $domain) {
            return null;
        }

        TenantDomain::withoutGlobalScopes()->where('id', $domain->id)->update([
            'status' => self::STATUS_VERIFIED, 'verified_at' => now(), 'updated_at' => now(),
        ]);

        // First person to reach a verified domain becomes primary automatically.
        $hasPrimary = TenantDomain::withoutGlobalScopes()
            ->where('tenant_id', $domain->tenant_id)->where('is_primary', true)->exists();
        if (! $hasPrimary) {
            TenantDomain::withoutGlobalScopes()->where('id', $domain->id)->update(['is_primary' => true]);
        }

        return $domain->refresh();
    }

    /** Promote a verified domain to primary (demoting any other primary of the tenant). */
    public function setPrimary(TenantDomain $domain): TenantDomain
    {
        abort_unless($domain->status === self::STATUS_VERIFIED && $domain->verified_at !== null, 422, 'Only verified domains can be primary.');
        TenantDomain::withoutGlobalScopes()->where('tenant_id', $domain->tenant_id)->where('is_primary', true)->update(['is_primary' => false]);
        TenantDomain::withoutGlobalScopes()->where('id', $domain->id)->update(['is_primary' => true, 'updated_at' => now()]);

        return $domain->refresh();
    }

    /**
     * Safe host resolution for verified domains only.
     * Returns the owning domain (exact host match) or null. Never resolves a
     * pending/unverified domain, a malformed host, or a case/prefix variant.
     */
    public function resolveByHost(?string $host): ?TenantDomain
    {
        $host = $this->normalizeHost($host);
        if ($host === null) {
            return null;
        }

        return TenantDomain::withoutGlobalScopes()
            ->where('domain', $host)
            ->where('status', self::STATUS_VERIFIED)
            ->whereNotNull('verified_at')
            ->first();
    }

    /** @return string|null Normalized hostname or null when not a plain DNS name. */
    private function normalizeHost(?string $host): ?string
    {
        if (is_null($host) || trim($host) === '') {
            return null;
        }
        $host = strtolower(trim((string) $host));
        $host = preg_replace('#^[a-z]+://#', '', $host);      // strip scheme
        $host = preg_replace('/:\d+$/', '', $host);            // strip port
        $host = rtrim($host, '.');                             // strip trailing FQDN dot
        if ($host === '' || ! preg_match('/^(?!-)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $host)) {
            return null;
        }

        return $host;
    }
}
