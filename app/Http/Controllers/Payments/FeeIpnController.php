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
            $amountPaidByGateway = $request->amount_paid ?? 0;
            $externalTransactionId = $request->transaction_id ?? $request->payment_id;
            $status = strtoupper($request->status ?? 'PENDING');

            // 1. VERIFY CONTROL NUMBER
            $feeControlNumber = FeeControlNumber::where('control_number', $controlNumber)->first();

            if (!$feeControlNumber) {
                Log::error('Control number not found: ' . $controlNumber);
                DB::rollBack();
                return response()->json(['error' => 'Control number not found.'], 404);
            }

            // 2. UPDATE FEE CONTROL NUMBER (Main Record)
            $newTotalPaid = $feeControlNumber->amount_paid + $amountPaidByGateway;
            $newBalance = $feeControlNumber->amount_required - $newTotalPaid;

            $feeControlNumber->update([
                'amount_paid' => $newTotalPaid,
                'balance'     => $newBalance,
                'status'      => $newBalance <= 0 ? 'PAID' : 'PARTIAL',
            ]);

            // 3. LOG TRANSACTION (Using ONLY your existing 13 columns)
            
            $paymentTransaction = PaymentTransaction::create([
                'user_id'           => $feeControlNumber->student_id,
                'amount'            => $amountPaidByGateway,
                'payment_gateway'   => $request->payment_gateway ?? 'Gateway',
                'order_id'          => 'ORD-' . time(),
                'payment_id'        => $externalTransactionId,
                'control_number'    => $controlNumber, // This exists in your DESC
                'payment_status'    => ($status === 'PAID' || $status === 'SUCCESS') ? 'succeed' : 'pending',
                'type'              => 'fees',
                'school_id'         => $feeControlNumber->school_id,
            ]);

            // 4. UPDATE SUMMARY TABLE
            $this->updateFeesPaidSummary($feeControlNumber, $amountPaidByGateway);

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Processed successfully']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('IPN Error: ' . $e->getMessage());
            return response()->json(['error' => 'Processing failed'], 500);
        }
    }

    private function updateFeesPaidSummary($feeControlNumber, $incomingAmount)
    {
        $feesPaid = FeesPaid::where('fees_id', $feeControlNumber->fees_id)
            ->where('student_id', $feeControlNumber->student_id)
            ->first();

        if ($feesPaid) {
            $total = $feesPaid->amount + $incomingAmount;
            $feesPaid->update([
                'amount' => $total,
                'is_fully_paid' => $total >= $feeControlNumber->amount_required,
                'date' => now()->toDateString()
            ]);
        } else {
            FeesPaid::create([
                'fees_id' => $feeControlNumber->fees_id,
                'student_id' => $feeControlNumber->student_id,
                'amount' => $incomingAmount,
                'is_fully_paid' => $incomingAmount >= $feeControlNumber->amount_required,
                'date' => now()->toDateString(),
                'school_id' => $feeControlNumber->school_id
            ]);
        }
    }
}

