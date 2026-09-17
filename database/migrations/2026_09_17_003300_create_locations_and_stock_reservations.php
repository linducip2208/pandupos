<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('zone', 64)->nullable();
            $table->string('rack', 64)->nullable();
            $table->string('shelf', 64)->nullable();
            $table->string('bin', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'warehouse_id', 'code']);
            $table->index(['tenant_id', 'warehouse_id', 'is_active']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('warehouse_location_id')->nullable()->after('warehouse_id')
                ->constrained('warehouse_locations')->restrictOnDelete();
            $table->index(['tenant_id', 'warehouse_location_id', 'product_variant_id'], 'stock_location_variant_idx');
        });

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('warehouse_location_id')->nullable()->constrained('warehouse_locations')->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('inventory_batch_id')->nullable()->constrained('inventory_batches')->restrictOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'warehouse_id', 'product_variant_id', 'status'], 'reservation_availability_idx');
            $table->index(['tenant_id', 'source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('stock_location_variant_idx');
            $table->dropConstrainedForeignId('warehouse_location_id');
        });
        Schema::dropIfExists('warehouse_locations');
    }
};
