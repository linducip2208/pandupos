<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('code', 32)->nullable();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('status', 16)->default('planned'); // planned|active|on_hold|completed|cancelled
            $table->decimal('budget', 15, 2)->default(0);
            $table->decimal('hourly_rate', 15, 2)->default(0);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('project_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('todo'); // todo|doing|review|done
            $table->decimal('estimate_hours', 8, 2)->default(0);
            $table->date('due_on')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'project_id', 'status']);
        });

        Schema::create('project_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('title', 200);
            $table->date('due_on')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'project_id']);
        });

        Schema::create('project_timesheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('project_tasks')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('worked_on');
            $table->decimal('hours', 5, 2);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'project_id', 'worked_on']);
        });

        Schema::create('project_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('description', 200);
            $table->string('category', 64)->nullable();
            $table->decimal('amount', 15, 2);
            $table->date('spent_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_expenses');
        Schema::dropIfExists('project_timesheets');
        Schema::dropIfExists('project_milestones');
        Schema::dropIfExists('project_tasks');
        Schema::dropIfExists('projects');
    }
};
