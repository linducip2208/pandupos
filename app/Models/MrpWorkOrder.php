<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class MrpWorkOrder extends Model
{
    use BelongsToTenant;

    public const DRAFT = 'draft';

    public const RELEASED = 'released';

    public const IN_PROGRESS = 'in_progress';

    public const DONE = 'done';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id', 'number', 'bom_id', 'finished_variant_id', 'warehouse_id',
        'quantity_planned', 'quantity_produced', 'quantity_scrapped', 'consumed_basis',
        'material_cost', 'unit_cost', 'status', 'scheduled_at', 'started_at',
        'finished_at', 'created_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_planned' => 'decimal:3', 'quantity_produced' => 'decimal:3',
            'quantity_scrapped' => 'decimal:3', 'consumed_basis' => 'decimal:3',
            'material_cost' => 'decimal:2', 'unit_cost' => 'decimal:2',
            'scheduled_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime',
        ];
    }

    public function bom()
    {
        return $this->belongsTo(MrpBom::class, 'bom_id');
    }

    public function finishedVariant()
    {
        return $this->belongsTo(ProductVariant::class, 'finished_variant_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function cumulativeOutput(): float
    {
        return round((float) $this->quantity_produced + (float) $this->quantity_scrapped, 3);
    }
}
