<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->decimal('default_discount_percent', 7, 4)->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('customer_group_id')->nullable()->after('type')
                ->constrained('customer_groups')->nullOnDelete();
        });

        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('scope', 24)->default('retail');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
            $table->foreignId('customer_group_id')->nullable()->constrained('customer_groups')->cascadeOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'is_active', 'priority']);
        });

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_id')->constrained('price_lists')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->decimal('price', 15, 2);
            $table->decimal('minimum_quantity', 15, 3)->default(0);
            $table->timestamps();
            $table->unique(['price_list_id', 'product_variant_id', 'minimum_quantity'], 'price_list_variant_qty_unique');
        });

        Schema::create('bundle_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('bundle_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('component_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->timestamps();
            $table->unique(['bundle_product_id', 'component_variant_id']);
        });

        Schema::create('barcode_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('prefix', 16);
            $table->unsignedTinyInteger('total_length');
            $table->unsignedTinyInteger('item_start');
            $table->unsignedTinyInteger('item_length');
            $table->unsignedTinyInteger('value_start');
            $table->unsignedTinyInteger('value_length');
            $table->string('value_type', 16)->default('weight');
            $table->unsignedTinyInteger('decimal_places')->default(3);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'prefix']);
        });

        Schema::create('sales_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_return_id')->constrained('sales_returns')->cascadeOnDelete();
            $table->foreignId('sales_line_id')->constrained('sales_lines')->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2);
            $table->timestamps();
            $table->index('sales_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_lines');
        Schema::dropIfExists('barcode_profiles');
        Schema::dropIfExists('bundle_items');
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
        Schema::table('contacts', fn (Blueprint $table) => $table->dropConstrainedForeignId('customer_group_id'));
        Schema::dropIfExists('customer_groups');
    }
};
