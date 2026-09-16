<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('batch_number');
            $table->date('manufactured_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('purchase_id')->nullable()->constrained('purchases')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'warehouse_id', 'product_variant_id', 'batch_number'], 'inventory_batch_unique');
            $table->index(['tenant_id', 'expires_at']);
        });

        Schema::create('serial_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('inventory_batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
            $table->string('serial_number');
            $table->string('status', 24)->default('received');
            $table->foreignId('purchase_id')->nullable()->constrained('purchases')->nullOnDelete();
            $table->foreignId('sales_invoice_id')->nullable()->constrained('sales_invoices')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'serial_number']);
            $table->index(['tenant_id', 'warehouse_id', 'status']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('inventory_batch_id')->nullable()->after('product_variant_id')
                ->constrained('inventory_batches')->restrictOnDelete();
            $table->foreignId('serial_number_id')->nullable()->after('inventory_batch_id')
                ->constrained('serial_numbers')->restrictOnDelete();
            $table->index(['tenant_id', 'inventory_batch_id']);
            $table->index(['tenant_id', 'serial_number_id']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('serial_number_id');
            $table->dropConstrainedForeignId('inventory_batch_id');
        });
        Schema::dropIfExists('serial_numbers');
        Schema::dropIfExists('inventory_batches');
    }
};
