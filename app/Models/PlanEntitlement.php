<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanEntitlement extends Model
{
    protected $fillable = ['plan_id', 'entitlement', 'value'];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    /** null = unlimited, "1"/"0" = bool, numeric string = limit */
    public function isUnlimited(): bool
    {
        return $this->value === null;
    }
}
