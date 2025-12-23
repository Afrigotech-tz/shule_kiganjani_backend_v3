<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\FeeControlNumber;
use App\Models\FeesAdvance;
use App\Models\FeesPaid;
use App\Models\PaymentTransaction;
use App\Models\School;
use App\Repositories\ClassSchool\ClassSchoolInterface;
use App\Repositories\ClassSection\ClassSectionInterface;
use App\Repositories\CompulsoryFee\CompulsoryFeeInterface;
use App\Repositories\Fees\FeesInterface;
use App\Repositories\FeesClassType\FeesClassTypeInterface;
use App\Repositories\FeesInstallment\FeesInstallmentInterface;
use App\Repositories\FeesPaid\FeesPaidInterface;
use App\Repositories\FeesType\FeesTypeInterface;
use App\Repositories\Medium\MediumInterface;
use App\Repositories\OptionalFee\OptionalFeeInterface;
use App\Repositories\PaymentConfiguration\PaymentConfigurationInterface;
use App\Repositories\PaymentTransaction\PaymentTransactionInterface;
use App\Repositories\SchoolSetting\SchoolSettingInterface;
use App\Repositories\SessionYear\SessionYearInterface;
use App\Repositories\Student\StudentInterface;
use App\Repositories\SystemSetting\SystemSettingInterface;
use App\Repositories\User\UserInterface;
use App\Services\BootstrapTableService;
use App\Services\CachingService;
use App\Services\ResponseService;
use App\Services\SessionYearsTrackingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class FeeIpnController extends Controller
{
    private FeesInterface $fees;
    private SessionYearInterface $sessionYear;
    private FeesInstallmentInterface $feesInstallment;
    private SchoolSettingInterface $schoolSettings;
    private MediumInterface $medium;
    private FeesTypeInterface $feesType;
    private ClassSchoolInterface $classes;
    private FeesClassTypeInterface $feesClassType;
    private UserInterface $user;
    private FeesPaidInterface $feesPaid;
    private CompulsoryFeeInterface $compulsoryFee;
    private OptionalFeeInterface $optionalFee;
    private CachingService $cache;
    private PaymentConfigurationInterface $paymentConfigurations;
    private ClassSchoolInterface $class;
    private StudentInterface $student;
    private PaymentTransactionInterface $paymentTransaction;
    private SystemSettingInterface $systemSetting;
    private ClassSectionInterface $classSection;
    private SessionYearsTrackingsService $sessionYearsTrackingsService;

    public function __construct(FeesInterface $fees, SessionYearInterface $sessionYear, FeesInstallmentInterface $feesInstallment, SchoolSettingInterface $schoolSettings, MediumInterface $medium, FeesTypeInterface $feesType, ClassSchoolInterface $classes, FeesClassTypeInterface $feesClassType, UserInterface $user, FeesPaidInterface $feesPaid, CompulsoryFeeInterface $compulsoryFee, OptionalFeeInterface $optionalFee, CachingService $cache, PaymentConfigurationInterface $paymentConfigurations, ClassSchoolInterface $classSchool, StudentInterface $student, PaymentTransactionInterface $paymentTransaction, SystemSettingInterface $systemSetting, ClassSectionInterface $classSection, SessionYearsTrackingsService $sessionYearsTrackingsService)
    {
        $this->fees = $fees;
        $this->sessionYear = $sessionYear;
        $this->feesInstallment = $feesInstallment;
        $this->schoolSettings = $schoolSettings;
        $this->medium = $medium;
        $this->feesType = $feesType;
        $this->classes = $classes;
        $this->feesClassType = $feesClassType;
        $this->user = $user;
        $this->feesPaid = $feesPaid;
        $this->compulsoryFee = $compulsoryFee;
        $this->optionalFee = $optionalFee;
        $this->cache = $cache;
        $this->paymentConfigurations = $paymentConfigurations;
        $this->class = $classSchool;
        $this->student = $student;
        $this->paymentTransaction = $paymentTransaction;
        $this->systemSetting = $systemSetting;
        $this->classSection = $classSection;
        $this->sessionYearsTrackingsService = $sessionYearsTrackingsService;
    }

    // public function payments(Request $request)
    // {
    //     Log::info('RECEIVED FEES IPN DATA:', ['all_data' => $request->all()]);

    //     $totalPaidSoFar = $request->amount_paid ?? 0;
    //     if ($totalPaidSoFar <= 200) {
    //         return response()->json(['success' => false, 'message' => 'Amount too low.'], 200);
    //     }

    //     // Handle Multi-tenant connection
    //     if ($request->school_code) {
    //         $school = School::on('mysql')->where('code', $request->school_code)->where('installed', 1)->first();
    //         if (!$school)
    //             return response()->json(['error' => 'Invalid school.'], 400);

    //         Config::set('database.connections.school.database', $school->database_name);
    //         DB::purge('school');
    //         DB::setDefaultConnection('school');
    //     }

    //     DB::beginTransaction();
    //     try {
    //         $controlNumber = $request->control_number;
    //         $feeControlNumber = FeeControlNumber::where('control_number', $controlNumber)->first();

    //         if (!$feeControlNumber) {
    //             DB::rollBack();
    //             return response()->json(['error' => 'Control number not found.'], 404);
    //         }

    //         // Update local control number record
    //         $newBalance = $feeControlNumber->amount_required - $totalPaidSoFar;
    //         $feeControlNumber->update([
    //             'amount_paid' => $totalPaidSoFar,
    //             'balance' => max(0, $newBalance),
    //             'status' => $newBalance <= 0 ? 'PAID' : 'PARTIAL',
    //         ]);

    //         // Create the Payment Transaction log
    //         PaymentTransaction::create([
    //             'user_id' => $feeControlNumber->student_id,
    //             'amount' => $totalPaidSoFar,
    //             'payment_gateway' => 'GePG/Gateway',
    //             'order_id' => 'IPN-' . time(),
    //             'payment_id' => $request->transaction_id ?? ('REC-' . uniqid()),
    //             'control_number' => $controlNumber,
    //             'payment_status' => ($newBalance <= 0) ? 'succeed' : 'pending',
    //             'type' => 'fees',
    //             'school_id' => $feeControlNumber->school_id,
    //         ]);

    //         // --- PREPARE DATA FOR payCompulsoryFeesStore ---
    //         $paymentRequest = new Request();
    //         $paymentRequest->replace([
    //             'fees_id' => $feeControlNumber->fees_id,  // From MariaDB table
    //             'student_id' => $feeControlNumber->student_id,  // From MariaDB table
    //             'installment_mode' => 0,  // IPN is usually bulk update
    //             'enter_amount' => $totalPaidSoFar,  // Amount from gateway
    //             'total_amount' => $feeControlNumber->amount_required,
    //             'date' => now()->format('Y-m-d'),
    //             'mode' => 3,  // 3 = Online/Gateway
    //             'advance' => 0  // Default
    //         ]);

    //         // Pass the manually created Request object
    //         $this->payCompulsoryFeesStore($paymentRequest);

    //         DB::commit();
    //         return response()->json(['success' => true, 'message' => 'Payment updated']);
    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         Log::error('IPN Processing Error: ' . $e->getMessage());
    //         return response()->json(['error' => 'Internal Error'], 500);
    //     }
    // }

    public function payments(Request $request)
    {
        Log::info('RECEIVED FEES IPN DATA:', ['all_data' => $request->all()]);

         $totalPaidSoFar = $request->add_amount ?? 0;
 

        if ($totalPaidSoFar <= 200) {
            return response()->json(['success' => false, 'message' => 'Amount too low.'], 200);
        }

        // Multi-tenant database connection logic
        if ($request->school_code) {
            $school = School::on('mysql')->where('code', $request->school_code)->where('installed', 1)->first();
            if (!$school)
                return response()->json(['error' => 'Invalid school code'], 400);

            Config::set('database.connections.school.database', $school->database_name);
            DB::purge('school');
            DB::setDefaultConnection('school');
        }

        DB::beginTransaction();

        try {
            $controlNumber = $request->control_number;

            // FIND the record in fee_control_numbers to get the school_id and fees_id
            $feeControlNumber = FeeControlNumber::where('control_number', $controlNumber)->first();

            if (!$feeControlNumber) {
                Log::error('Control number not found: ' . $controlNumber);
                DB::rollBack();
                return response()->json(['error' => 'Control number not found.'], 404);
            }

            // Update the Control Number record balance
            $newBalance = $feeControlNumber->amount_required - $totalPaidSoFar;
            $feeControlNumber->update([
                'amount_paid' => $totalPaidSoFar,
                'balance' => max(0, $newBalance),
                'status' => $newBalance <= 0 ? 'PAID' : 'PARTIAL',
            ]);

            // LOG the transaction
            PaymentTransaction::create([
                'user_id' => $feeControlNumber->student_id,
                'amount' => $totalPaidSoFar,
                'payment_gateway' => 'GePG/Gateway',
                'order_id' => 'IPN-' . time(),
                'payment_id' => $request->transaction_id ?? ('REC-' . uniqid()),
                'control_number' => $controlNumber,
                'payment_status' => ($newBalance <= 0) ? 'succeed' : 'pending',
                'type' => 'fees',
                'school_id' => $feeControlNumber->school_id,
            ]);

            // --- PREPARE DATA FOR payCompulsoryFeesStore ---
            // We create a new request and MANUALLY put the school_id from the DB row into it
            $paymentRequest = new Request();
            $paymentRequest->replace([
                'fees_id' => $feeControlNumber->fees_id,  // From DB
                'student_id' => $feeControlNumber->student_id,  // From DB
                'school_id' => $feeControlNumber->school_id,  // From DB (Fixes your error)
                'installment_mode' => 0,
                'enter_amount' => $totalPaidSoFar,
                'total_amount' => $feeControlNumber->amount_required,
                'date' => now()->format('Y-m-d'),
                'mode' => 3,  // Online
                'advance' => 0
            ]);


            // Proceed to update the main fees ledger
            $this->payCompulsoryFeesStore($paymentRequest);

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Payment updated']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('IPN Processing Error: ' . $e->getMessage());
            return response()->json(['error' => 'Internal Server Error'], 500);
        }
    }

    // public function payCompulsoryFeesStore(Request $request)
    // {
    //     Log::info('THE INFORMATION DATA FOR NOW IS :', ['data' => $request->all()]);

    //     $request->validate([
    //         'fees_id' => 'required|numeric',
    //         'student_id' => 'required|numeric',
    //         'installment_mode' => 'required|boolean',
    //         'installment_fees' => 'array',
    //         'installment_fees' => 'required_if:installment_mode,1',
    //     ], [
    //         'installment_fees.required_if' => 'Please select at least one installment'
    //     ]);

    //     try {
    //         DB::beginTransaction();
    //         $fees = $this->fees->findById($request->fees_id, ['*'], ['fees_class_type.fees_type:id,name', 'installments:id,name,due_date,due_charges,fees_id']);

    //         $feesPaid = $this->feesPaid->builder()->where([
    //             'fees_id' => $request->fees_id,
    //             'student_id' => $request->student_id
    //         ])->first();

    //         if (!empty($feesPaid) && $feesPaid->is_fully_paid) {
    //             ResponseService::errorResponse('Compulsory Fees already Paid');
    //         }

    //         $amount = 0;
    //         // If Fees Paid Doesn't Exists
    //         if ($request->installment_mode) {
    //             if (!empty($request->installment_fees)) {
    //                 $amount = array_sum(array_column($request->installment_fees, 'amount'));
    //             }
    //             $amount += $request->advance;
    //         } else {
    //             if ($request->enter_amount) {
    //                 $amount = $request->enter_amount;
    //             } else {
    //                 $amount = $request->total_amount;
    //             }
    //         }

    //         if (empty($feesPaid)) {
    //             $feesPaidResult = $this->feesPaid->create([
    //                 'date' => date('Y-m-d', strtotime($request->date)),
    //                 'is_fully_paid' => $amount >= $fees->total_compulsory_fees,
    //                 'is_used_installment' => $request->installment_mode,
    //                 'fees_id' => $request->fees_id,
    //                 'student_id' => $request->student_id,
    //                 'amount' => $amount,
    //             ]);
    //         } else {
    //             $feesPaidResult = $this->feesPaid->update($feesPaid->id, [
    //                 'amount' => $amount + $feesPaid->amount,
    //                 'is_fully_paid' => ($amount + $feesPaid->amount) >= $fees->total_compulsory_fees
    //             ]);
    //         }
    //         if ($request->installment_mode == 1) {
    //             if (!empty($request->installment_fees)) {
    //                 foreach ($request->installment_fees as $installment_fee) {
    //                     $compulsoryFeeData = array(
    //                         'student_id' => $request->student_id,
    //                         'type' => 'Installment Payment',
    //                         'installment_id' => $installment_fee['id'],
    //                         'mode' => $request->mode,
    //                         'cheque_no' => $request->mode == 2 ? $request->cheque_no : null,
    //                         'amount' => $installment_fee['amount'],
    //                         'due_charges' => $installment_fee['due_charges'] ?? null,
    //                         'fees_paid_id' => $feesPaidResult->id,
    //                         'date' => date('Y-m-d', strtotime($request->date))
    //                     );
    //                     $this->compulsoryFee->create($compulsoryFeeData);

    //                     $sessionYear = $this->cache->getDefaultSessionYear();
    //                     $this->sessionYearsTrackingsService->storeSessionYearsTracking('App\Models\CompulsoryFee', $feesPaidResult->id, Auth::user()->id, $sessionYear->id, Auth::user()->school_id, null);
    //                 }
    //             } else {
    //             }
    //         } else {
    //             $compulsoryFeeData = array(
    //                 'type' => 'Full Payment',
    //                 'student_id' => $request->student_id,
    //                 'mode' => $request->mode,
    //                 'cheque_no' => $request->mode == 2 ? $request->cheque_no : null,
    //                 'amount' => $amount,
    //                 'due_charges' => $request->due_charges_amount ?? null,
    //                 'fees_paid_id' => $feesPaidResult->id,
    //                 'date' => date('Y-m-d', strtotime($request->date))
    //             );
    //             $this->compulsoryFee->create($compulsoryFeeData);

    //             $sessionYear = $this->cache->getDefaultSessionYear();
    //             $this->sessionYearsTrackingsService->storeSessionYearsTracking('App\Models\CompulsoryFee', $feesPaidResult->id, Auth::user()->id, $sessionYear->id, Auth::user()->school_id, null);
    //         }

    //         // Add advance amount in installment
    //         if ($request->advance > 0) {
    //             $updateCompulsoryFees = $this->compulsoryFee->builder()->where('student_id', $request->student_id)->with('fees_paid')->whereHas('fees_paid', function ($q) use ($request) {
    //                 $q->where('fees_id', $request->fees_id);
    //             })->orderBy('id', 'DESC')->first();

    //             $updateCompulsoryFees->amount += $request->advance;
    //             $updateCompulsoryFees->save();

    //             FeesAdvance::create([
    //                 'compulsory_fee_id' => $updateCompulsoryFees->id,
    //                 'student_id' => $request->student_id,
    //                 'parent_id' => $request->parent_id,
    //                 'amount' => $request->advance
    //             ]);
    //         }

    //         DB::commit();
    //         ResponseService::successResponse('Data Updated SuccessFully');
    //     } catch (Throwable $e) {
    //         DB::rollback();
    //         ResponseService::logErrorResponse($e, 'FeesController -> compulsoryFeesPaidStore method ');
    //         ResponseService::errorResponse();
    //     }

    // }

    public function payCompulsoryFeesStore(Request $request)
    {
        Log::info('THE INFORMATION DATA FOR NOW IS :', ['data' => $request->all()]);

        $request->validate([
            'fees_id' => 'required|numeric',
            'student_id' => 'required|numeric',
            'school_id' => 'required|numeric',
            'installment_mode' => 'required|boolean',
        ]);

        try {
            DB::beginTransaction();

            // 1. Fetch fee configuration - bypass global school filter
            $fees = $this
                ->fees
                ->builder()
                ->withoutGlobalScopes()
                ->where('id', $request->fees_id)
                ->with(['fees_class_type.fees_type', 'installments'])
                ->first();

            // 2. Check existing ledger - bypass global school filter
            $feesPaid = $this->feesPaid->builder()->withoutGlobalScopes()->where([
                'fees_id' => $request->fees_id,
                'student_id' => $request->student_id
            ])->first();

            if (!empty($feesPaid) && $feesPaid->is_fully_paid) {
                Log::info('IPN: Fees already fully paid for student ' . $request->student_id);
                DB::rollBack();
                return;
            }

            $amount = $request->enter_amount ?? $request->total_amount;

            // 3. Create or Update Ledger
            if (empty($feesPaid)) {
                $feesPaidResult = $this->feesPaid->builder()->withoutGlobalScopes()->create([
                    'date' => date('Y-m-d', strtotime($request->date)),
                    'is_fully_paid' => $amount >= $fees->total_compulsory_fees,
                    'is_used_installment' => $request->installment_mode,
                    'fees_id' => $request->fees_id,
                    'student_id' => $request->student_id,
                    'school_id' => $request->school_id,
                    'amount' => $amount,
                ]);
            } else {
                $feesPaid->update([
                    'amount' => $amount + $feesPaid->amount,
                    'is_fully_paid' => ($amount + $feesPaid->amount) >= $fees->total_compulsory_fees
                ]);
                $feesPaidResult = $feesPaid;
            }

            // 4. Record the individual transaction
            $this->compulsoryFee->builder()->withoutGlobalScopes()->create([
                'type' => 'Full Payment',
                'student_id' => $request->student_id,
                'school_id' => $request->school_id,
                'mode' => $request->mode ?? 3,
                'amount' => $amount,
                'fees_paid_id' => $feesPaidResult->id,
                'date' => date('Y-m-d', strtotime($request->date))
            ]);

            // 5. FIX THE CACHE CRASH: Get Session Year manually by school_id
            // Replace: $this->cache->getDefaultSessionYear()
            $sessionYear = DB::table('session_years')
                ->where('school_id', $request->school_id)
                ->where('default', 1)
                ->first();

            if (!$sessionYear) {
                // Fallback to current year if no default is marked
                $sessionYear = DB::table('session_years')
                    ->where('school_id', $request->school_id)
                    ->orderBy('id', 'desc')
                    ->first();
            }

            // 6. Audit Tracking
            $this->sessionYearsTrackingsService->storeSessionYearsTracking(
                'App\Models\CompulsoryFee',
                $feesPaidResult->id,
                $request->student_id,
                $sessionYear->id,
                $request->school_id,
                null
            );

            DB::commit();
            Log::info('IPN SUCCESS: Payment recorded for School: ' . $request->school_id);
        } catch (\Throwable $e) {
            DB::rollback();
            Log::error('IPN CRASH at ' . $e->getFile() . ':' . $e->getLine() . ' - ' . $e->getMessage());
        }
    }

}

