<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Module extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug', 'name', 'description', 'version',
        'category', 'status', 'is_core', 'is_paid',
        'provider', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_core' => 'boolean',
            'is_paid' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
