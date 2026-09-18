<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('serial_numbers', function (Blueprint $table) {
            $table->string('reserved_reference_type', 80)->nullable()->after('sales_invoice_id');
            $table->unsignedBigInteger('reserved_reference_id')->nullable()->after('reserved_reference_type');
            $table->timestamp('reserved_at')->nullable()->after('reserved_reference_id');
            $table->index(['tenant_id', 'reserved_reference_type', 'reserved_reference_id'], 'serial_reserved_reference_index');
        });
    }

    public function down(): void
    {
        Schema::table('serial_numbers', function (Blueprint $table) {
            $table->dropIndex('serial_reserved_reference_index');
            $table->dropColumn(['reserved_reference_type', 'reserved_reference_id', 'reserved_at']);
        });
    }
};
