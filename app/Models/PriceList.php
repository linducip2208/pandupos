<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PriceList extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'scope', 'branch_id', 'customer_group_id',
        'starts_at', 'ends_at', 'priority', 'is_active',
    ];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean'];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function customerGroup()
    {
        return $this->belongsTo(CustomerGroup::class);
    }

    public function items()
    {
        return $this->hasMany(PriceListItem::class);
    }
}
