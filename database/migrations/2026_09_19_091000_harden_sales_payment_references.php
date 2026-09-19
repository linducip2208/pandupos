<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('sale_payments')
            ->select('tenant_id', 'reference')
            ->whereNotNull('reference')
            ->groupBy('tenant_id', 'reference')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicates) {
            throw new RuntimeException('Cannot add unique sale payment reference: duplicate tenant payment references require reconciliation first.');
        }

        Schema::table('sale_payments', function (Blueprint $table) {
            $table->unique(['tenant_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'reference']);
        });
    }
};
