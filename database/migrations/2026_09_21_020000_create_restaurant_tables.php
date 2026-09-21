<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_floors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 128);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('floor_id')->nullable()->constrained('restaurant_floors')->nullOnDelete();
            $table->string('code', 32);
            $table->string('name', 128)->nullable();
            $table->unsignedInteger('seats')->default(2);
            $table->string('status', 16)->default('available'); // available|occupied|reserved
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('restaurant_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('table_id')->constrained('restaurant_tables')->cascadeOnDelete();
            $table->string('customer_name', 160);
            $table->string('phone', 64)->nullable();
            $table->timestamp('starts_at');
            $table->unsignedInteger('party_size')->default(1);
            $table->string('status', 16)->default('booked'); // booked|seated|cancelled|no_show
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'table_id', 'status']);
        });

        Schema::create('modifier_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 128);
            $table->unsignedInteger('min_select')->default(0);
            $table->unsignedInteger('max_select')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('modifier_groups')->cascadeOnDelete();
            $table->string('name', 128);
            $table->decimal('price_delta', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'group_id']);
        });

        Schema::create('product_modifier_group', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('modifier_groups')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['product_id', 'group_id']);
        });

        Schema::create('kitchen_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('number', 64);
            $table->foreignId('table_id')->nullable()->constrained('restaurant_tables')->nullOnDelete();
            $table->string('order_type', 16); // dine_in|takeaway|delivery
            $table->string('customer_name', 160)->nullable();
            $table->string('status', 16)->default('queued'); // queued|preparing|ready|served|cancelled
            $table->unsignedBigInteger('sales_invoice_id')->nullable();
            $table->decimal('total', 15, 2)->default(0);
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('kitchen_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained('kitchen_tickets')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2);
            $table->json('modifiers')->nullable();
            $table->string('status', 16)->default('queued'); // queued|preparing|ready|served
            $table->boolean('refired')->default(false);
            $table->string('refire_reason', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'ticket_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_items');
        Schema::dropIfExists('kitchen_tickets');
        Schema::dropIfExists('product_modifier_group');
        Schema::dropIfExists('modifiers');
        Schema::dropIfExists('modifier_groups');
        Schema::dropIfExists('restaurant_bookings');
        Schema::dropIfExists('restaurant_tables');
        Schema::dropIfExists('restaurant_floors');
    }
};
