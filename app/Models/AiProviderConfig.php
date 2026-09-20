<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AiProviderConfig extends Model
{
    use BelongsToTenant;

    public const PROVIDERS = ['openai', 'anthropic', 'google', 'openrouter', 'custom'];

    protected $fillable = ['tenant_id', 'provider', 'base_url', 'api_key', 'model', 'monthly_token_cap', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'monthly_token_cap' => 'integer'];
    }
}
