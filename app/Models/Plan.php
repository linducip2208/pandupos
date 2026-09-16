<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'description', 'monthly_price', 'yearly_price',
        'currency', 'trial_days', 'is_active', 'is_public', 'is_featured', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'monthly_price' => 'decimal:2',
            'yearly_price' => 'decimal:2',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    public function entitlements()
    {
        return $this->hasMany(PlanEntitlement::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }
}
