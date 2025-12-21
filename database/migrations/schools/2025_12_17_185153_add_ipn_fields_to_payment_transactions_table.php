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
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->string('control_number')->nullable()->after('payment_signature');
            $table->string('request_id')->nullable()->after('control_number');
            $table->string('student_name')->nullable()->after('request_id');
            $table->decimal('amount_required', 15, 2)->nullable()->after('student_name');
            $table->decimal('amount_paid', 15, 2)->nullable()->after('amount_required');
            $table->decimal('balance', 15, 2)->nullable()->after('amount_paid');
            $table->string('ipn_status')->nullable()->after('balance');
            $table->timestamp('ipn_created_at')->nullable()->after('ipn_status');
            $table->unsignedBigInteger('class_id')->nullable()->after('school_id');
            $table->unsignedBigInteger('session_year_id')->nullable()->after('class_id');
        });

    }
    

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropForeign(['class_id']);
            $table->dropForeign(['session_year_id']);
            $table->dropColumn([
                'control_number',
                'request_id',
                'student_name',
                'amount_required',
                'amount_paid',
                'balance',
                'ipn_status',
                'ipn_created_at',
                'class_id',
                'session_year_id'
            ]);
        });
    }
};
