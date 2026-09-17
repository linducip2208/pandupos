<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class StockAdjustment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'warehouse_id', 'status', 'reason', 'notes', 'requested_by', 'approved_by', 'posted_by', 'approved_at', 'posted_at'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'posted_at' => 'datetime'];
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
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
        return $this->hasMany(StockAdjustmentLine::class);
    }
}
