<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function scalar(int $tenantId, string $key, mixed $default = null): mixed
    {
        return static::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('key', $key)->first()?->value ?? $default;
    }
}
