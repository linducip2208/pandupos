<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hms_patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 160);
            $table->date('birth_date')->nullable();
            $table->string('gender', 16)->nullable();
            $table->string('phone', 64)->nullable();
            $table->text('address')->nullable();
            $table->string('blood_type', 8)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('hms_doctors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('specialization', 128)->nullable();
            $table->decimal('consultation_fee', 15, 2)->default(0);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('hms_appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('hms_patients')->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('hms_doctors')->restrictOnDelete();
            $table->timestamp('scheduled_at');
            $table->unsignedInteger('duration_minutes')->default(30);
            $table->string('status', 16)->default('scheduled'); // scheduled|checked_in|completed|cancelled|no_show
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'doctor_id', 'scheduled_at']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('hms_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('appointment_id')->constrained('hms_appointments')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('hms_patients')->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('hms_doctors')->restrictOnDelete();
            $table->text('diagnosis');
            $table->text('prescription')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'patient_id']);
        });

        Schema::create('hms_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('number', 64);
            $table->foreignId('patient_id')->constrained('hms_patients')->restrictOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained('hms_appointments')->nullOnDelete();
            $table->decimal('consultation_fee', 15, 2)->default(0);
            $table->decimal('pharmacy_total', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('paid', 15, 2)->default(0);
            $table->decimal('balance', 15, 2)->default(0);
            $table->string('status', 16)->default('unpaid'); // unpaid|partial|paid
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('hms_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('hms_invoice_id')->constrained('hms_invoices')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2);
            $table->timestamps();
            $table->index(['tenant_id', 'hms_invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hms_invoice_lines');
        Schema::dropIfExists('hms_invoices');
        Schema::dropIfExists('hms_records');
        Schema::dropIfExists('hms_appointments');
        Schema::dropIfExists('hms_doctors');
        Schema::dropIfExists('hms_patients');
    }
};
