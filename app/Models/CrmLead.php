<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CrmLead extends Model
{
    use BelongsToTenant;

    public const NEW = 'new';

    public const CONTACTED = 'contacted';

    public const QUALIFIED = 'qualified';

    public const CONVERTED = 'converted';

    public const LOST = 'lost';

    public const STATUSES = [self::NEW, self::CONTACTED, self::QUALIFIED, self::CONVERTED, self::LOST];

    protected $fillable = [
        'tenant_id', 'name', 'company', 'email', 'phone', 'source',
        'status', 'owner_id', 'converted_contact_id', 'notes',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function convertedContact()
    {
        return $this->belongsTo(Contact::class, 'converted_contact_id');
    }

    public function opportunities()
    {
        return $this->hasMany(CrmOpportunity::class, 'lead_id');
    }

    public function activities()
    {
        return $this->hasMany(CrmActivity::class, 'lead_id');
    }
}
