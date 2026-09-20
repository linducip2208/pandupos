<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_online')->default(false)->after('is_active');
        });

        Schema::create('ecommerce_carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('email', 128);
            $table->json('lines')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'email']);
        });

        Schema::create('ecommerce_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('number', 64);
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('email', 128);
            $table->string('recipient', 160);
            $table->string('phone', 64);
            $table->text('address');
            $table->string('city', 128)->nullable();
            $table->string('postal_code', 16)->nullable();
            $table->string('shipping_method', 32)->default('regular');
            $table->decimal('shipping_fee', 15, 2)->default(0);
            $table->string('tracking_number', 64)->nullable();
            $table->string('status', 16)->default('pending'); // pending|paid|shipped|delivered|cancelled
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('paid', 15, 2)->default(0);
            $table->string('payment_method', 32)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'email']);
        });

        Schema::create('ecommerce_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('ecommerce_order_id')->constrained('ecommerce_orders')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2);
            $table->timestamps();
            $table->index(['tenant_id', 'ecommerce_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecommerce_order_lines');
        Schema::dropIfExists('ecommerce_orders');
        Schema::dropIfExists('ecommerce_carts');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_online');
        });
    }
};
