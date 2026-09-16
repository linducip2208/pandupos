<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('device_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'device_id']);
        });

        Schema::create('server_change_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('entity_type', 64);
            $table->unsignedBigInteger('entity_id');
            $table->string('operation', 16); // upsert, delete
            $table->timestamp('changed_at')->useCurrent();
            $table->timestamps();
            $table->index(['tenant_id', 'changed_at']);
        });

        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('url');
            $table->text('secret');
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
            $table->string('event', 64);
            $table->json('payload')->nullable();
            $table->string('status', 16)->default('queued');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
        });

        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('key', 128);
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('tenant_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('domain')->unique();
            $table->string('status', 16)->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_domains');
        Schema::dropIfExists('tenant_settings');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('server_change_logs');
        Schema::dropIfExists('devices');
    }
};
