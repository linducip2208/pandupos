<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $fillable = [
        'code', 'discount_type', 'discount_value', 'applicable_plan_ids',
        'max_redemptions', 'per_tenant_limit', 'valid_from', 'valid_until', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'applicable_plan_ids' => 'array',
            'discount_value' => 'decimal:2',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function redemptions()
    {
        return $this->hasMany(CouponRedemption::class);
    }
}
