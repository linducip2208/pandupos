<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->foreignId('sales_order_id')->nullable()->after('warehouse_id')->constrained('sales_orders')->restrictOnDelete();
            $table->unique(['tenant_id', 'sales_order_id']);
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'sales_order_id']);
            $table->dropConstrainedForeignId('sales_order_id');
        });
    }
};
