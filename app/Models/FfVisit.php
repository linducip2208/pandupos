<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class FfVisit extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'task_id', 'user_id', 'check_in_at', 'check_in_lat',
        'check_in_lng', 'check_out_at', 'check_out_lat', 'check_out_lng',
        'distance_m', 'notes', 'evidence_photo', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'check_in_at' => 'datetime', 'check_out_at' => 'datetime',
            'check_in_lat' => 'decimal:7', 'check_in_lng' => 'decimal:7',
            'check_out_lat' => 'decimal:7', 'check_out_lng' => 'decimal:7',
        ];
    }

    public function task()
    {
        return $this->belongsTo(FfTask::class, 'task_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
