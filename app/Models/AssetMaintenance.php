<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AssetMaintenance extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'asset_id', 'maintained_on', 'kind', 'cost',
        'next_due_on', 'created_by', 'notes',
    ];

    protected function casts(): array
    {
        return ['maintained_on' => 'date', 'cost' => 'decimal:2', 'next_due_on' => 'date'];
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }
}
