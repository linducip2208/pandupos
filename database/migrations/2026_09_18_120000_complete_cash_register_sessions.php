<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->decimal('expected_amount', 15, 2)->nullable()->after('closing_amount');
            $table->decimal('variance_amount', 15, 2)->nullable()->after('expected_amount');
            $table->json('denomination_counts')->nullable()->after('variance_amount');
            $table->foreignId('closed_by')->nullable()->after('opened_by')->constrained('users')->nullOnDelete();
            $table->text('closing_notes')->nullable()->after('status');
            $table->index(['tenant_id', 'register_id', 'status']);
        });

        Schema::create('cash_session_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('cash_session_id')->constrained('cash_sessions')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('type', 16); // cash_in, cash_out
            $table->decimal('amount', 15, 2);
            $table->string('reason', 1000);
            $table->timestamps();
            $table->index(['tenant_id', 'cash_session_id', 'type']);
        });

        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->foreignId('cash_session_id')->nullable()->after('warehouse_id')->constrained('cash_sessions')->nullOnDelete();
            $table->index(['tenant_id', 'cash_session_id']);
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropForeign(['cash_session_id']);
            $table->dropIndex(['tenant_id', 'cash_session_id']);
            $table->dropColumn('cash_session_id');
        });

        Schema::dropIfExists('cash_session_movements');

        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->dropForeign(['closed_by']);
            $table->dropIndex(['tenant_id', 'register_id', 'status']);
            $table->dropColumn(['expected_amount', 'variance_amount', 'denomination_counts', 'closed_by', 'closing_notes']);
        });
    }
};
