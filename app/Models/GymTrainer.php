<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class GymTrainer extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'specialization', 'user_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
