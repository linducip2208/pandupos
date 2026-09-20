<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mrp_boms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('finished_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'finished_variant_id', 'version']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('mrp_bom_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('bom_id')->constrained('mrp_boms')->cascadeOnDelete();
            $table->foreignId('component_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('scrap_rate', 7, 4)->default(0);
            $table->timestamps();
            $table->unique(['bom_id', 'component_variant_id']);
        });

        Schema::create('mrp_work_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('number', 64);
            $table->foreignId('bom_id')->constrained('mrp_boms')->restrictOnDelete();
            $table->foreignId('finished_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->decimal('quantity_planned', 15, 3);
            $table->decimal('quantity_produced', 15, 3)->default(0);
            $table->decimal('quantity_scrapped', 15, 3)->default(0);
            $table->decimal('consumed_basis', 15, 3)->default(0);
            $table->decimal('material_cost', 15, 2)->default(0);
            $table->decimal('unit_cost', 15, 2)->nullable();
            $table->string('status', 16)->default('draft'); // draft|released|in_progress|done|cancelled
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mrp_work_orders');
        Schema::dropIfExists('mrp_bom_lines');
        Schema::dropIfExists('mrp_boms');
    }
};
