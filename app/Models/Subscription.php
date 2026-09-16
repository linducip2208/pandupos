<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'plan_id', 'status', 'billing_cycle',
        'starts_at', 'trial_ends_at', 'current_period_start',
        'current_period_end', 'cancelled_at', 'ends_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancelled_at' => 'datetime',
            'ends_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public const ACTIVE_STATUSES = ['trialing', 'active', 'past_due', 'grace_period'];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function isUsable(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function events()
    {
        return $this->hasMany(SubscriptionEvent::class);
    }

    public function invoices()
    {
        return $this->hasMany(BillingInvoice::class);
    }
}
