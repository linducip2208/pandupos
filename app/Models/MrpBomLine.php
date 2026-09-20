<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class MrpBomLine extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'bom_id', 'component_variant_id', 'quantity', 'scrap_rate'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'scrap_rate' => 'decimal:4'];
    }

    public function bom()
    {
        return $this->belongsTo(MrpBom::class, 'bom_id');
    }

    public function component()
    {
        return $this->belongsTo(ProductVariant::class, 'component_variant_id');
    }
}
