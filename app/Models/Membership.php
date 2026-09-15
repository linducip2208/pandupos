<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Membership extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'tenant_id', 'branch_ids'];

    protected function casts(): array
    {
        return ['branch_ids' => 'array'];
    }
}
