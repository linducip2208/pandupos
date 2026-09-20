<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zatca_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('type', 16); // invoice|credit_note|debit_note
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('references_document_id')->nullable();
            $table->string('seller_name', 255);
            $table->string('seller_vat', 32);
            $table->string('buyer_name', 255)->nullable();
            $table->string('buyer_vat', 32)->nullable();
            $table->decimal('total', 15, 2);
            $table->decimal('vat_total', 15, 2);
            $table->timestamp('issued_at');
            $table->text('qr_tlv');
            $table->mediumText('xml');
            $table->string('hash', 64);
            $table->string('status', 16)->default('generated'); // generated|reported
            $table->string('clearance_id', 64)->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zatca_documents');
    }
};
