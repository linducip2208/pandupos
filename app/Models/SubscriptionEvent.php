<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionEvent extends Model
{
    protected $fillable = ['subscription_id', 'tenant_id', 'event', 'payload', 'actor_id'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
