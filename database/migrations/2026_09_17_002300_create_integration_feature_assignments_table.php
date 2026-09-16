<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_feature_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('feature_key', 120);
            $table->foreignId('integration_provider_id')->constrained('integration_providers')->restrictOnDelete();
            $table->string('model_name', 255)->nullable();
            $table->decimal('input_rate', 18, 8)->nullable();
            $table->decimal('output_rate', 18, 8)->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'feature_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_feature_assignments');
    }
};
