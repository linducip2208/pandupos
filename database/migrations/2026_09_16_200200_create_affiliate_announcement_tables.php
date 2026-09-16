<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('code', 16)->unique();
            $table->string('status', 16)->default('pending');
            $table->timestamp('tc_accepted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('affiliate_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 16);
            $table->timestamps();
        });

        Schema::create('affiliate_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->decimal('sale_amount', 15, 2);
            $table->decimal('commission_percent', 5, 2);
            $table->decimal('commission_amount', 15, 2);
            $table->string('status', 16)->default('pending');
            $table->timestamps();
        });

        Schema::create('affiliate_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('bank_ref')->nullable();
            $table->string('status', 16)->default('paid');
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('subject');
            $table->text('body');
            $table->string('audience', 64)->default('all'); // all, trial, expired, plan:slug
            $table->string('channel', 16)->default('in-app');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('announcement_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained('announcements')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('status', 16)->default('queued');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['announcement_id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_deliveries');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('affiliate_payouts');
        Schema::dropIfExists('affiliate_commissions');
        Schema::dropIfExists('affiliate_referrals');
        Schema::dropIfExists('affiliates');
    }
};
