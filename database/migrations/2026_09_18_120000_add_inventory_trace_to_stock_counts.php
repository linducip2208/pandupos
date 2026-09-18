<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_counts', function (Blueprint $table) {
            $table->foreignId('warehouse_location_id')->nullable()->after('warehouse_id')
                ->constrained('warehouse_locations')->restrictOnDelete();
        });
        Schema::table('stock_count_lines', function (Blueprint $table) {
            $table->foreignId('inventory_batch_id')->nullable()->after('product_variant_id')
                ->constrained('inventory_batches')->restrictOnDelete();
            $table->foreignId('serial_number_id')->nullable()->after('inventory_batch_id')
                ->constrained('serial_numbers')->restrictOnDelete();
            $table->dropUnique(['stock_count_id', 'product_variant_id']);
            $table->unique(['stock_count_id', 'product_variant_id', 'inventory_batch_id', 'serial_number_id'], 'stock_count_line_trace_unique');
        });
    }

    public function down(): void
    {
        Schema::table('stock_count_lines', function (Blueprint $table) {
            $table->dropUnique('stock_count_line_trace_unique');
            $table->unique(['stock_count_id', 'product_variant_id']);
            $table->dropConstrainedForeignId('serial_number_id');
            $table->dropConstrainedForeignId('inventory_batch_id');
        });
        Schema::table('stock_counts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_location_id');
        });
    }
};
