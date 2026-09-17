<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->string('approval_level', 20)->nullable()->after('status');
            $table->foreignId('requested_by')->nullable()->after('approval_level')->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->after('requested_by')->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable()->after('approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn(['approval_level', 'approved_at']);
        });
    }
};
