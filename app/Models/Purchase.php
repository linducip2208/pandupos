<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'warehouse_id', 'contact_id', 'ref_no', 'status', 'total'];

    protected function casts(): array
    {
        return ['total' => 'decimal:2'];
    }

    public function lines()
    {
        return $this->hasMany(PurchaseLine::class);
    }
}
