<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class HrmDepartment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'manager_id'];

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function employees()
    {
        return $this->hasMany(HrmEmployee::class, 'department_id');
    }
}
