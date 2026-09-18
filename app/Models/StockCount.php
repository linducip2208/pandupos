<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class StockCount extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'warehouse_id', 'warehouse_location_id', 'status', 'reference', 'notes', 'created_by', 'approved_by', 'posted_by', 'snapshot_at', 'approved_at', 'posted_at'];

    protected function casts(): array
    {
        return ['snapshot_at' => 'datetime', 'approved_at' => 'datetime', 'posted_at' => 'datetime'];
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function warehouseLocation()
    {
        return $this->belongsTo(WarehouseLocation::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function poster()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function lines()
    {
        return $this->hasMany(StockCountLine::class);
    }
}
