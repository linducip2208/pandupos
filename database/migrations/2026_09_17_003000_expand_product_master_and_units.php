<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('product_type', 16)->default('stock')->after('name');
            $table->boolean('track_inventory')->default(true)->after('alert_quantity');
            $table->string('image_path')->nullable()->after('barcode');
            $table->decimal('tax_rate', 7, 4)->default(0)->after('alert_quantity');
            $table->string('tax_method', 16)->default('exclusive')->after('tax_rate');
            $table->index(['tenant_id', 'product_type', 'is_active']);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('barcode')->nullable()->after('sku');
            $table->json('attributes')->nullable()->after('barcode');
            $table->unique(['tenant_id', 'barcode']);
        });

        Schema::create('unit_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('from_unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('to_unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('factor', 20, 8);
            $table->timestamps();
            $table->unique(['tenant_id', 'from_unit_id', 'to_unit_id']);
        });

        Schema::create('product_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['product_id', 'warehouse_id']);
            $table->index(['tenant_id', 'warehouse_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_locations');
        Schema::dropIfExists('unit_conversions');

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'barcode']);
            $table->dropColumn(['barcode', 'attributes']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'product_type', 'is_active']);
            $table->dropColumn(['product_type', 'track_inventory', 'image_path', 'tax_rate', 'tax_method']);
        });
    }
};
