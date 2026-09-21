<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class KitchenItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'ticket_id', 'product_variant_id', 'quantity', 'unit_price',
        'modifiers', 'status', 'refired', 'refire_reason', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3', 'unit_price' => 'decimal:2',
            'modifiers' => 'array', 'refired' => 'boolean',
        ];
    }

    public function ticket()
    {
        return $this->belongsTo(KitchenTicket::class, 'ticket_id');
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
