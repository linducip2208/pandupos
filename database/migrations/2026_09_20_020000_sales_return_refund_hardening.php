<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->string('idempotency_key', 128)->nullable()->after('total');
            $table->unique(['tenant_id', 'idempotency_key']);
        });

        Schema::table('sales_return_lines', function (Blueprint $table) {
            $table->foreignId('inventory_batch_id')->nullable()->after('unit_price')
                ->constrained('inventory_batches')->restrictOnDelete();
            $table->foreignId('serial_number_id')->nullable()->after('inventory_batch_id')
                ->constrained('serial_numbers')->restrictOnDelete();
        });

        Schema::table('sale_refunds', function (Blueprint $table) {
            $table->foreignId('cash_session_movement_id')->nullable()->after('created_by')
                ->constrained('cash_session_movements')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sale_refunds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_session_movement_id');
        });
        Schema::table('sales_return_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('serial_number_id');
            $table->dropConstrainedForeignId('inventory_batch_id');
        });
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
