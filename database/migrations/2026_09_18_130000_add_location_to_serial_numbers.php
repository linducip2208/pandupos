<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('serial_numbers', function (Blueprint $table) {
            $table->foreignId('warehouse_location_id')->nullable()->after('warehouse_id')
                ->constrained('warehouse_locations')->restrictOnDelete();
            $table->index(['tenant_id', 'warehouse_id', 'warehouse_location_id', 'status'], 'serial_location_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('serial_numbers', function (Blueprint $table) {
            $table->dropIndex('serial_location_status_idx');
            $table->dropConstrainedForeignId('warehouse_location_id');
        });
    }
};
