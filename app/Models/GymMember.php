<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class GymMember extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'code', 'name', 'phone', 'contact_id', 'status'];

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function memberships()
    {
        return $this->hasMany(GymMembership::class, 'member_id');
    }
}
