<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class MrpBom extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'finished_variant_id', 'version', 'is_active', 'notes'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function finishedVariant()
    {
        return $this->belongsTo(ProductVariant::class, 'finished_variant_id');
    }

    public function lines()
    {
        return $this->hasMany(MrpBomLine::class, 'bom_id');
    }
}
