<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('quantity', 18, 6)->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'warehouse_id', 'product_variant_id'], 'inventory_balance_unique');
        });

        DB::table('stock_movements')
            ->selectRaw("tenant_id, warehouse_id, product_variant_id, SUM(CASE WHEN movement_type = 'in' THEN quantity ELSE -quantity END) AS quantity")
            ->groupBy('tenant_id', 'warehouse_id', 'product_variant_id')
            ->orderBy('tenant_id')->orderBy('warehouse_id')->orderBy('product_variant_id')
            ->chunk(500, function ($rows): void {
                DB::table('inventory_balances')->insert($rows->map(fn ($row) => [
                    'tenant_id' => $row->tenant_id,
                    'warehouse_id' => $row->warehouse_id,
                    'product_variant_id' => $row->product_variant_id,
                    'quantity' => $row->quantity,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all());
            });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 24)->default('draft');
            $table->string('reason', 32);
            $table->text('notes')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('stock_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained('stock_adjustments')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('quantity_change', 18, 6);
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->timestamps();
        });

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 24)->default('draft');
            $table->string('reference', 64)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('snapshot_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained('stock_counts')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('expected_quantity', 18, 6);
            $table->decimal('counted_quantity', 18, 6)->nullable();
            $table->decimal('variance_quantity', 18, 6)->nullable();
            $table->timestamps();
            $table->unique(['stock_count_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('stock_adjustment_lines');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('inventory_balances');
    }
};
