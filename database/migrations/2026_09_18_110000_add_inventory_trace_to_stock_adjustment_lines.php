<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_adjustment_lines', function (Blueprint $table) {
            $table->foreignId('inventory_batch_id')->nullable()->after('product_variant_id')
                ->constrained('inventory_batches')->restrictOnDelete();
            $table->foreignId('serial_number_id')->nullable()->after('inventory_batch_id')
                ->constrained('serial_numbers')->restrictOnDelete();
            $table->foreignId('warehouse_location_id')->nullable()->after('serial_number_id')
                ->constrained('warehouse_locations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_adjustment_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_location_id');
            $table->dropConstrainedForeignId('serial_number_id');
            $table->dropConstrainedForeignId('inventory_batch_id');
        });
    }
};
