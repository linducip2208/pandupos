<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 160);
            $table->string('category', 64)->nullable();
            $table->date('purchase_date');
            $table->decimal('purchase_cost', 15, 2);
            $table->decimal('salvage_value', 15, 2)->default(0);
            $table->unsignedInteger('useful_life_months');
            $table->string('depreciation_method', 32)->default('straight_line'); // straight_line|declining_balance
            $table->string('status', 16)->default('active'); // active|assigned|maintenance|disposed
            $table->string('location', 128)->nullable();
            $table->foreignId('custodian_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disposed_at')->nullable();
            $table->decimal('disposal_proceeds', 15, 2)->nullable();
            $table->decimal('disposal_gain_loss', 15, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('asset_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('from_custodian_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_custodian_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_location', 128)->nullable();
            $table->string('to_location', 128)->nullable();
            $table->timestamp('transferred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'asset_id']);
        });

        Schema::create('asset_maintenances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->date('maintained_on');
            $table->string('kind', 32)->default('preventive'); // preventive|corrective
            $table->decimal('cost', 15, 2)->default(0);
            $table->date('next_due_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_maintenances');
        Schema::dropIfExists('asset_transfers');
        Schema::dropIfExists('assets');
    }
};
