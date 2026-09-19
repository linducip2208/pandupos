<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TenantDomain extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'domain', 'status', 'verification_token', 'verified_at', 'is_primary'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'is_primary' => 'boolean'];
    }
}
