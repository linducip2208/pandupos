<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Affiliate extends Model
{
    protected $fillable = ['user_id', 'code', 'status', 'tc_accepted_at'];

    protected function casts(): array
    {
        return ['tc_accepted_at' => 'datetime'];
    }
}
