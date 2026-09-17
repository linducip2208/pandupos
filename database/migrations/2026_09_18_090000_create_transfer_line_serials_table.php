<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_line_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_line_id')->constrained('transfer_lines')->cascadeOnDelete();
            $table->foreignId('serial_number_id')->constrained('serial_numbers')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['transfer_line_id', 'serial_number_id']);
            $table->index('serial_number_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_line_serials');
    }
};
