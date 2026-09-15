<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'name', 'slug', 'status',
        'timezone', 'currency', 'locale', 'logo',
        'settings', 'trial_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
        ];
    }

    public const STATUSES = ['trial', 'active', 'past_due', 'suspended', 'cancelled', 'archived'];

    public function branches()
    {
        return $this->hasMany(Branch::class);
    }

    public function warehouses()
    {
        return $this->hasMany(Warehouse::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription()
    {
        return $this->hasOne(Subscription::class)->whereIn('status', ['trialing', 'active', 'past_due', 'grace_period'])->latestOfMany();
    }

    public function isBlocked(): bool
    {
        return in_array($this->status, ['suspended', 'cancelled', 'archived'], true);
    }
}
