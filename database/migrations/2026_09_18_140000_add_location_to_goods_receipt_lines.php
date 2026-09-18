<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            $table->foreignId('warehouse_location_id')->nullable()->after('inventory_batch_id')
                ->constrained('warehouse_locations')->restrictOnDelete();
            $table->index(['goods_receipt_id', 'warehouse_location_id'], 'grn_location_idx');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            $table->dropIndex('grn_location_idx');
            $table->dropConstrainedForeignId('warehouse_location_id');
        });
    }
};
