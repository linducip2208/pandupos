<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookDelivery extends Model
{
    protected $fillable = ['webhook_endpoint_id', 'event', 'payload', 'status', 'attempts'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'attempts' => 'integer'];
    }

    public function endpoint()
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
