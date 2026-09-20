<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wo_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 128);
            $table->string('store_url', 255);
            $table->text('consumer_key');
            $table->text('consumer_secret');
            $table->string('status', 16)->default('active'); // active|inactive|error
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('wo_product_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('wo_connections')->cascadeOnDelete();
            $table->foreignId('local_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedBigInteger('woo_product_id');
            $table->timestamps();
            $table->unique(['connection_id', 'woo_product_id']);
            $table->unique(['connection_id', 'local_variant_id']);
        });

        Schema::create('wo_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('wo_connections')->cascadeOnDelete();
            $table->string('direction', 8); // push|pull
            $table->string('entity', 16); // product|inventory|order|customer
            $table->string('external_id', 64)->nullable();
            $table->string('local_reference', 64)->nullable();
            $table->string('status', 16); // success|failed
            $table->text('message')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'connection_id', 'entity']);
            $table->index(['tenant_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wo_sync_logs');
        Schema::dropIfExists('wo_product_links');
        Schema::dropIfExists('wo_connections');
    }
};
