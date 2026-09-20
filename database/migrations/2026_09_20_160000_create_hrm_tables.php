<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hrm_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 128);
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('hrm_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 160);
            $table->foreignId('department_id')->nullable()->constrained('hrm_departments')->nullOnDelete();
            $table->string('position', 128)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('join_date')->nullable();
            $table->string('status', 16)->default('active'); // active|inactive|terminated
            $table->string('phone', 64)->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('hrm_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hrm_employees')->cascadeOnDelete();
            $table->date('worked_on');
            $table->timestamp('check_in')->nullable();
            $table->timestamp('check_out')->nullable();
            $table->decimal('work_hours', 5, 2)->default(0);
            $table->string('status', 16)->default('present'); // present|late|absent|leave|holiday
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'worked_on']);
            $table->index(['tenant_id', 'worked_on']);
        });

        Schema::create('hrm_leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hrm_employees')->cascadeOnDelete();
            $table->string('type', 16); // annual|sick|unpaid
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedInteger('days');
            $table->text('reason')->nullable();
            $table->string('status', 16)->default('pending'); // pending|approved|rejected
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'employee_id', 'status']);
        });

        Schema::create('hrm_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->date('holiday_on');
            $table->string('name', 160);
            $table->timestamps();
            $table->unique(['tenant_id', 'holiday_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrm_holidays');
        Schema::dropIfExists('hrm_leaves');
        Schema::dropIfExists('hrm_attendances');
        Schema::dropIfExists('hrm_employees');
        Schema::dropIfExists('hrm_departments');
    }
};
