<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_lines', function (Blueprint $table) {
            $table->foreignId('source_warehouse_location_id')->nullable()->after('destination_inventory_batch_id')
                ->constrained('warehouse_locations')->restrictOnDelete();
            $table->foreignId('destination_warehouse_location_id')->nullable()->after('source_warehouse_location_id')
                ->constrained('warehouse_locations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transfer_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('destination_warehouse_location_id');
            $table->dropConstrainedForeignId('source_warehouse_location_id');
        });
    }
};
