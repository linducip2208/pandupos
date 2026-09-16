<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationProvider extends Model
{
    protected $fillable = [
        'tenant_id', 'integration_type', 'name', 'api_format', 'base_url',
        'api_key_encrypted', 'extra_headers', 'settings', 'is_active',
    ];

    protected $hidden = ['api_key_encrypted'];

    protected function casts(): array
    {
        return [
            'api_key_encrypted' => 'encrypted',
            'extra_headers' => 'array',
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function assignments()
    {
        return $this->hasMany(IntegrationFeatureAssignment::class);
    }
}
