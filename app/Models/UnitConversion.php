<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class UnitConversion extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'from_unit_id', 'to_unit_id', 'factor'];

    protected function casts(): array
    {
        return ['factor' => 'decimal:8'];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function fromUnit()
    {
        return $this->belongsTo(Unit::class, 'from_unit_id');
    }

    public function toUnit()
    {
        return $this->belongsTo(Unit::class, 'to_unit_id');
    }
}
