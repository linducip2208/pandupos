<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Membership extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['user_id', 'tenant_id', 'branch_ids'];

    protected function casts(): array
    {
        return ['branch_ids' => 'array'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
