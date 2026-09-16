<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index(['tenant_id', 'barcode']);
            $table->index(['tenant_id', 'created_at']);
        });
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'invoice_no']);
            $table->index(['tenant_id', 'warehouse_id']);
            $table->index(['tenant_id', 'branch_id']);
        });
        Schema::table('purchases', function (Blueprint $table) {
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'warehouse_id']);
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'product_variant_id']);
        });
        Schema::table('contacts', function (Blueprint $table) {
            $table->index(['tenant_id', 'created_at']);
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->index(['status', 'current_period_end']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'barcode']);
            $table->dropIndex(['tenant_id', 'created_at']);
        });
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'created_at']);
            $table->dropIndex(['tenant_id', 'invoice_no']);
            $table->dropIndex(['tenant_id', 'warehouse_id']);
            $table->dropIndex(['tenant_id', 'branch_id']);
        });
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'created_at']);
            $table->dropIndex(['tenant_id', 'warehouse_id']);
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'created_at']);
            $table->dropIndex(['tenant_id', 'product_variant_id']);
        });
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'created_at']);
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['status', 'current_period_end']);
        });
    }
};
