<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('uuid', 64)->nullable()->after('tenant_id');
            $table->string('platform', 32)->nullable()->after('name');
            $table->timestamp('revoked_at')->nullable()->after('last_sync_at');
        });
        Schema::table('server_change_logs', function (Blueprint $table) {
            $table->string('entity', 64)->nullable()->after('tenant_id');
            $table->string('entity_uuid', 64)->nullable()->after('entity');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['uuid', 'platform', 'revoked_at']);
        });
        Schema::table('server_change_logs', function (Blueprint $table) {
            $table->dropColumn(['entity', 'entity_uuid']);
        });
    }
};
