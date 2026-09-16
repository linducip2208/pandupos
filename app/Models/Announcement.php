<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    protected $fillable = ['subject', 'body', 'audience', 'channel', 'scheduled_at', 'sent_at'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function deliveries()
    {
        return $this->hasMany(AnnouncementDelivery::class);
    }
}
