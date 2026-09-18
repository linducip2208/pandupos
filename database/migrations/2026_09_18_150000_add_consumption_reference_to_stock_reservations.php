<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->string('consumed_reference_type', 80)->nullable()->after('consumed_at');
            $table->unsignedBigInteger('consumed_reference_id')->nullable()->after('consumed_reference_type');
            $table->index(['tenant_id', 'consumed_reference_type', 'consumed_reference_id'], 'stock_reservations_consumed_reference_index');
        });
    }

    public function down(): void
    {
        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->dropIndex('stock_reservations_consumed_reference_index');
            $table->dropColumn(['consumed_reference_type', 'consumed_reference_id']);
        });
    }
};
