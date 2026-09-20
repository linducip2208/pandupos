<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ff_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('address', 255)->nullable();
            $table->decimal('planned_lat', 10, 7)->nullable();
            $table->decimal('planned_lng', 10, 7)->nullable();
            $table->string('status', 16)->default('assigned'); // assigned|en_route|checked_in|completed|cancelled
            $table->date('due_on')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'assignee_id', 'status']);
        });

        Schema::create('ff_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('ff_tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('check_in_at');
            $table->decimal('check_in_lat', 10, 7);
            $table->decimal('check_in_lng', 10, 7);
            $table->timestamp('check_out_at')->nullable();
            $table->decimal('check_out_lat', 10, 7)->nullable();
            $table->decimal('check_out_lng', 10, 7)->nullable();
            $table->unsignedInteger('distance_m')->nullable();
            $table->text('notes')->nullable();
            $table->string('evidence_photo', 255)->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ff_visits');
        Schema::dropIfExists('ff_tasks');
    }
};
