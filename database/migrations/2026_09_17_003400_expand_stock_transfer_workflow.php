<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_orders', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->foreignId('shipped_by')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('in_transit_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
        });
        Schema::table('transfer_lines', function (Blueprint $table) {
            $table->decimal('received_quantity', 15, 3)->default(0)->after('quantity');
            $table->decimal('unit_cost', 18, 4)->default(0)->after('received_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('transfer_lines', function (Blueprint $table) {
            $table->dropColumn(['received_quantity', 'unit_cost']);
        });
        Schema::table('transfer_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('shipped_by');
            $table->dropColumn(['approved_at', 'shipped_at', 'in_transit_at', 'received_at', 'cancelled_at', 'notes']);
        });
    }
};
