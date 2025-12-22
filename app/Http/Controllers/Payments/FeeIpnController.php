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

        // 1. Safety Guard: Check if amount is valid
        $totalPaidSoFar = $request->amount_paid ?? 0;

        if ($totalPaidSoFar <= 0) {
            Log::warning('IPN Ignored: Amount paid is zero or less.', ['amount' => $totalPaidSoFar]);
            return response()->json([
                'success' => false, 
                'message' => 'No update performed. Amount must be greater than zero.'
            ], 200); // 200 returned so the gateway stops retrying
        }

        // 2. Handle Multi-tenant connection
        if ($request->school_code) {
            $school = School::on('mysql')->where('code', $request->school_code)->where('installed', 1)->first();

            if (!$school) {
                Log::error('Invalid school code: ' . $request->school_code);
                return response()->json(['error' => 'Invalid school identifier.'], 400);
            }

            Config::set('database.connections.school.database', $school->database_name);
            DB::purge('school');
            DB::connection('school')->reconnect();
            DB::setDefaultConnection('school');
        }

        DB::beginTransaction();

        try {
            $controlNumber = $request->control_number;
            $status = strtoupper($request->payment_status ?? 'PENDING');

            // 3. Find the Control Number record
            $feeControlNumber = FeeControlNumber::where('control_number', $controlNumber)->first();

            if (!$feeControlNumber) {
                Log::error('Control number not found: ' . $controlNumber);
                DB::rollBack();
                return response()->json(['error' => 'Control number not found.'], 404);
            }

            // 4. Calculate the new Balance
            $newBalance = $feeControlNumber->amount_required - $totalPaidSoFar;

            // 5. Update Fee Control Number
            $feeControlNumber->update([
                'amount_paid' => $totalPaidSoFar,
                'balance'     => max(0, $newBalance), // Ensure balance never goes below 0
                'status'      => $newBalance <= 0 ? 'PAID' : $status,
            ]);

            // 6. Log the Transaction in payment_transactions
            PaymentTransaction::create([
                'user_id'           => $feeControlNumber->student_id,
                'amount'            => $totalPaidSoFar, 
                'payment_gateway'   => 'GePG/Gateway',
                'order_id'          => 'IPN-' . time(),
                'payment_id'        => $request->transaction_id ?? ('REC-' . uniqid()),
                'control_number'    => $controlNumber,
                'payment_status'    => ($newBalance <= 0) ? 'succeed' : 'pending',
                'type'              => 'fees',
                'school_id'         => $feeControlNumber->school_id,
            ]);

            // 7. Update the main FeesPaid table (The student's actual ledger)
            $this->updateStudentLedger($feeControlNumber, $totalPaidSoFar);

            DB::commit();
            Log::info("Payment Successfully Updated: CN $controlNumber, Total Paid: $totalPaidSoFar");

            return response()->json(['success' => true, 'message' => 'Payment updated']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('IPN Processing Error: ' . $e->getMessage());
            return response()->json(['error' => 'Internal Server Error'], 500);
        }
    }

    private function updateStudentLedger($feeControlNumber, $totalAmount)
    {
        FeesPaid::updateOrCreate(
            [
                'fees_id'    => $feeControlNumber->fees_id,
                'student_id' => $feeControlNumber->student_id,
            ],
            [
                'amount'        => $totalAmount,
                'is_fully_paid' => $totalAmount >= $feeControlNumber->amount_required,
                'date'          => now()->toDateString(),
                'school_id'     => $feeControlNumber->school_id
            ]
        );
    }



}


