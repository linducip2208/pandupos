<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AccountingPeriod extends Model
{
    use BelongsToTenant;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $fillable = [
        'tenant_id', 'starts_on', 'ends_on', 'status', 'closed_by', 'closed_at',
    ];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'closed_at' => 'datetime'];
    }

    public function covers(string $date): bool
    {
        return $date >= $this->starts_on->toDateString() && $date <= $this->ends_on->toDateString();
    }
}
