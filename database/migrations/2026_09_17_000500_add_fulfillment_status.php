<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->string('fulfillment_status', 16)->default('unfulfilled')->after('payment_status');
            $table->index(['tenant_id', 'fulfillment_status']);
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'fulfillment_status']);
            $table->dropColumn('fulfillment_status');
        });
    }
};
