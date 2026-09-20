<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AssetTransfer extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'asset_id', 'from_custodian_id', 'to_custodian_id',
        'from_location', 'to_location', 'transferred_at', 'created_by', 'notes',
    ];

    protected function casts(): array
    {
        return ['transferred_at' => 'datetime'];
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }
}
