<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class HrmHoliday extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'holiday_on', 'name'];

    protected function casts(): array
    {
        return ['holiday_on' => 'date'];
    }
}
