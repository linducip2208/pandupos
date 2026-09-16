<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('integration_type', 32)->index();
            $table->string('name');
            $table->string('api_format', 32);
            $table->string('base_url')->nullable();
            $table->text('api_key_encrypted')->nullable();
            $table->json('extra_headers')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['tenant_id', 'integration_type', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_providers');
    }
};
