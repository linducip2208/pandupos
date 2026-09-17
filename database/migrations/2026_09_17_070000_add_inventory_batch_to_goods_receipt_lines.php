<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            $table->foreignId('inventory_batch_id')->nullable()->after('product_variant_id')
                ->constrained('inventory_batches')->restrictOnDelete();
            $table->index(['inventory_batch_id']);
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_batch_id');
        });
    }
};
