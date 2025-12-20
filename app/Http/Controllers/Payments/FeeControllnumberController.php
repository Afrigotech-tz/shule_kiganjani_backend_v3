<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FeeControllnumberController extends Controller
{
    public function generate(Request $request)
    {
        try {
            
            $request->validate([
                'fees_id' => 'required|integer',
                'student_id' => 'required|integer',
                'amount' => 'required|numeric|min:1',
                'class_id' => 'required|integer',
                'session_year_id' => 'required|integer',
            ]);

            // Get school database connection if needed
            $schoolCode = $request->input('school_code');
            if (!$schoolCode) {
                // Get school code from authenticated user's school
                $school = DB::connection('mysql')
                    ->table('schools')
                    ->where('id', auth()->user()->school_id ?? 1)
                    ->first();
                $schoolCode = $school->code ?? 'NULL';
            }
            if ($schoolCode && $schoolCode !== 'NULL') {
                $this->switchToSchoolDatabase($schoolCode);
            }

            DB::beginTransaction();

            $user = User::findOrFail($request->student_id);

            $studentName = trim($user->first_name . ' ' . $user->last_name);

            // Create payment transaction record
            $paymentTransaction = PaymentTransaction::create([
                'user_id' => $request->student_id,
                'amount' => $request->amount,
                'payment_gateway' => 3,  // Assuming 3 is for SM/other gateway
                'order_id' => 'ORD-' . time() . '-' . $request->student_id,
                'payment_status' => 2,  // pending
                'school_id' => auth()->user()->school_id ?? 1,
                'class_id' => $request->class_id,
                'session_year_id' => $request->session_year_id,

            ]);

            // Call payment gateway API to generate control number
            $controlNumber = $this->generateControlNumberFromGateway([
                'amount' => $request->amount,
                'student_id' => $request->student_id,
                'student_name' => $studentName,
                'fees_id' => $request->fees_id,
                'order_id' => $paymentTransaction->order_id,
                'school_code' => $schoolCode

            ]);
            

            if ($controlNumber) {
                $paymentTransaction->update([
                    'control_number' => $controlNumber,
                    'request_id' => 'REQ-' . time() . '-' . $request->student_id
                ]);

                DB::commit();

                Log::info('Control number generated successfully', [
                    'payment_id' => $paymentTransaction->id,
                    'control_number' => $controlNumber,
                    'student_id' => $request->student_id
                ]);

                return response()->json([
                    'success' => true,
                    'control_number' => $controlNumber,
                    'payment_id' => $paymentTransaction->id,
                    'message' => 'Control number generated successfully'
                ]);
            } else {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to generate control number from payment gateway'
                ], 500);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Control number generation failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Control number generation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    private function generateControlNumberFromGateway($data)
    {
        try {
            // Log the data being sent to the API
            Log::info('Sending data to control number generation API', $data);

            // Use the provided API endpoint
            $apiUrl = env("PAYMENT_GATEWAY_URL");

            // Call the payment gateway API
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post($apiUrl, [
                'amount_required' => $data['amount'],
                'student_id' => $data['student_id'],
                'student_name' => $data['student_name'],
                'fees_id' => $data['fees_id'],
                'order_id' => $data['order_id'],
                'school_code' => $data['school_code']
            ]);

            if ($response->successful()) {
                $responseData = $response->json();
                return $responseData['control_number'] ?? null;
            } else {
                Log::error('Payment gateway API error: ' . $response->body());
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Payment gateway API call failed: ' . $e->getMessage());
            return null;
        }
    }

    private function switchToSchoolDatabase($schoolCode)
    {
        $school = DB::connection('mysql')
            ->table('schools')
            ->where('code', $schoolCode)
            ->where('installed', 1)
            ->first();

        if ($school) {
            config(['database.connections.school.database' => $school->database_name]);
            DB::purge('school');
            DB::reconnect('school');
            DB::setDefaultConnection('school');
        }
    }
}
