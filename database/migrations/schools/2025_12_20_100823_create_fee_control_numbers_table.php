<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fee_control_numbers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('fees_id');
            $table->enum('fee_type', ['compulsory', 'optional']);
            $table->unsignedBigInteger('class_id');
            $table->unsignedBigInteger('session_year_id');
            $table->string('control_number')->unique();
            $table->decimal('amount_required', 15, 2);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->decimal('balance', 15, 2);
            $table->string('status')->default('PENDING'); // PENDING, PAID, PARTIAL
            $table->json('payload')->nullable(); // Store full gateway response
            $table->timestamp('gateway_created_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'fees_id', 'fee_type'], 'unique_control_number');
            $table->index(['school_id', 'student_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fee_control_numbers');
    }
};
