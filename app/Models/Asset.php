<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    use BelongsToTenant;

    public const ACTIVE = 'active';

    public const ASSIGNED = 'assigned';

    public const MAINTENANCE = 'maintenance';

    public const DISPOSED = 'disposed';

    protected $fillable = [
        'tenant_id', 'code', 'name', 'category', 'purchase_date', 'purchase_cost',
        'salvage_value', 'useful_life_months', 'depreciation_method', 'status',
        'location', 'custodian_id', 'disposed_at', 'disposal_proceeds',
        'disposal_gain_loss', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'purchase_cost' => 'decimal:2', 'salvage_value' => 'decimal:2',
            'disposal_proceeds' => 'decimal:2', 'disposal_gain_loss' => 'decimal:2',
            'disposed_at' => 'datetime',
        ];
    }

    public function custodian()
    {
        return $this->belongsTo(User::class, 'custodian_id');
    }

    public function transfers()
    {
        return $this->hasMany(AssetTransfer::class);
    }

    public function maintenances()
    {
        return $this->hasMany(AssetMaintenance::class);
    }
}
