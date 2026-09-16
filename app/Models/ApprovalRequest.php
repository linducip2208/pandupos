<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ApprovalRequest extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'subject_type', 'subject_id', 'amount', 'status', 'requested_by', 'decided_by', 'reason', 'metadata', 'decided_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'metadata' => 'array', 'decided_at' => 'datetime'];
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
