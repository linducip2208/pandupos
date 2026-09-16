<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class IntegrationFeatureAssignment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'feature_key', 'integration_provider_id', 'model_name', 'input_rate', 'output_rate', 'settings'];

    protected function casts(): array
    {
        return ['input_rate' => 'decimal:8', 'output_rate' => 'decimal:8', 'settings' => 'array'];
    }

    public function provider()
    {
        return $this->belongsTo(IntegrationProvider::class, 'integration_provider_id');
    }
}
