<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CrmOpportunity extends Model
{
    use BelongsToTenant;

    public const PROSPECT = 'prospect';

    public const NEGOTIATION = 'negotiation';

    public const WON = 'won';

    public const LOST = 'lost';

    public const STAGES = [self::PROSPECT, self::NEGOTIATION, self::WON, self::LOST];

    protected $fillable = [
        'tenant_id', 'lead_id', 'contact_id', 'title', 'value', 'stage',
        'expected_close', 'quotation_id', 'owner_id', 'notes',
    ];

    protected function casts(): array
    {
        return ['value' => 'decimal:2', 'expected_close' => 'date'];
    }

    public function lead()
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function activities()
    {
        return $this->hasMany(CrmActivity::class, 'opportunity_id');
    }
}
