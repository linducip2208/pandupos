<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentWebhook extends Model
{
    protected $fillable = ['gateway', 'gateway_ref', 'payload', 'status'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
