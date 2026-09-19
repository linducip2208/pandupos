<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->text('reason')->nullable()->after('status');
            $table->foreignId('created_by')->nullable()->after('reason')->constrained('users')->nullOnDelete();
        });

        Schema::create('sale_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->restrictOnDelete();
            $table->foreignId('sales_return_id')->constrained('sales_returns')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('method', 32);
            $table->string('reference', 100)->nullable();
            $table->text('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('refunded_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'reference']);
            $table->index(['tenant_id', 'sales_invoice_id']);
            $table->index(['sales_return_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_refunds');
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn('reason');
        });
    }
};
