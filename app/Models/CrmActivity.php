<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CrmActivity extends Model
{
    use BelongsToTenant;

    public const TYPES = ['call', 'meeting', 'email', 'note', 'follow-up'];

    protected $fillable = [
        'tenant_id', 'lead_id', 'opportunity_id', 'type', 'subject',
        'notes', 'scheduled_at', 'done_at', 'owner_id',
    ];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'done_at' => 'datetime'];
    }

    public function lead()
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    public function opportunity()
    {
        return $this->belongsTo(CrmOpportunity::class, 'opportunity_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function isOverdue(): bool
    {
        return $this->done_at === null && $this->scheduled_at !== null && $this->scheduled_at->isPast();
    }
}
