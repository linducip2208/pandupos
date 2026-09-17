<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_lines', function (Blueprint $table) {
            $table->foreignId('source_inventory_batch_id')->nullable()->after('product_variant_id')
                ->constrained('inventory_batches')->restrictOnDelete();
            $table->foreignId('destination_inventory_batch_id')->nullable()->after('source_inventory_batch_id')
                ->constrained('inventory_batches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transfer_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('destination_inventory_batch_id');
            $table->dropConstrainedForeignId('source_inventory_batch_id');
        });
    }
};
