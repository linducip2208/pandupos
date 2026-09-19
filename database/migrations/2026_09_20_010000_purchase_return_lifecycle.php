<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('purchase_id')
                ->constrained('contacts')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->after('supplier_id')
                ->constrained('warehouses')->restrictOnDelete();
            $table->decimal('subtotal', 15, 2)->default(0)->after('total');
            $table->decimal('tax', 15, 2)->default(0)->after('subtotal');
            $table->decimal('discount', 15, 2)->default(0)->after('tax');
            $table->foreignId('requested_by')->nullable()->after('created_by')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->after('requested_by')
                ->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable()->after('reviewed_by');
            $table->foreignId('approved_by')->nullable()->after('reviewed_at')
                ->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable()->after('approved_by');
            $table->string('idempotency_key', 128)->nullable()->after('return_no');
            $table->unique(['tenant_id', 'idempotency_key']);
        });

        Schema::table('purchase_return_lines', function (Blueprint $table) {
            $table->foreignId('serial_number_id')->nullable()->after('warehouse_location_id')
                ->constrained('serial_numbers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_return_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('serial_number_id');
        });
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'idempotency_key']);
            $table->dropColumn(['idempotency_key', 'approved_at', 'approved_by', 'reviewed_at', 'reviewed_by', 'requested_by', 'discount', 'tax', 'subtotal']);
            $table->dropConstrainedForeignId('warehouse_id');
            $table->dropConstrainedForeignId('supplier_id');
        });
    }
};
