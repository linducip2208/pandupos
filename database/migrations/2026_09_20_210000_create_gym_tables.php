<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gym_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 128);
            $table->unsignedInteger('duration_days');
            $table->decimal('price', 15, 2)->default(0);
            $table->unsignedInteger('visits_limit')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('gym_trainers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('specialization', 128)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('gym_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 160);
            $table->string('phone', 64)->nullable();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('status', 16)->default('active'); // active|inactive
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('gym_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('gym_members')->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('gym_packages')->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedInteger('visits_limit')->nullable();
            $table->unsignedInteger('visits_used')->default(0);
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('paid', 15, 2)->default(0);
            $table->decimal('balance', 15, 2)->default(0);
            $table->string('status', 16)->default('active'); // active|expired|cancelled
            $table->timestamps();
            $table->index(['tenant_id', 'member_id', 'status']);
        });

        Schema::create('gym_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained('gym_memberships')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('gym_members')->cascadeOnDelete();
            $table->foreignId('trainer_id')->nullable()->constrained('gym_trainers')->nullOnDelete();
            $table->timestamp('checked_in_at');
            $table->timestamps();
            $table->index(['tenant_id', 'membership_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gym_attendances');
        Schema::dropIfExists('gym_memberships');
        Schema::dropIfExists('gym_members');
        Schema::dropIfExists('gym_trainers');
        Schema::dropIfExists('gym_packages');
    }
};
