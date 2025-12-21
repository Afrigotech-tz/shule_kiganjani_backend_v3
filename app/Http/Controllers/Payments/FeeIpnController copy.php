<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\FeeControlNumber;
use App\Models\PaymentTransaction;
use App\Models\FeesPaid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FeeIpnController extends Controller
{
    public function payments(Request $request)
    {
        Log::info('RECEIVED FEES IPN DATA:', ['all_data' => $request->all()]);

        if ($request->school_code) {
            // Retrieve the school's database connection info
            $school = School::on('mysql')->where('code', $request->school_code)->where('installed', 1)->first();

            if (!$school) {
                Log::error('Invalid school code: ' . $request->school_code);
                return response()->json(['error' => 'Invalid school identifier.'], 400);
            }

            // Set the dynamic database connection
            Config::set('database.connections.school.database', $school->database_name);
            DB::purge('school');
            DB::connection('school')->reconnect();
            DB::setDefaultConnection('school');

            Log::info('Processing IPN on school database: ' . DB::connection('school')->getDatabaseName());
        }

        DB::beginTransaction();
        
        try {
            $controlNumber = $request->control_number;
            $amountPaid = $request->amount_paid ?? 0;
            $status = $request->status ?? 'PENDING';

            // Find the FeeControlNumber record
            $feeControlNumber = FeeControlNumber::where('control_number', $controlNumber)->first();

            if (!$feeControlNumber) {
                Log::error('FeeControlNumber not found for control number: ' . $controlNumber);
                DB::rollBack();
                return response()->json(['error' => 'Control number not found.'], 404);
            }

            // Update FeeControlNumber
            $existingPayload = is_array($feeControlNumber->payload) ? $feeControlNumber->payload : (json_decode($feeControlNumber->payload ?? '[]', true) ?: []);
            $feeControlNumber->update([
                'amount_paid' => $amountPaid,
                'balance' => $feeControlNumber->amount_required - $amountPaid,
                'status' => $status,
                'payload' => array_merge($existingPayload, ['ipn_data' => $request->all()])
            ]);

            // Find or create PaymentTransaction
            $paymentTransaction = PaymentTransaction::where('control_number', $controlNumber)->first();

            if ($paymentTransaction) {
                $paymentTransaction->update([
                    'amount_paid' => $amountPaid,
                    'balance' => $feeControlNumber->amount_required - $amountPaid,
                    'ipn_status' => $status,
                    'payment_status' => $status === 'PAID' ? 1 : 2, // 1 = success, 2 = pending
                    'ipn_created_at' => now()
                ]);

            } else {

                // Create new payment transaction if not exists
                $paymentTransaction = PaymentTransaction::create([
                    'user_id' => $feeControlNumber->student_id,
                    'amount' => $feeControlNumber->amount_required,
                    'payment_gateway' => 3, // Assuming 3 is for SM/other gateway
                    'order_id' => 'IPN-' . time() . '-' . $feeControlNumber->student_id,
                    'payment_status' => $status === 'PAID' ? 1 : 2,
                    'school_id' => $feeControlNumber->school_id,
                    'class_id' => $feeControlNumber->class_id,
                    'session_year_id' => $feeControlNumber->session_year_id,
                    'fee_type' => $feeControlNumber->fee_type,
                    'fees_id' => $feeControlNumber->fees_id,
                    'control_number' => $controlNumber,
                    'student_name' => $request->student_name ?? $feeControlNumber->student->first_name . ' ' . $feeControlNumber->student->last_name,
                    'amount_required' => $feeControlNumber->amount_required,
                    'amount_paid' => $amountPaid,
                    'balance' => $feeControlNumber->amount_required - $amountPaid,
                    'ipn_status' => $status,
                    'ipn_created_at' => now()
                ]);


            }

            // Update or create FeesPaid record
            $this->updateFeesPaid($feeControlNumber, $amountPaid, $status);

            DB::commit();

            Log::info('IPN processed successfully', [
                'control_number' => $controlNumber,
                'amount_paid' => $amountPaid,
                'status' => $status
            ]);

            return response()->json(['success' => true, 'message' => 'IPN processed successfully']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('IPN processing failed: ' . $e->getMessage());
            return response()->json(['error' => 'IPN processing failed'], 500);
        }
    }

    private function updateFeesPaid(FeeControlNumber $feeControlNumber, $amountPaid, $status)
    {
        // Check if FeesPaid record exists for this fee and student
        $feesPaid = FeesPaid::where('fees_id', $feeControlNumber->fees_id)
            ->where('student_id', $feeControlNumber->student_id)
            ->first();

        if ($feesPaid) {
            // Update existing record
            $newAmount = $feesPaid->amount + $amountPaid;
            $feesPaid->update([
                'amount' => $newAmount,
                'is_fully_paid' => $newAmount >= $feeControlNumber->amount_required,
                'date' => now()->toDateString()
            ]);
        } else {
            // Create new FeesPaid record
            FeesPaid::create([
                'fees_id' => $feeControlNumber->fees_id,
                'student_id' => $feeControlNumber->student_id,
                'amount' => $amountPaid,
                'is_fully_paid' => $amountPaid >= $feeControlNumber->amount_required,
                'is_used_installment' => false, // Assuming not using installments for now
                'date' => now()->toDateString(),
                'school_id' => $feeControlNumber->school_id
            ]);

        }


    }

    
}



