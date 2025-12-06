<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\Transportation\PickupPointRepositoryInterface;
use App\Repositories\SchoolSetting\SchoolSettingInterface;
use App\Repositories\PaymentTransaction\PaymentTransactionInterface;
use App\Repositories\Expense\ExpenseInterface;
use App\Repositories\SystemSetting\SystemSettingInterface;
use App\Repositories\Student\StudentInterface;
use Illuminate\Http\Request;
use App\Models\School;
use App\Models\TransportationFee;
use App\Models\RoutePickupPoint;
use App\Models\Shift;
use App\Models\RouteVehicle;
use App\Models\RouteVehicleHistory;
use App\Models\TransportationPayment;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Models\PaymentTransaction;
use App\Models\Leave;
use App\Models\Holiday;
use App\Models\TransportationAttendance;
use App\Models\TransportationRequest;
use Illuminate\Support\Facades\Config;
use App\Services\ResponseService;
use App\Services\SessionYearsTrackingsService;
use Illuminate\Support\Facades\Validator;
use App\Services\CachingService;
use App\Services\Payment\PaymentService;
use Auth;
use DB;
use Carbon\Carbon;
use function PHPUnit\Framework\isEmpty;

class TrasportationApiController extends Controller
{
    private PickupPointRepositoryInterface $pickupPoint;
    private SchoolSettingInterface $schoolSetting;
    private CachingService $cache;
    private PaymentTransactionInterface $paymentTransaction;
    private ExpenseInterface $expense;
    private SessionYearsTrackingsService $sessionYearsTrackingsService;
    private SystemSettingInterface $systemSetting;
    private StudentInterface $student;


    public function __construct(StudentInterface $student, PickupPointRepositoryInterface $pickupPoint, SchoolSettingInterface $schoolSetting, CachingService $cache, PaymentTransactionInterface $paymentTransaction, ExpenseInterface $expense, SessionYearsTrackingsService $sessionYearsTrackingsService, SystemSettingInterface $systemSetting)
    {
        $this->pickupPoint = $pickupPoint;
        $this->schoolSetting = $schoolSetting;
        $this->cache = $cache;
        $this->paymentTransaction = $paymentTransaction;
        $this->expense = $expense;
        $this->sessionYearsTrackingsService = $sessionYearsTrackingsService;
        $this->systemSetting = $systemSetting;
        $this->student = $student;
    }

    public function pickupPoints(Request $request)
    {
        try {

            if (Auth::user()->hasRole('Driver') || Auth::user()->hasRole('Helper')) {
                $route = RouteVehicle::where('driver_id', Auth::user()->id)->where('helper_id', Auth::user()->id)->get('route_id');
            }

            $pickupPoints = $this->pickupPoint->builder()->where('status', 1)->get();

            return ResponseService::successResponse("Pickup points fetched successfully", $pickupPoints);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            return ResponseService::errorResponse();
        }
    }
    public function transportation_fees(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'pickup_point_id' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $names = array('currency_code', 'currency_symbol', );

            $settings = $this->schoolSetting->getBulkData($names);
            $transportationFee = TransportationFee::select('id', 'duration', 'fee_amount')
                ->where('pickup_point_id', $request->pickup_point_id)
                ->get()
                ->map(function ($fee) use ($settings) {
                    $fee->formatted_fee_amount = $settings['currency_symbol'] . " " . $fee->fee_amount;
                    return $fee;
                });
            $accept_payments['status'] = true;

            return ResponseService::successResponse("Transportation fees fetched successfully", ['fees' => $transportationFee, 'take_payments' => $accept_payments]);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            return ResponseService::errorResponse();
        }
    }

    public function transportation_shifts(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'pickup_point_id' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $routeIds = RoutePickupPoint::where('pickup_point_id', $request->pickup_point_id)->pluck('route_id')->toArray();

            $shiftIds = RouteVehicle::with('route')->whereIn('route_id', $routeIds)->get()->pluck('route.shift_id')->unique()->toArray();

            $shifts = Shift::select(['id', 'name', 'start_time', 'end_time'])->whereIn('id', $shiftIds)->where('status', 1)->get();

            return ResponseService::successResponse("Transportation shifts fetched successfully", $shifts);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            return ResponseService::errorResponse();
        }
    }
    public function transportation_payments(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'pickup_point_id' => 'required|numeric',
            'user_id' => 'required|numeric|exists:users,id',
            'shift_id' => 'required|numeric|exists:shifts,id',
            'transportation_fee_id' => 'required|numeric|exists:transportation_fees,id',
            'payment_method' => 'required|in:Stripe,Razorpay,Flutterwave,Paystack',
            'change_route' => 'nullable|in:yes,no',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $today = Carbon::now();
            $school_code = $request->header('school-code');

            $school = School::on('mysql')->where('code', $school_code)->first();

            DB::beginTransaction();

            $schoolId = $school->id;
            $sessionYear = $this->cache->getDefaultSessionYear();
            $amount = TransportationFee::where('id', $request->transportation_fee_id)->value('fee_amount');
            $transportationFee = TransportationFee::where('id', $request->transportation_fee_id)->first();

            if ($request->change_route == 'yes') {
                $currentPlan = TransportationPayment::where('user_id', $request->user_id)->where('expiry_date', '>', $today)->first();
                if ($currentPlan) {
                    $currentPlan->update(['expiry_date' => $today]);
                }
            }

            if ($amount == 0) {
                $expiryDate = null;
                if ($transportationFee) {
                    if (!empty($transportationFee->duration)) {
                        $expiryDate = now()->addDays($transportationFee->duration);
                    }
                }
                $paymentTransactionData = $this->paymentTransaction->create([
                    'user_id' => $request->user_id,
                    'amount' => $amount,
                    'payment_gateway' => $request->payment_method,
                    'payment_status' => 'succeed',
                    'school_id' => $schoolId,
                    'order_id' => null,
                    'type' => 'transportation_fee'
                ]);

                $transportationPayment = TransportationPayment::create([
                    'shift_id' => $request->shift_id,
                    'pickup_point_id' => $request->pickup_point_id,
                    'user_id' => $request->user_id,
                    'transportation_fee_id' => $request->transportation_fee_id,
                    'amount' => $amount,
                    'paid_at' => $today,
                    'session_year_id' => $sessionYear->id,
                    'status' => 'paid',
                    'payment_transaction_id' => $paymentTransactionData->id,
                    'expiry_date' => $expiryDate,
                ]);
                DB::commit();
                ResponseService::successResponse("Request sent successfully", ["amount" => $amount]);
            }


            $paymentTransactionData = $this->paymentTransaction->create([
                'user_id' => $request->user_id,
                'amount' => $amount,
                'payment_gateway' => $request->payment_method,
                'payment_status' => 'Pending',
                'school_id' => $schoolId,
                'order_id' => null,
                'type' => 'transportation_fee'
            ]);

            $transportationPayment = TransportationPayment::create([
                'shift_id' => $request->shift_id,
                'pickup_point_id' => $request->pickup_point_id,
                'user_id' => $request->user_id,
                'transportation_fee_id' => $request->transportation_fee_id,
                'amount' => $amount,
                'paid_at' => null,
                'session_year_id' => $sessionYear->id,
                'status' => 'pending',
                'payment_transaction_id' => $paymentTransactionData->id,
            ]);

            $paymentIntent = PaymentService::create($request->payment_method, $schoolId)->createPaymentIntent(round($amount, 2), [
                'user_id' => Auth::user()->id,
                'name' => Auth::user()->full_name,
                'email' => Auth::user()->email,
                'mobile' => Auth::user()->mobile,
                'fees_id' => $request->transportation_fee_id,
                'student_id' => $request->user_id,
                'parent_id' => Auth::user()->id,
                'session_year_id' => $sessionYear->id,
                'payment_transaction_id' => $paymentTransactionData->id,
                'total_amount' => $amount,
                'advance_amount' => 0,
                'dueChargesAmount' => 0,
                'school_id' => $schoolId,
                'type' => 'transportation_fee',
                'fees_type' => 'transportation_fee',
                'is_fully_paid' => null,
            ]);

            if ($request->payment_method == "Flutterwave" || $request->payment_method == "Paystack") {
                $this->paymentTransaction->update($paymentTransactionData->id, ['order_id' => $paymentIntent['order_id'] ?? null, 'school_id' => $schoolId]);
                $paymentTransactionData = $this->paymentTransaction->findById($paymentTransactionData->id);
                DB::commit();

                \Log::info("Payment Intent:", ['payment_intent' => $paymentIntent]);

                // Return only the payment_link for Flutterwave
                if ($request->payment_method == "Flutterwave") {
                    ResponseService::successResponse("", [
                        "payment_link" => $paymentIntent['payment_link']
                    ]);
                } else {
                    ResponseService::successResponse("", [
                        "payment_link" => $paymentIntent['data']['authorization_url']
                    ]);
                }
            } else {
                $this->paymentTransaction->update($paymentTransactionData->id, ['order_id' => $paymentIntent->id, 'school_id' => $schoolId]);

                $paymentTransactionData = $this->paymentTransaction->findById($paymentTransactionData->id);
                // Custom Array to Show as response
                $paymentGatewayDetails = array(
                    ...$paymentIntent->toArray(),
                    'payment_transaction_id' => $paymentTransactionData->id,
                );
                DB::commit();
                ResponseService::successResponse("", ["payment_intent" => $paymentGatewayDetails, "payment_transaction" => $paymentTransactionData]);
            }
        } catch (\Throwable $th) {
            DB::rollBack();
            ResponseService::logErrorResponse($th);
            return ResponseService::errorResponse();
        }
    }
    public function transportation_requests(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'pickup_point_id' => 'required|numeric',
            'user_id' => 'required|numeric|exists:users,id',
            'transportation_fee_id' => 'required|numeric|exists:transportation_fees,id',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::beginTransaction();

            $sessionYear = $this->cache->getDefaultSessionYear();

            $transportationRequestData = TransportationRequest::create([
                'user_id' => $request->user_id,
                'pickup_point_id' => $request->pickup_point_id,
                'transportation_fee_id' => $request->transportation_fee_id,
                'session_year_id' => $sessionYear->id,
                'status' => 'Pending',
            ]);

            DB::commit();
            return ResponseService::successResponse("Transportation request stored successfully", $transportationRequestData);
        } catch (\Throwable $th) {
            DB::rollBack();
            ResponseService::logErrorResponse($th);
            return ResponseService::errorResponse();
        }
    }
    public function transportation_expense_create(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'vehicle_id' => 'required|integer|exists:vehicles,id',
            'category_id' => 'required|integer|exists:expense_categories,id',
            'title' => 'required',
            'ref_no' => 'nullable|numeric',
            'amount' => 'required|numeric|min:0',
            'date' => 'required|date',
            'image_pdf' => 'nullable|file|mimes:jpg,jpeg,png,gif,webp,pdf|max:4096'
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::beginTransaction();

            $sessionYear = $this->cache->getDefaultSessionYear();

            $data = [
                'vehicle_id' => $request->vehicle_id,
                'category_id' => $request->category_id,
                'title' => $request->title,
                'ref_no' => $request->ref_no,
                'amount' => $request->amount,
                'date' => date('Y-m-d', strtotime($request->date)),
                'description' => $request->description,
                'session_year_id' => $sessionYear->id,
                'file' => $request->file('image_pdf'),
                'created_by' => Auth::user()->id
            ];

            $expense = $this->expense->create($data);

            $this->sessionYearsTrackingsService->storeSessionYearsTracking('App\Models\Expense', $expense->id, Auth::user()->id, $sessionYear->id, Auth::user()->school_id, null);

            DB::commit();
            return ResponseService::successResponse("Expense stored successfully", $expense);
        } catch (\Throwable $th) {
            DB::rollBack();
            ResponseService::logErrorResponse($th);
            return ResponseService::errorResponse();
        }
    }
    public function transportation_expense_get(Request $request)
    {
        // $validator = Validator::make($request->all(), [
        //     'vehicle_id' => 'required|integer|exists:vehicles,id',
        // ]);

        // if ($validator->fails()) {
        //     ResponseService::validationError($validator->errors()->first());
        // }

        try {

            if (!(Auth::user()->hasRole('Driver') || Auth::user()->hasRole('Helper'))) {
                return ResponseService::errorResponse('Unauthorised');
            }


            $expenses = $this->expense->builder()->with('category', 'vehicle', 'created_by', 'sessionYear')
                ->whereNotNull("vehicle_id")
                ->where('created_by', Auth::user()->id)
                ->get();

            $schoolSettings = $this->schoolSetting->getBulkData(['currency_code', 'currency_symbol']);

            $data = [];

            if ($expenses->isNotEmpty()) {
                foreach ($expenses as $expense) {
                    $data[] = [
                        'vehicle' => [
                            'name' => $expense->vehicle->name,
                            'vehicle_number' => $expense->vehicle->vehicle_number,
                        ],
                        'category' => [
                            'id' => $expense->category->id,
                            'name' => $expense->category->name,
                        ],
                        'expense' => [
                            'title' => $expense->title,
                            'ref_no' => $expense->ref_no,
                            'currency_code' => $schoolSettings['currency_code'] ?? '',
                            'currency_symbol' => $schoolSettings['currency_symbol'] ?? '',
                            'amount' => $expense->amount,
                            'date' => explode(' ', $expense->date)[0],
                            'description' => $expense->description,
                            'file' => $expense->file,
                        ],
                        'session_year' => [
                            'id' => $expense->sessionYear->id,
                            'name' => $expense->sessionYear->name,
                        ],
                        'created_by' => [
                            'id' => $expense->created_by,
                            'name' => $expense->creator->full_name,
                            'avtar' => $expense->creator->image,
                        ],
                    ];
                }
                return ResponseService::successResponse("Expense fetched successfully", $data);
            } else {
                return ResponseService::errorResponse('No expense created by this user');
            }

        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            return ResponseService::errorResponse();
        }
    }

    public function getTransportationExpenseCategory(Request $request)
    {
        try {
            $data = [];

            if (!(Auth::user()->hasRole('Driver') || Auth::user()->hasRole('Helper'))) {
                return ResponseService::errorResponse('Unauthorised');
            }

            $categories = ExpenseCategory::get(['id', 'name', 'description']);

            $data = $categories;


            return ResponseService::successResponse("Expense categories fetched successfully", $data);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            return ResponseService::errorResponse();
        }
    }
    public function getTransportationData(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'pickup_drop' => 'required|in:0,1'
        ], [
            'user_id.required' => 'Please select a user.',
            'user_id.exists' => 'Selected user does not exist.',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $today = Carbon::now();
            $data = [];

            $transportationPayments = TransportationPayment::with([
                'pickupPoint',
                'user',
                'paymentTransaction',
                'transportationFee',
                'routeVehicle',
                'routeVehicle.route',
                'routeVehicle.route.routePickupPoints',
                'routeVehicle.vehicle',
                'routeVehicle.driver',
                'routeVehicle.helper'
            ])
                ->whereNotNull('route_vehicle_id')
                ->where('user_id', $request->user_id)
                ->get();

            $transportationPayment = $transportationPayments->sortByDesc('created_at')->first();
            if (!$transportationPayment) {
                return ResponseService::successResponse("No plan found", ["status" => "No plan found"]);
            }

            // Determine payment type
            $requestType = 'online';
            if (
                $transportationPayment->payment_transaction_id == null ||
                in_array(optional($transportationPayment->paymentTransaction)->payment_gateway, ['cash', 'cheque'])
            ) {
                $requestType = 'offline';
            }

            // Check status
            $status = $transportationPayment->expiry_date < $today ? 'expired' : 'active';

            // Fetch current active routeVehicleHistory
            $routeVehicleHistory = null;
            if ($status === 'active') {
                $routeVehicleHistory = RouteVehicleHistory::with(['route', 'route.routePickupPoints', 'route.routePickupPoints.pickupPoint'])
                    ->where('route_id', $transportationPayment->routeVehicle->route_id)
                    ->where('vehicle_id', $transportationPayment->routeVehicle->vehicle_id)
                    ->where('driver_id', $transportationPayment->routeVehicle->driver_id)
                    ->where('helper_id', $transportationPayment->routeVehicle->helper_id)
                    ->where('shift_id', $transportationPayment->shift_id)
                    ->where('status', 'inprogress')
                    ->latest()
                    ->first();
            }

            // Today attendance
            $today_date = date('Y-m-d');
            $attendances = TransportationAttendance::where('user_id', $request->user_id)
                ->where('date', $today_date)
                ->get();

            $today_attendance = [];
            if ($attendances->isNotEmpty()) {
                foreach ($attendances as $attendance) {
                    $attendance_status = $attendance->status === 'present' ? 'P' : 'A';
                    $trip_type = $attendance->pickup_drop == 0 ? 'pickup' : 'drop';
                    $today_attendance[] = [
                        'status' => $attendance_status,
                        'trip_type' => $trip_type,
                    ];
                }
            } else {
                $today_attendance[] = "No attendance found for today";
            }

            // Live summary
            if ($routeVehicleHistory) {
                $pickupPoints = $routeVehicleHistory->route->routePickupPoints;

                // Fetch all active pickup point IDs once
                $activePickupPointIds = TransportationPayment::whereNotNull('route_vehicle_id')
                    ->where('expiry_date', '>=', now())
                    ->pluck('pickup_point_id')
                    ->unique()
                    ->toArray();

                // Filter out pickup points with no users
                $pickupPoints = $pickupPoints->filter(fn($p) => in_array($p->pickup_point_id, $activePickupPointIds));

                if ($routeVehicleHistory->type === 'pickup') {
                    $pickupPoints = $pickupPoints->sortBy(function ($point) {
                        return Carbon::parse($point->pickup_time);
                    })->values();
                } else {
                    $pickupPoints = $pickupPoints->sortBy(function ($point) {
                        return Carbon::parse($point->drop_time);
                    })->values();
                }

                $delay_status = null;
                $minutesDiff = Carbon::parse($routeVehicleHistory->start_time)
                    ->diffInMinutes(Carbon::parse($routeVehicleHistory->actual_start_time));

                $routePickupPoints = $pickupPoints->map(function ($point) use ($minutesDiff) {
                    $pickupDiff = $dropDiff = null;

                    if ($point->pickup_time && $minutesDiff) {
                        $pickupDiff = Carbon::parse($point->pickup_time)->addMinutes($minutesDiff);
                    }

                    if ($point->drop_time && $minutesDiff) {
                        $dropDiff = Carbon::parse($point->drop_time)->addMinutes($minutesDiff);
                    }

                    $point->pickup_diff_minutes = $pickupDiff;
                    $point->drop_diff_minutes = $dropDiff;

                    return $point;
                })->values();

                // Delay status
                if ($routeVehicleHistory->actual_start_time > $routeVehicleHistory->start_time) {
                    $delay_status = 'delayed';
                } elseif ($routeVehicleHistory->actual_start_time < $routeVehicleHistory->start_time) {
                    $delay_status = 'ahead_of_time';
                } else {
                    $delay_status = 'on_time';
                }

                // ETA logic
                if ($routeVehicleHistory->type == 'pickup') {
                    $routePickupPoints = $routePickupPoints->sortBy(fn($p) => $p->pickup_time)->values();
                    $userRoutePickupPoint = $routePickupPoints->first(function ($item) use ($transportationPayment) {
                        return $item->pickup_point_id == $transportationPayment->pickup_point_id &&
                            $item->route_id == $transportationPayment->routeVehicle->route->id;
                    });

                    if ($userRoutePickupPoint) {
                        $estimated_time = $userRoutePickupPoint->pickup_diff_minutes;
                        if ($routeVehicleHistory->last_pickup_point_id) {
                            $estimated_time_diff = Carbon::parse($estimated_time)->diffInMinutes(
                                Carbon::parse(date('H:i:s', strtotime($routeVehicleHistory->updated_at))),
                                false
                            );
                            $estimated_time = Carbon::parse($estimated_time)->addMinutes($estimated_time_diff);
                        }
                        $minutesDiffToLocation = Carbon::parse(date('H:i:s'))->diffInMinutes($estimated_time, false) + 1;
                        if ($minutesDiffToLocation < 0)
                            $minutesDiffToLocation = "Delayed";
                    } else {
                        $estimated_time = $transportationPayment->routeVehicle->route->routePickupPoints->first()->pickup_time;
                        $minutesDiffToLocation = Carbon::parse(date('H:i:s'))->diffInMinutes($estimated_time, false) + 1;
                    }

                    if (
                        isset($userRoutePickupPoint) &&
                        $routeVehicleHistory->last_pickup_point_id == $userRoutePickupPoint->pickup_point_id
                    ) {
                        $estimated_time = null;
                        $minutesDiffToLocation = null;
                    }
                } else {
                    $routePickupPoints = $routePickupPoints->sortByDesc(fn($p) => $p->drop_time)->values();
                    $userRoutePickupPoint = $routePickupPoints->first(function ($item) use ($transportationPayment) {
                        return $item->pickup_point_id == $transportationPayment->pickup_point_id &&
                            $item->route_id == $transportationPayment->routeVehicle->route->id;
                    });

                    if ($userRoutePickupPoint) {
                        $estimated_time = $userRoutePickupPoint->drop_diff_minutes;
                        $minutesDiffToLocation = Carbon::parse(date('H:i:s'))->diffInMinutes($estimated_time, false) + 1;
                        if ($minutesDiffToLocation < 0)
                            $minutesDiffToLocation = "Delayed";
                    } else {
                        $estimated_time = $transportationPayment->routeVehicle->route->routePickupPoints->first()->drop_time;
                        $minutesDiffToLocation = Carbon::parse(date('H:i:s'))->diffInMinutes($estimated_time, false) + 1;
                    }

                    if (
                        isset($userRoutePickupPoint) &&
                        $routeVehicleHistory->last_pickup_point_id == $userRoutePickupPoint->pickup_point_id
                    ) {
                        $estimated_time = null;
                        $minutesDiffToLocation = null;
                    }
                }

                // Current & next location
                if ($routeVehicleHistory->last_pickup_point_id) {
                    $current_index = $pickupPoints->search(fn($p) => $p->pickupPoint->id === $routeVehicleHistory->last_pickup_point_id);
                    if ($current_index !== false) {
                        $current_location_point = $pickupPoints[$current_index];
                        $current_location = $current_location_point->pickupPoint->name;

                        if (isset($pickupPoints[$current_index + 1])) {
                            $next_location = $pickupPoints[$current_index + 1]->pickupPoint->name;
                        } else {
                            $next_location = 'School';
                        }
                    }
                } else {
                    $next_location_point = $pickupPoints->first();
                    $current_location = "School";
                    $next_location = $next_location_point->pickupPoint->name ?? "N/A";
                }

                $live_summary = [
                    'status' => $delay_status,
                    'current_location' => $current_location ?? 'Unknown',
                    'eta_to_user_stop_min' => $minutesDiffToLocation ?: "Reached",
                    'next_location' => $next_location ?? 'Unknown',
                    'estimated_time' => $estimated_time ? Carbon::parse($estimated_time)->format('h:i A') : "Reached",
                ];
            } else {
                $live_summary = 'No on-going trip found';
            }

            // Build response data
            $differenceInDays = now()->diffInDays($transportationPayment->expiry_date, false);
            $differenceInDaysForStaff = !$transportationPayment->transportationFee
                ? Carbon::parse($transportationPayment->paid_at)->diffInDays($transportationPayment->expiry_date, false)
                : null;

            $data = [
                'plan' => [
                    'plan_id' => $transportationPayment->id,
                    'status' => $status,
                    'request_type' => $requestType,
                    'duration' => isset($transportationPayment->transportationFee->duration)
                        ? $transportationPayment->transportationFee->duration . " Days"
                        : $differenceInDaysForStaff . " Days",
                    'valid_from' => date("d F Y", strtotime($transportationPayment->paid_at)),
                    'valid_to' => date("d F Y", strtotime($transportationPayment->expiry_date)),
                    'route' => [
                        'id' => $transportationPayment->routeVehicle->route->id,
                        'name' => $transportationPayment->routeVehicle->route->name
                    ],
                    'pickup_stop' => [
                        'id' => $transportationPayment->pickupPoint->id,
                        'name' => $transportationPayment->pickupPoint->name,
                        'pickup_time' => date("h:i A", strtotime($transportationPayment->routeVehicle->route->routePickupPoints
                            ->where('pickup_point_id', $transportationPayment->pickup_point_id)
                            ->first()->pickup_time)),
                        'drop_time' => date("h:i A", strtotime($transportationPayment->routeVehicle->route->routePickupPoints
                            ->where('pickup_point_id', $transportationPayment->pickup_point_id)
                            ->first()->drop_time))
                    ],
                    'expires_in_days' => $differenceInDays,
                ],
                'bus_info' => [
                    'vehicle_id' => $transportationPayment->routeVehicle->vehicle->id,
                    'vehicle_name' => $transportationPayment->routeVehicle->vehicle->name,
                    'registration' => $transportationPayment->routeVehicle->vehicle->vehicle_number,
                    'driver' => [
                        'id' => $transportationPayment->routeVehicle->driver->id,
                        'name' => $transportationPayment->routeVehicle->driver->full_name,
                        'phone' => $transportationPayment->routeVehicle->driver->mobile,
                        'email' => $transportationPayment->routeVehicle->driver->email,
                        'avtar' => $transportationPayment->routeVehicle->driver->image,
                    ],
                ],
                'live_summary' => $live_summary,
                'today_attendance' => $today_attendance,
            ];

            if ($transportationPayment->routeVehicle->helper) {
                $data['bus_info']['attender'] = [
                    'id' => $transportationPayment->routeVehicle->helper->id,
                    'name' => $transportationPayment->routeVehicle->helper->full_name,
                    'phone' => $transportationPayment->routeVehicle->helper->mobile,
                    'email' => $transportationPayment->routeVehicle->helper->email,
                    'avtar' => $transportationPayment->routeVehicle->helper->image,
                ];
            }

            return ResponseService::successResponse("Home screen data fetched successfully", $data);

        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            return ResponseService::errorResponse();
        }
    }

    public function getTransportationRequests(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id'
        ], [
            'user_id.required' => 'Please select a user.',
            'user_id.exists' => 'Selected user does not exist.',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {

            $transportationPayments = TransportationPayment::with([
                'pickupPoint',
                'routeVehicle.route',
                'user',
                'paymentTransaction',
                'transportationFee',
            ])
                ->where('user_id', $request->user_id)
                ->where('status', 'paid')
                ->orderBy("id", "DESC")
                ->get();

            $settings = $this->schoolSetting->getBulkData(array('school_email', 'school_phone'));

            $today = Carbon::now();
            $data = [];
            foreach ($transportationPayments as $transportationPayment) {
                if ($transportationPayment->route_vehicle_id != null && $transportationPayment->expiry_date > $today) {
                    $transportationPayment->status = "accepted";
                } else if ($transportationPayment->route_vehicle_id == null && $transportationPayment->expiry_date > $today) {
                    $transportationPayment->status = "pending";
                } else if ($transportationPayment->route_vehicle_id != null && $transportationPayment->expiry_date <= $today) {
                    $transportationPayment->status = "expired";
                }
                $requestType = 'online';
                if ($transportationPayment->route_vehicle_id != null) {
                    if ($transportationPayment->paymentTransaction->payment_gateway == 'cash' || $transportationPayment->paymentTransaction->payment_gateway == 'cheque') {
                        $requestType = 'offline';
                    } else {
                        $requestType = 'online';
                    }
                }

                $data[] = [
                    "id" => $transportationPayment->id,
                    "status" => $transportationPayment->status,
                    "request_type" => $requestType,
                    "requested_on" => date("Y-m-d", strtotime($transportationPayment->created_at)),
                    "requested_by" => [
                        "student_id" => $transportationPayment->user_id,
                        "name" => $transportationPayment->user->full_name,
                    ],
                    "details" => [
                        "pickup_stop" => [
                            "id" => $transportationPayment->pickup_point_id,
                            "name" => $transportationPayment->pickupPoint->name,
                        ],
                        "plan" => [
                            "duration" => $transportationPayment->transportationFee->duration . " Days",
                            "validity" => (!empty($transportationPayment->paid_at) && !empty($transportationPayment->expiry_date))
                                ? date("Y-m-d", strtotime($transportationPayment->paid_at)) . " - " . date("Y-m-d", strtotime($transportationPayment->expiry_date))
                                : "N/A",
                        ],
                        "route" => $transportationPayment->routeVehicle ? [
                            'id' => $transportationPayment->routeVehicle->route->id,
                            'name' => $transportationPayment->routeVehicle->route->name,
                        ] : null,
                    ],
                    "review" => [
                        "responded_on" => date("Y-m-d", strtotime($transportationPayment->updated_at)),
                    ],
                    "mode" => $transportationPayment->paymentTransaction->payment_gateway == 'cash' ? 'cash' : 'online',
                    "contact_details" => [
                        'school_email' => $settings['school_email'],
                        'school_phone' => $settings['school_phone'],
                    ],
                ];
            }

            return ResponseService::successResponse("Transportation requests fetched successfully", $data);
        } catch (\Throwable $th) {

            ResponseService::logErrorResponse($th);
            return ResponseService::errorResponse();
        }
    }

    public function pickupPointsTrack(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
        ], [
            'user_id.required' => 'Please select a user.',
            'user_id.exists' => 'Selected user does not exist.',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {

            $today = Carbon::now();
            $transportationPayment = TransportationPayment::with([
                'pickupPoint',
                'routeVehicle',
                'routeVehicle.route',
                'routeVehicle.route.pickupPoints',
                'routeVehicle.vehicle'
            ])
                ->whereNotNull('route_vehicle_id')
                ->where('user_id', $request->user_id)
                ->where('expiry_date', '>', $today)
                ->first();

            if (!$transportationPayment) {
                $data = "Plan expired";
                return ResponseService::successResponse("Live route data fetched successfully", $data);
            }

            $routeVehicles = RouteVehicle::with(['vehicle', 'route', 'route.shift', 'route.routePickupPoints', 'route.routePickupPoints.pickupPoint'])
                ->where('id', $transportationPayment->route_vehicle_id)
                ->where('status', 1)->first();

            $driver_id = $routeVehicles->driver_id;
            $helper_id = $routeVehicles->helper_id;

            $routeVehicleHistory = RouteVehicleHistory::with(['vehicle', 'route', 'route.routePickupPoints', 'route.routePickupPoints.pickupPoint', 'shift'])
                ->where('driver_id', $driver_id)
                ->where('helper_id', $helper_id)
                ->where('shift_id', $routeVehicles->route->shift_id)
                ->where('route_id', $routeVehicles->route_id)
                ->where('vehicle_id', $routeVehicles->vehicle_id)
                ->where('status', 'inprogress')
                ->first();


            if ($routeVehicleHistory) {
                $pickupPoints = $routeVehicleHistory->route->routePickupPoints;
                // sort based on pickup_drop
                if ($routeVehicleHistory->type == 'pickup') {
                    // ascending order
                    $pickupPoints = $pickupPoints->sortBy(fn($p) => $p->pickup_time)->values();
                } else {
                    // descending order
                    $pickupPoints = $pickupPoints->sortBy(fn($p) => $p->drop_time)->values();
                }

                $stops = [];
                $minutesDiff = Carbon::parse($routeVehicleHistory->start_time)
                    ->diffInMinutes(Carbon::parse($routeVehicleHistory->actual_start_time), false);

                $routePickupPoints = $pickupPoints->map(function ($point) use ($minutesDiff) {
                    // Calculate pickup difference
                    $pickupDiff = null;
                    if ($point->pickup_time && $minutesDiff) {
                        $pickupDiff = Carbon::parse($point->pickup_time)
                            ->addMinutes($minutesDiff);
                    }

                    // Calculate drop difference
                    $dropDiff = null;
                    if ($point->drop_time && $minutesDiff) {
                        $dropDiff = Carbon::parse($point->drop_time)
                            ->addMinutes($minutesDiff);
                    }

                    // Add attributes to point model
                    $point->pickup_diff_minutes = $pickupDiff;
                    $point->drop_diff_minutes = $dropDiff;

                    return $point;
                });
                $users = TransportationPayment::with(['user', 'user.roles', 'pickupPoint', 'shift'])
                    ->where('route_vehicle_id', $routeVehicleHistory->route->routeVehicle->first()->id)
                    ->where('shift_id', $routeVehicleHistory->shift_id)
                    ->where('expiry_date', '>', $today)
                    ->get();

                if ($routeVehicleHistory->created_at < $users->max('created_at')) {
                    $users = $users->filter(function ($user) use ($routeVehicleHistory) {
                        return $user->created_at < $routeVehicleHistory->created_at;
                    })->values();
                }

                $transportationAttendance = TransportationAttendance::where('date', date("Y-m-d", strtotime($today)))
                    ->where('route_vehicle_id', $routeVehicleHistory->route->routeVehicle->first()->id)
                    ->where('shift_id', $routeVehicleHistory->shift_id)
                    ->get();

                $stops[] = [
                    'name' => "School",
                    'scheduled_time' => date('h:i A', strtotime($routeVehicleHistory->start_time)),
                    'actual_time' => date('h:i A', strtotime($routeVehicleHistory->actual_start_time)),
                ];
                foreach ($routePickupPoints as $pickupPoint) {
                    if ($routeVehicleHistory->type == 'drop') {
                        $time = $pickupPoint->drop_time;
                        $estimated_time = $pickupPoint->drop_diff_minutes;
                        if ($routeVehicleHistory->last_pickup_point_id) {
                            $actual_time = null;
                            $attendance = $transportationAttendance
                                ->where('pickup_point_id', $pickupPoint->pickup_point_id)
                                ->sortByDesc('created_at')
                                ->first();
                            $actual_time = $attendance ? date('h:i A', strtotime($attendance->created_at)) : null;
                            $lastPickupPoint = $routePickupPoints->firstWhere('pickup_point_id', $routeVehicleHistory->last_pickup_point_id);
                            $lastEstimated_time = $lastPickupPoint->drop_diff_minutes;
                            $estimated_time_diff = Carbon::parse($lastEstimated_time)->diffInMinutes(Carbon::parse(date('H:i:s', strtotime($routeVehicleHistory->updated_at))), false);
                            if ($estimated_time_diff < 0) {
                                $estimated_time_diff = $estimated_time_diff - 1;
                            }
                            $estimated_time = Carbon::parse($estimated_time)->addMinutes($estimated_time_diff);
                            if ($actual_time) {
                                $estimated_time = null;
                            }
                        }
                        $userRoutePickupPoint = $routePickupPoints->first(function ($item) use ($transportationPayment) {
                            return $item->pickup_point_id == $transportationPayment->pickup_point_id
                                && $item->route_id == $transportationPayment->routeVehicle->route->id; // example of extra condition
                        });
                        if ($userRoutePickupPoint) {
                            $user_estimated_time = $userRoutePickupPoint->drop_diff_minutes;
                            $minutesDiffToLocation = Carbon::parse(date('H:i:s'))->diffInMinutes($user_estimated_time, false) + 1;
                            if ($minutesDiffToLocation < 0) {
                                $minutesDiffToLocation = "Delayed";
                            }
                        } else {
                            $user_estimated_time = $transportationPayment->routeVehicle->route->routePickupPoints->first()->drop_time;
                            $minutesDiffToLocation = Carbon::parse(date('H:i:s'))->diffInMinutes($user_estimated_time, false) + 1;
                        }
                        if ($routeVehicleHistory->last_pickup_point_id == $userRoutePickupPoint->pickup_point_id) {
                            $user_estimated_time = null;
                            $minutesDiffToLocation = null;
                        }
                    } else {
                        $time = $pickupPoint->pickup_time;
                        $estimated_time = $pickupPoint->pickup_diff_minutes;
                        if ($routeVehicleHistory->last_pickup_point_id) {
                            $actual_time = null;
                            $attendance = $transportationAttendance
                                ->where('pickup_point_id', $pickupPoint->pickup_point_id)
                                ->sortByDesc('created_at')
                                ->first();
                            $actual_time = $attendance ? date('h:i A', strtotime($attendance->created_at)) : null;
                            $lastPickupPoint = $routePickupPoints->firstWhere('pickup_point_id', $routeVehicleHistory->last_pickup_point_id);
                            $lastEstimated_time = $lastPickupPoint->pickup_diff_minutes;
                            $estimated_time_diff = Carbon::parse($lastEstimated_time)->diffInMinutes(Carbon::parse(date('H:i:s', strtotime($routeVehicleHistory->updated_at))), false);
                            if ($estimated_time_diff < 0) {
                                $estimated_time_diff = $estimated_time_diff - 1;
                            }
                            $estimated_time = Carbon::parse($estimated_time)->addMinutes($estimated_time_diff);
                            if ($actual_time) {
                                $estimated_time = null;
                            }
                        }
                        $userRoutePickupPoint = $routePickupPoints->first(function ($item) use ($transportationPayment) {
                            return $item->pickup_point_id == $transportationPayment->pickup_point_id
                                && $item->route_id == $transportationPayment->routeVehicle->route->id; // example of extra condition
                        });
                        if ($userRoutePickupPoint) {
                            $user_estimated_time = $userRoutePickupPoint->pickup_diff_minutes;
                            if ($routeVehicleHistory->last_pickup_point_id) {
                                $user_estimated_time_diff = Carbon::parse($user_estimated_time)->diffInMinutes(Carbon::parse(date('H:i:s', strtotime($routeVehicleHistory->updated_at))), false);
                                $user_estimated_time = Carbon::parse($user_estimated_time)->addMinutes($user_estimated_time_diff);
                            }
                            $minutesDiffToLocation = Carbon::parse(date('H:i:s'))->diffInMinutes($user_estimated_time, false) + 1;
                            if ($minutesDiffToLocation < 0) {
                                $minutesDiffToLocation = "Delayed";
                            }
                        } else {
                            $user_estimated_time = $transportationPayment->routeVehicle->route->routePickupPoints->first()->pickup_time;
                            $minutesDiffToLocation = Carbon::parse(date('H:i:s'))->diffInMinutes($user_estimated_time, false) + 1;
                        }
                        if ($routeVehicleHistory->last_pickup_point_id == $userRoutePickupPoint->pickup_point_id) {
                            $user_estimated_time = null;
                            $minutesDiffToLocation = null;
                        }
                    }
                    if ($users->where('pickup_point_id', $pickupPoint->pickup_point_id)->values()->isNotEmpty()) {
                        $stops[] = [
                            'id' => $pickupPoint->pickup_point_id,
                            'name' => $pickupPoint->pickupPoint->name,
                            'scheduled_time' => date('h:i A', strtotime($time)),
                            'estimated_time' => $estimated_time ? date('h:i A', strtotime($estimated_time)) : "Reached",
                            'actual_time' => $actual_time ?? "Pending",
                            'passengers' => $users->where('pickup_point_id', $pickupPoint->pickup_point_id)->values()->map(function ($user) {
                                return [
                                    'id' => $user->user->id,
                                    'name' => $user->user->full_name,
                                    'role' => $user->user->role,
                                ];
                            }),
                        ];
                    }
                }
                $stops[] = [
                    'name' => "School",
                    'scheduled_time' => date('h:i A', strtotime($routeVehicleHistory->end_time)),
                    'actual_time' => $routeVehicleHistory->actual_end_time ? date('h:i A', strtotime($routeVehicleHistory->actual_end_time)) : "Pending",
                ];

                $data[] = [
                    'trip_id' => $routeVehicleHistory->id,
                    'eta_to_user_stop_min' => $minutesDiffToLocation ? $minutesDiffToLocation : "Reached",
                    'status' => $routeVehicleHistory->status,
                    'vehicle' => [
                        'name' => $routeVehicleHistory->vehicle->name,
                        'number' => $routeVehicleHistory->vehicle->vehicle_number,
                    ],
                    'shift_time' => [
                        'label' => (function ($start) {
                            $hour = date('H', strtotime($start)); // 24-hour format
        
                            if ($hour >= 5 && $hour < 12) {
                                return 'Morning';
                            } elseif ($hour >= 12 && $hour < 17) {
                                return 'Noon';
                            } elseif ($hour >= 17 && $hour < 21) {
                                return 'Evening';
                            } else {
                                return 'Night';
                            }
                        })($routeVehicleHistory->start_time),
                        'from' => date('h:i A', strtotime($routeVehicleHistory->start_time)),
                        'to' => date('h:i A', strtotime($routeVehicleHistory->end_time)),
                    ],
                    'route' => [
                        'id' => $routeVehicleHistory->route->id,
                        'name' => $routeVehicleHistory->route->name,
                    ],
                    'stops' => $stops,
                    'type' => $routeVehicleHistory->type,
                    'last_reached_stop' => [
                        'id' => $routeVehicleHistory->last_pickup_point_id ? optional($routeVehicleHistory->route->routePickupPoints->where('pickup_point_id', $routeVehicleHistory->last_pickup_point_id)->first())->pickupPoint->id : null,
                        'name' => $routeVehicleHistory->last_pickup_point_id ? optional($routeVehicleHistory->route->routePickupPoints->where('pickup_point_id', $routeVehicleHistory->last_pickup_point_id)->first())->pickupPoint->name : null
                    ],
                ];
                return ResponseService::successResponse("Live route data fetched successfully", $data);
            } else {
                $data = "No on-going trip found";
                return ResponseService::successResponse("Live route data fetched successfully", $data);
            }


        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            return ResponseService::errorResponse();
        }
    }

    public function getvehicleAssignmentstatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
        ], [
            'user_id.required' => 'Please select a user.',
            'user_id.exists' => 'Selected user does not exist.',
        ]);
        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {

            $today = Carbon::now();

            // Get the latest transportation payment for the user
            $transportationPayment = TransportationPayment::where('user_id', $request->user_id)
                ->whereNotNull('expiry_date')
                ->orderByDesc('id')
                ->first();

            if (!$transportationPayment) {
                return ResponseService::successResponse("No request found", "false");
            }

            // Check expiry
            if ($transportationPayment->expiry_date < $today) {
                return ResponseService::successResponse("Plan expired", "expired");
            }

            // Check vehicle assignment
            if ($transportationPayment->route_vehicle_id === null) {
                return ResponseService::successResponse("Vehicle assignment pending", "pending");
            }

            return ResponseService::successResponse("Vehicle assigned", "assigned");

        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            return ResponseService::errorResponse();
        }
    }

    public function getTransoprtationCurrentPlan(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
        ], [
            'user_id.required' => 'Please select a user.',
            'user_id.exists' => 'Selected user does not exist.',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $today = Carbon::now();
            $names = ['currency_code', 'currency_symbol'];
            $settings = $this->schoolSetting->getBulkData($names);

            $activePlan = TransportationPayment::with([
                'pickupPoint',
                'paymentTransaction',
                'shift',
                'transportationFee',
                'routeVehicle.route.routePickupPoints'
            ])
                ->where('user_id', $request->user_id)
                ->where('paid_at', '<=', $today)
                ->where('expiry_date', '>=', $today)
                ->first();

            if (!$activePlan) {
                $transportationPayment = TransportationPayment::with(['routeVehicle.route', 'pickupPoint', 'transportationFee'])->where('user_id', $request->user_id)->whereNotNull('route_vehicle_id')->orderByDesc('id')->first();
                if ($transportationPayment) {
                    $old_plan_details = [
                        'route' => [
                            'id' => optional($transportationPayment->routeVehicle->route)->id,
                            'name' => optional($transportationPayment->routeVehicle->route)->name,
                        ],
                        'pickup_point' => [
                            'id' => optional($transportationPayment->pickupPoint)->id,
                            'name' => optional($transportationPayment->pickupPoint)->name,
                        ],
                        'fees' => [
                            'id' => optional($transportationPayment->transportationFee)->id,
                            'duration' => optional($transportationPayment->transportationFee)->duration . " Days",
                            'total_fee' => ($settings['currency_symbol'] ?? '') . " " . optional($transportationPayment->transportationFee)->fee_amount,
                        ],
                        'validity' => explode(' ', $transportationPayment->paid_at)[0] . " to " . $transportationPayment->expiry_date,
                        'shift_id' => $transportationPayment->shift_id,
                    ];
                    return ResponseService::successResponse("Plan expired. Old plan details fetched", $old_plan_details);
                } else {
                    return ResponseService::successResponse("No active or expired plan found");
                }
            }

            $userPickupPointId = optional($activePlan->pickupPoint)->id;
            $pickupPoint = $activePlan->routeVehicle->route->routePickupPoints
                ->where('pickup_point_id', $userPickupPointId)
                ->first();

            $data = [
                'payment_id' => $activePlan->id,
                'shift_id' => $activePlan->shift_id,
                'duration' => optional($activePlan->transportationFee)->duration . " Days",
                'valid_from' => date("d F Y", strtotime($activePlan->paid_at)),
                'valid_to' => date("d F Y", strtotime($activePlan->expiry_date)),
                'total_fee' => ($settings['currency_symbol'] ?? '') . " " . optional($activePlan->transportationFee)->fee_amount,
                'payment_mode' => optional($activePlan->paymentTransaction)->payment_gateway,
                'route' => [
                    'id' => optional($activePlan->routeVehicle->route)->id,
                    'name' => optional($activePlan->routeVehicle->route)->name,
                ],
                'shift' => [
                    'name' => optional($activePlan->shift)->name,
                    'time_window' => (optional($activePlan->shift)->start_time && optional($activePlan->shift)->end_time)
                        ? date("h:i A", strtotime($activePlan->shift->start_time)) . " - " . date("h:i A", strtotime($activePlan->shift->end_time))
                        : null,
                ],
                'pickup_stop' => [
                    'id' => optional($activePlan->pickupPoint)->id,
                    'name' => optional($activePlan->pickupPoint)->name,
                ],
                'estimated_pickup_time' => $pickupPoint ? date('h:i A', strtotime($pickupPoint->pickup_time)) : null,
                'vehicle_id' => optional($activePlan->routeVehicle)->vehicle_id,
            ];

            return ResponseService::successResponse("Plan fetched successfully", $data);

        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            return ResponseService::errorResponse();
        }
    }
    public function getTransoprtationRouteForUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
        ], [
            'user_id.required' => 'Please select a user.',
            'user_id.exists' => 'Selected user does not exist.',
        ]);
        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {

            $today = Carbon::now();
            $activePlan = TransportationPayment::with([
                'pickupPoint',
                'paymentTransaction',
                'shift',
                'transportationFee',
                'routeVehicle.route',
                'routeVehicle.vehicle',
                'routeVehicle.route.routePickupPoints',
                'routeVehicle.route.routePickupPoints.pickupPoint'
            ])
                ->where('user_id', $request->user_id)
                ->where('paid_at', '<=', $today)
                ->where('expiry_date', '>=', $today)
                ->first();

            $userPickupPointId = $activePlan->pickupPoint->id;
            $pickupPoint = $activePlan->routeVehicle->route->routePickupPoints
                ->where('pickup_point_id', $userPickupPointId)
                ->first();

            foreach ($activePlan->routeVehicle->route->routePickupPoints as $pickupPoint) {
                $is_user_stop = false;
                if ($activePlan->pickup_point_id == $pickupPoint->pickup_point_id) {
                    $is_user_stop = true;
                }
                $stops[] = [
                    'id' => $pickupPoint->pickup_point_id,
                    'name' => $pickupPoint->pickupPoint->name,
                    'scheduled_time' => date('h:i A', strtotime($pickupPoint->pickup_time)),
                    'is_user_stop' => $is_user_stop
                ];
            }

            $data = [
                'route' => [
                    'id' => $activePlan->routeVehicle->route->id,
                    'name' => $activePlan->routeVehicle->route->name,
                    'vehicle_name' => $activePlan->routeVehicle->vehicle->name,
                    'vehicle_registration' => $activePlan->routeVehicle->vehicle->vehicle_number
                ],
                'stops' => $stops,
            ];

            return ResponseService::successResponse("Stops fetched successfully", $data);
        } catch (\Throwable $th) {
            DB::rollBack();
            ResponseService::logErrorResponse($th);
            return ResponseService::errorResponse();
        }
    }
    public function getTransoprtationAttendanceUsers(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'shift_id' => 'required|integer|exists:shifts,id',
            'pickup_point_id' => 'required|integer|exists:pickup_points,id',
        ], [
            'shift_id.required' => 'Please select a shift.',
            'shift_id.exists' => 'Selected shift does not exist.',
            'pickup_point_id.required' => 'Please select a pickup point.',
            'pickup_point_id.exists' => 'Selected pickup point does not exist.',
        ]);
        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {

            $today = Carbon::now();

            $user_id = Auth::user()->id;
            if (Auth::user()->hasRole('Driver') || Auth::user()->hasRole('Helper')) {
                $routeVehicle = RouteVehicle::where(function ($q) use ($user_id) {
                    $q->where('driver_id', $user_id)
                        ->orWhere('helper_id', $user_id);
                })
                    ->whereHas('route', function ($q) use ($request) {
                        $q->where('shift_id', $request->shift_id);
                    })
                    ->first();

                $users = TransportationPayment::with(['user', 'pickupPoint', 'shift'])
                    ->where('route_vehicle_id', $routeVehicle->id)
                    ->where('pickup_point_id', $request->pickup_point_id)
                    ->where('shift_id', $request->shift_id)
                    ->where('expiry_date', '>', $today)
                    ->get();
                $data['route_vehicle'] = [
                    'id' => $routeVehicle->id
                ];
                $data['shift'] = [
                    'id' => $request->shift_id,
                    'name' => $users[0]->shift->name,
                    'time' => date('h:i A', strtotime($users[0]->shift->start_time)) . " - " . date('h:i A', strtotime($users[0]->shift->end_time)),
                ];
                $data['pickup_drop_point'] = [
                    'pickup_drop_point_id' => $users[0]->pickupPoint->id,
                    'pickup_drop_point_name' => $users[0]->pickupPoint->name
                ];
                foreach ($users as $user) {
                    $data['users'][] = [
                        'id' => $user->user->id,
                        'full_name' => $user->user->full_name,
                        'avtar' => $user->user->image,
                    ];
                }
                return ResponseService::successResponse("Data fetched successfully", $data);
            } else {
                return ResponseService::errorResponse("Unauthorised");
            }


        } catch (\Throwable $th) {
            DB::rollBack();
            ResponseService::logErrorResponse($th);
            return ResponseService::errorResponse();
        }
    }
    public function getTransoprtationAttendanceStore(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'route_vehicle_id' => 'required|exists:route_vehicles,id',
            'pickup_point_id' => 'required|exists:pickup_points,id',
            'shift_id' => 'required|exists:shifts,id',
            'pickup_drop' => 'required|in:0,1', // 0=pickup, 1=drop
            'date' => 'required|date',
            'records' => 'required|array|min:1',
            'records.*.user_id' => 'required|exists:users,id',
            'records.*.status' => 'required|in:present,absent',
            'trip_id' => 'required|exists:route_vehicle_histories,id',
        ], [
            'date.required' => 'Date is required.',
            'date.date' => 'Date must be a valid date.',
            'route_vehicle_id.required' => 'Route vehicle id is required.',
            'route_vehicle_id.exists' => 'Route vehicle does not exist.',
            'shift_id.required' => 'Shift is required.',
            'shift_id.exists' => 'Shift does not exist.',
            'pickup_point_id.required' => 'Pickup point is required.',
            'pickup_point_id.exists' => 'Pickup point does not exist.',
            'pickup_drop.required' => 'Pickup/Drop type is required.',
            'pickup_drop.in' => 'Pickup/Drop must be 0 (pickup) or 1 (drop).',
            'records.required' => 'Attendance records are required.',
            'records.array' => 'Attendance records must be an array.',
            'records.min' => 'At least one user record is required.',
            'records.*.user_id.required' => 'User ID is required for each record.',
            'records.*.user_id.exists' => 'One or more user IDs are invalid.',
            'records.*.status.required' => 'Status is required for each user.',
            'records.*.status.in' => 'Status must be either present or absent.',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            if (!(Auth::user()->hasRole('Driver') || Auth::user()->hasRole('Helper'))) {
                return ResponseService::errorResponse("Unauthorised");
            }

            DB::beginTransaction();
            $today = Carbon::now();

            $routeVehicleHistory = RouteVehicleHistory::with([
                'vehicle',
                'route',
                'route.routePickupPoints',
                'route.routePickupPoints.pickupPoint',
                'shift'
            ])
                ->where('id', $request->trip_id)
                ->first();

            if ($routeVehicleHistory) {
                // Sort pickup/drop points in correct order
                $pickupPoints = $routeVehicleHistory->route->routePickupPoints;
                if ($routeVehicleHistory->type == 'pickup') {
                    $pickupPoints = $pickupPoints->sortBy(fn($p) => $p->pickup_time)->values();
                } else {
                    $pickupPoints = $pickupPoints->sortBy(fn($p) => $p->drop_time)->values();
                }

                // Find current index in the sorted list
                $currentIndex = $pickupPoints->search(
                    fn($p) => $p->pickup_point_id == $request->pickup_point_id
                );

                // Get the *next* pickup point
                $nextPickupPoint = $pickupPoints->get($currentIndex + 1);

                $users = collect(); // default empty collection

                if ($nextPickupPoint) {
                    $users = TransportationPayment::where('route_vehicle_id', $routeVehicleHistory->route->routeVehicle->first()->id)
                        ->where('shift_id', $routeVehicleHistory->shift_id)
                        ->where('expiry_date', '>', $today)
                        ->where('pickup_point_id', $nextPickupPoint->pickup_point_id)
                        ->pluck('user_id');

                    $students = $this->student->builder()->whereIn('user_id', $users)->get();
                    $guardian_id = $students->pluck('guardian_id')->toArray();
                }
            }

            foreach ($request->records as $record) {
                TransportationAttendance::updateOrCreate(
                    [
                        'trip_id' => $request->trip_id,
                        'user_id' => $record['user_id'],
                        'date' => $request->date,
                        'shift_id' => $request->shift_id,
                    ],
                    [
                        'pickup_point_id' => $request->pickup_point_id,
                        'route_vehicle_id' => $request->route_vehicle_id,
                        'status' => $record['status'],
                        'pickup_drop' => $request->pickup_drop,
                        'created_by' => Auth::user()->id,
                    ]
                );
            }

            RouteVehicleHistory::where('id', $request->trip_id)
                ->update([
                    'last_pickup_point_id' => $request->pickup_point_id
                ]);

            if (isset($users) && $users->isNotEmpty()) {
                $user = array_merge($users->toarray(), $guardian_id);
                $title = "Your stop is next!";
                $body = "The bus has reached the previous stop. Please be ready at your pickup point.";
                $type = "Transportation";
                send_notification($user, $title, $body, $type);
            }

            DB::commit();

            return ResponseService::successResponse("Attendance stored successfully");

        } catch (\Throwable $th) {
            DB::rollBack();
            ResponseService::logErrorResponse($th);
            return ResponseService::errorResponse();
        }
    }

    public function getDriverHelperDashboard()
    {
        try {
            if (!(Auth::user()->hasRole('Driver') || Auth::user()->hasRole('Helper'))) {
                return ResponseService::errorResponse("Unauthorised");
            }

            $data = [];
            $user_id = Auth::user()->id;
            $today = Carbon::now();
            $user = User::where('id', Auth::user()->id)->first();
            $routeVehicles = RouteVehicle::with(['vehicle', 'route', 'shift', 'route.routePickupPoints', 'route.routePickupPoints.pickupPoint'])->where(function ($q) use ($user_id) {
                $q->where('driver_id', $user_id)
                    ->orWhere('helper_id', $user_id);
            })->where('status', 1)->get();

            $routeVehicleHistories = RouteVehicleHistory::with(['vehicle', 'route', 'route.routePickupPoints', 'route.routePickupPoints.pickupPoint', 'shift'])->where(function ($q) use ($user_id) {
                $q->where('driver_id', $user_id)
                    ->orWhere('helper_id', $user_id);
            })->get();

            $transportationPayments = TransportationPayment::with([
                'pickupPoint',
                'user',
                'paymentTransaction',
                'transportationFee',
                'routeVehicle',
                'routeVehicle.route',
                'routeVehicle.vehicle',
                'routeVehicle.driver',
                'routeVehicle.helper'
            ])
                ->whereNotNull('route_vehicle_id')
                ->where('expiry_date', ">", $today)
                ->get();

            $transportationPaymentUserIds = $transportationPayments->pluck('user_id');

            $leaves = Leave::with(['leave_detail', 'user'])
                ->where(function ($q) use ($user_id, $transportationPaymentUserIds) {
                    $q->where('user_id', $user_id)
                        ->orWhereIn('user_id', $transportationPaymentUserIds);
                })
                ->where('status', 1)
                ->get();

            $sessionYear = $this->cache->getDefaultSessionYear();

            $school_holidays = Holiday::where('date', '>=', date("Y-m-d", strtotime($today)))
                ->whereDate('date', '>=', $sessionYear->start_date)
                ->whereDate('date', '<=', $sessionYear->end_date)
                ->get();

            $route_vehicle_status = "No active route and vehicle found";

            if ($routeVehicles) {
                $route_vehicle_status = "Route and vehicle found";
                foreach ($routeVehicles as $routeVehicle) {
                    $route_vehicle[] = [
                        'shift' => [
                            'id' => $routeVehicle->route->shift_id,
                            'name' => $routeVehicle->route->shift->name,
                            'start_time' => date('h:i A', strtotime($routeVehicle->route->shift->start_time)),
                            'end_time' => date('h:i A', strtotime($routeVehicle->route->shift->end_time)),
                        ],
                        'vehicle' => [
                            'id' => $routeVehicle->vehicle->id,
                            'name' => $routeVehicle->vehicle->name,
                            'registration_number' => $routeVehicle->vehicle->vehicle_number,
                        ],
                        'route' => [
                            'id' => $routeVehicle->route->id,
                            'name' => $routeVehicle->route->name,
                        ],
                    ];
                }
            }
            $route_vehicle[] = ['status' => $route_vehicle_status];

            $live_trips = [];
            if ($routeVehicleHistories->isNotEmpty()) {
                foreach ($routeVehicleHistories as $routeVehicleHistory) {
                    if ($routeVehicleHistory->type == 'drop') {
                        // Drop: School → first pickup
                        $from = "School";
                        $to = optional($routeVehicleHistory->route->routePickupPoints->first()->pickupPoint)->name;
                    } elseif ($routeVehicleHistory->type == 'pickup') {
                        // Pickup: Last pickup → School
                        $from = optional($routeVehicleHistory->route->routePickupPoints->first()->pickupPoint)->name;
                        $to = "School";
                    }

                    $live_trips[] = [
                        'trip_id' => $routeVehicleHistory->id,
                        'from' => $from,
                        'to' => $to,
                        'route_name' => $routeVehicleHistory->route->name,
                        'type' => $routeVehicleHistory->type,
                        'status' => $routeVehicleHistory->status,
                        'shift_time' => [
                            'from' => date('h:i A', strtotime($routeVehicleHistory->start_time)),
                            'to' => date('h:i A', strtotime($routeVehicleHistory->end_time)),
                        ],
                    ];
                }
            } else {
                foreach ($routeVehicles as $routeVehicle) {
                    $pickup_from = optional($routeVehicle->route->routePickupPoints->first()->pickupPoint)->name;
                    $pickup_to = "school";
                    $drop_from = "school";
                    $drop_to = optional($routeVehicle->route->routePickupPoints->first()->pickupPoint)->name;

                    $live_trips[] = [
                        'from' => $pickup_from,
                        'to' => $pickup_to,
                        'route_name' => $routeVehicle->route->name,
                        'type' => 'pickup',
                        'status' => 'upcoming',
                        'shift_time' => [
                            'from' => date('h:i A', strtotime($routeVehicle->pickup_start_time)),
                            'to' => date('h:i A', strtotime($routeVehicle->pickup_end_time)),
                        ],
                    ];
                    $live_trips[] = [
                        'from' => $drop_from,
                        'to' => $drop_to,
                        'route_name' => $routeVehicle->route->name,
                        'type' => 'pickup',
                        'status' => 'upcoming',
                        'shift_time' => [
                            'from' => date('h:i A', strtotime($routeVehicle->drop_start_time)),
                            'to' => date('h:i A', strtotime($routeVehicle->drop_end_time)),
                        ],
                    ];
                }
            }

            $new_passenger = [];
            if ($transportationPayments->isNotEmpty()) {
                $todayPayment = $transportationPayments->filter(function ($payment) {
                    return $payment->created_at->isToday();
                });

                foreach ($todayPayment as $transportationPayment) {
                    $new_passenger[] = [
                        'id' => $transportationPayment->user_id,
                        'name' => $transportationPayment->user->full_name,
                        'avtar' => $transportationPayment->user->image,
                        'mobile' => $transportationPayment->user->mobile ?? "",
                        'shift_time' => [
                            'from' => $transportationPayment->shift->start_time,
                            'to' => $transportationPayment->shift->end_time,
                        ],
                        'pickup_point' => [
                            'id' => $transportationPayment->pickupPoint->id,
                            'name' => $transportationPayment->pickupPoint->name,
                        ],
                    ];
                }
            }

            $staff_on_leave = [];
            $my_leaves = [];
            if ($leaves->isNotEmpty()) {
                foreach ($leaves as $leave) {
                    $location_data = $transportationPayments->where('user_id', $leave->user_id)->filter(function ($payment) {
                        return !in_array($payment->user->role, ['Driver', 'Helper']);
                    })->first();
                    if ($location_data) {
                        foreach ($leave->leave_detail as $leave_detail) {
                            if ($leave_detail->date == date("Y-m-d", strtotime($today))) {
                                $staff_on_leave[] = [
                                    'id' => $leave->user_id,
                                    'name' => $leave->user->full_name,
                                    'avtar' => $leave->user->image,
                                    'location' => $location_data->pickupPoint->name,
                                    'leave_type' => $leave_detail->type,
                                ];
                            }
                        }
                    }
                }
                $user_leaves = $leaves->where('user_id', Auth::user()->id)->values();
                if ($user_leaves->isNotEmpty()) {
                    foreach ($user_leaves as $user_leave) {
                        foreach ($user_leave->leave_detail as $user_leave_detail) {
                            if ($user_leave_detail->date >= date("Y-m-d", strtotime($today))) {
                                $my_leaves[] = [
                                    "date" => $user_leave_detail->date,
                                    "leave_type" => $user_leave_detail->type,
                                    "reason" => $user_leave->reason
                                ];
                            }
                        }
                    }
                }
            }

            $holidays = [];
            if ($school_holidays->isNotEmpty()) {
                foreach ($school_holidays as $school_holiday) {
                    $holidays[] = [
                        'id' => $school_holiday->id,
                        'date' => $school_holiday->date,
                        'name' => $school_holiday->title,
                        'description' => $school_holiday->description,
                    ];
                }
            }

            $data = [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->full_name,
                    'avtar' => $user->image,
                ],
                'route_vehicle' => $route_vehicle ?? [],
                'live_trips' => $live_trips,
                'new_passenger' => $new_passenger,
                'staff_on_leave' => $staff_on_leave,
                'my_leaves' => $my_leaves,
                'holidays' => $holidays,
            ];

            return ResponseService::successResponse("Dashboard data fetched successfully", $data);

        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            return ResponseService::errorResponse();
        }
    }

    public function getVehicleDetails()
    {
        try {

            if (!(Auth::user()->hasRole('Driver') || Auth::user()->hasRole('Helper'))) {
                return ResponseService::errorResponse("Unauthorized");
            }

            $user_id = Auth::user()->id;

            $routeVehicles = RouteVehicle::with(['vehicle', 'route', 'route.shift'])->where(function ($q) use ($user_id) {
                $q->where('driver_id', $user_id)
                    ->orWhere('helper_id', $user_id);
            })->where('status', 1)->get();

            $data = [];

            if ($routeVehicles->isNotEmpty()) {
                foreach ($routeVehicles as $routeVehicle) {
                    $data[] = [
                        'vehicle' => [
                            'id' => $routeVehicle->vehicle->id,
                            'name' => $routeVehicle->vehicle->name,
                            'registration_number' => $routeVehicle->vehicle->vehicle_number,
                        ],
                        'shifts' => [
                            'id' => $routeVehicle->route->shift->id,
                            'name' => $routeVehicle->route->shift->name,
                            'start_time' => date("h:i A", strtotime($routeVehicle->route->shift->start_time)),
                            'end_time' => date("h:i A", strtotime($routeVehicle->route->shift->end_time)),
                            'pickup_start_time' => date("h:i A", strtotime($routeVehicle->pickup_start_time)),
                            'pickup_end_time' => date("h:i A", strtotime($routeVehicle->pickup_end_time)),
                            'drop_start_time' => date("h:i A", strtotime($routeVehicle->drop_start_time)),
                            'drop_end_time' => date("h:i A", strtotime($routeVehicle->drop_end_time)),
                        ],
                    ];
                }
            }

            return ResponseService::successResponse("Vehicle data fetched successfully", $data);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            return ResponseService::errorResponse();
        }
    }

    public function tripStartEnd(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'shift_id' => 'required|exists:shifts,id',
            'pickup_drop' => 'required|in:pickup,drop',
            'start_end' => 'required|in:start,end',
            'trip_id' => 'sometimes|exists:route_vehicle_histories,id'
        ], [
            'shift_id.required' => 'Shift is required.',
            'shift_id.exists' => 'Shift does not exist.',
            'pickup_drop.required' => 'Pickup/Drop type is required.',
            'pickup_drop.in' => 'Pickup/Drop must pickup or drop.',
            'start_end.required' => 'Start/End type is required.',
            'start_end.in' => 'Start/End must be start or end.',
            'trip_id.exists' => 'Trip does not exist.',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }
        try {

            if (!(Auth::user()->hasRole('Driver') || Auth::user()->hasRole('Helper'))) {
                return ResponseService::errorResponse("Unauthorized");
            }

            $user_id = Auth::user()->id;
            $today = Carbon::now();

            $routeVehicles = RouteVehicle::with(['vehicle', 'shift', 'route'])->where(function ($q) use ($user_id) {
                $q->where('driver_id', $user_id)
                    ->orWhere('helper_id', $user_id);
            })->where('status', 1)
                ->whereHas('route', function ($q) use ($request) {
                    $q->where('shift_id', $request->shift_id);
                })
                ->first();

            $data = [];
            $sessionYear = $this->cache->getDefaultSessionYear();

            if ($routeVehicles) {
                if ($request->start_end == 'start') {
                    if ($request->pickup_drop == 'drop') {
                        // Ensure pickup trip exists and is completed
                        $pickupTrip = RouteVehicleHistory::where('route_id', $routeVehicles->route_id)
                            ->where('vehicle_id', $routeVehicles->vehicle_id)
                            ->where('shift_id', $routeVehicles->route->shift_id)
                            ->where('date', date("Y-m-d"))
                            ->where('type', 'pickup')
                            ->first();

                        if (!$pickupTrip) {
                            return ResponseService::errorResponse("You cannot start a drop trip before completing the pickup trip.");
                        }

                        if ($pickupTrip->status !== 'completed') {
                            return ResponseService::errorResponse("Pickup trip must be completed before starting the drop trip.");
                        }
                    }
                    $existingTrip = RouteVehicleHistory::where('route_id', $routeVehicles->route_id)
                        ->where('vehicle_id', $routeVehicles->vehicle_id)
                        ->where('shift_id', $routeVehicles->route->shift_id)
                        ->where('date', date("Y-m-d"))
                        ->where('type', $request->pickup_drop)
                        ->whereIn('status', ['inprogress', 'completed'])
                        ->first();

                    if ($existingTrip) {
                        return ResponseService::errorResponse("This {$request->pickup_drop} trip has already been started today.");
                    }
                    if ($request->pickup_drop == 'pickup') {
                        $data = [
                            'route_id' => $routeVehicles->route_id,
                            'vehicle_id' => $routeVehicles->vehicle_id,
                            'shift_id' => $routeVehicles->route->shift_id,
                            'driver_id' => $routeVehicles->driver_id,
                            'helper_id' => $routeVehicles->helper_id,
                            'date' => date("Y-m-d"),
                            'type' => $request->pickup_drop,
                            'status' => 'inprogress',
                            'start_time' => $routeVehicles->pickup_start_time,
                            'actual_start_time' => date("H:i:s"),
                            'end_time' => $routeVehicles->pickup_end_time,
                            'session_year_id' => $sessionYear->id,
                            'created_by' => $user_id,
                        ];
                    } else {
                        $data = [
                            'route_id' => $routeVehicles->route_id,
                            'vehicle_id' => $routeVehicles->vehicle_id,
                            'shift_id' => $routeVehicles->route->shift_id,
                            'driver_id' => $routeVehicles->driver_id,
                            'helper_id' => $routeVehicles->helper_id,
                            'date' => date("Y-m-d"),
                            'type' => $request->pickup_drop,
                            'status' => 'inprogress',
                            'start_time' => $routeVehicles->drop_start_time,
                            'actual_start_time' => date("H:i:s"),
                            'end_time' => $routeVehicles->drop_end_time,
                            'session_year_id' => $sessionYear->id,
                            'created_by' => $user_id,
                        ];

                    }

                    $id = RouteVehicleHistory::create($data);
                    $trip_id = ["trip_id" => $id->id];
                    return ResponseService::successResponse("Trip Started", $trip_id);
                } else {
                    $trip = RouteVehicleHistory::with(['route', 'route.routePickupPoints', 'route.routePickupPoints.pickupPoint'])->where('id', $request->trip_id)->first();
                    $users = TransportationPayment::with(['user', 'user.roles', 'pickupPoint', 'shift'])
                        ->where('route_vehicle_id', $trip->route->routeVehicle->first()->id)
                        ->where('shift_id', $trip->shift_id)
                        ->where('expiry_date', '>', $today)
                        ->get();
                    if (!$trip) {
                        return ResponseService::errorResponse("Trip not found");
                    }
                    if ($trip->type == 'drop') {
                        $last_pickup_point = $trip->route->routePickupPoints
                            ->filter(fn($p) => $users->contains('pickup_point_id', $p->pickup_point_id))
                            ->sortBy(fn($p) => $p->drop_time)
                            ->last()?->pickupPoint?->id;
                    } elseif ($trip->type == 'pickup') {
                        $last_pickup_point = $trip->route->routePickupPoints
                            ->filter(fn($p) => $users->contains('pickup_point_id', $p->pickup_point_id))
                            ->sortBy(fn($p) => $p->pickup_time)
                            ->last()?->pickupPoint?->id;
                    }

                    if ($trip->last_pickup_point_id == $last_pickup_point) {
                        $trip->actual_end_time = date("H:i:s");
                        $trip->status = "completed";
                        $trip->save();
                        return ResponseService::successResponse("Trip ended");
                    } else {
                        return ResponseService::errorResponse("You have not reached the last pickup point yet");
                    }
                }
            } else {
                return ResponseService::errorResponse("No active route and vehicle found");
            }
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            return ResponseService::errorResponse();
        }
    }

    public function getDriverHelperTrips(Request $request)
    {
        try {

            if (!(Auth::user()->hasRole('Driver') || Auth::user()->hasRole('Helper'))) {
                return ResponseService::errorResponse("Unauthorised");
            }

            $data = [];
            $user_id = Auth::user()->id;
            $today = Carbon::now();
            $routeVehicleHistories = RouteVehicleHistory::with(['vehicle', 'route', 'route.routePickupPoints', 'route.routePickupPoints.pickupPoint', 'shift'])->where(function ($q) use ($user_id) {
                $q->where('driver_id', $user_id)
                    ->orWhere('helper_id', $user_id);
            });
            if ($request->has('route_id') && $request->route_id) {
                $routeVehicleHistories->where('route_id', $request->route_id);
            }
            if ($request->has('trip_id') && $request->trip_id) {
                $routeVehicleHistories->where('id', $request->trip_id);
            }
            $routeVehicleHistories = $routeVehicleHistories->get();

            $inprogress_data = array();
            $upcoming_data = array();
            $completed_data = array();

            if ($routeVehicleHistories->isNotEmpty()) {
                foreach ($routeVehicleHistories as $routeVehicleHistory) {
                    $stops = [];
                    $minutesDiff = Carbon::parse($routeVehicleHistory->start_time)
                        ->diffInMinutes(Carbon::parse($routeVehicleHistory->actual_start_time), false);

                    $routePickupPoints = $routeVehicleHistory->route->routePickupPoints->map(function ($point) use ($minutesDiff) {
                        // Calculate pickup difference
                        $pickupDiff = null;
                        if ($point->pickup_time && $minutesDiff) {
                            $pickupDiff = Carbon::parse($point->pickup_time)
                                ->addMinutes($minutesDiff);
                        }

                        // Calculate drop difference
                        $dropDiff = null;
                        if ($point->drop_time && $minutesDiff) {
                            $dropDiff = Carbon::parse($point->drop_time)
                                ->addMinutes($minutesDiff);
                        }

                        // Add attributes to point model
                        $point->pickup_diff_minutes = $pickupDiff;
                        $point->drop_diff_minutes = $dropDiff;

                        return $point;
                    });
                    $users = TransportationPayment::with(['user', 'user.roles', 'pickupPoint', 'shift'])
                        ->where('route_vehicle_id', $routeVehicleHistory->route->routeVehicle->first()->id)
                        ->where('shift_id', $routeVehicleHistory->shift_id)
                        ->where('expiry_date', '>', $today)
                        ->get();

                    if ($routeVehicleHistory->created_at < $users->max('created_at')) {
                        $users = $users->filter(function ($user) use ($routeVehicleHistory) {
                            return $user->created_at < $routeVehicleHistory->created_at;
                        })->values();
                    }

                    $transportationAttendance = TransportationAttendance::where('route_vehicle_id', $routeVehicleHistory->route->routeVehicle->first()->id)
                        ->where('shift_id', $routeVehicleHistory->shift_id)
                        ->where('trip_id', $routeVehicleHistory->id)
                        ->get();

                    if ($routeVehicleHistory->type == 'pickup') {
                        $routePickupPoints = $routePickupPoints->sortBy(fn($p) => $p->pickup_time)->values();
                    } else {
                        $routePickupPoints = $routePickupPoints->sortBy(fn($p) => $p->drop_time)->values();
                    }

                    if ($routeVehicleHistory->created_at < $routePickupPoints->max('created_at')) {
                        $routePickupPoints = $routePickupPoints->filter(function ($point) use ($routeVehicleHistory) {
                            return $point->created_at < $routeVehicleHistory->created_at;
                        })->values();
                    }

                    $stops[] = [
                        'name' => "School",
                        'scheduled_time' => date('h:i A', strtotime($routeVehicleHistory->start_time)),
                        'actual_time' => date('h:i A', strtotime($routeVehicleHistory->actual_start_time)),
                    ];
                    foreach ($routePickupPoints as $pickupPoint) {
                        if ($routeVehicleHistory->type == 'drop') {
                            $time = $pickupPoint->drop_time;
                            $estimated_time = $pickupPoint->drop_diff_minutes;
                            if ($routeVehicleHistory->last_pickup_point_id) {
                                $actual_time = null;
                                $attendance = $transportationAttendance
                                    ->where('pickup_point_id', $pickupPoint->pickup_point_id)
                                    ->sortByDesc('created_at')
                                    ->first();
                                $actual_time = $attendance ? date('h:i A', strtotime($attendance->created_at)) : null;
                                $lastPickupPoint = $routePickupPoints->firstWhere('pickup_point_id', $routeVehicleHistory->last_pickup_point_id);
                                $lastEstimated_time = $lastPickupPoint->drop_diff_minutes;
                                $estimated_time_diff = Carbon::parse($lastEstimated_time)->diffInMinutes(Carbon::parse(date('H:i:s', strtotime($routeVehicleHistory->updated_at))), false);
                                if ($estimated_time_diff < 0) {
                                    $estimated_time_diff = $estimated_time_diff - 1;
                                }
                                $estimated_time = Carbon::parse($estimated_time)->addMinutes($estimated_time_diff);
                                if ($actual_time) {
                                    $estimated_time = null;
                                }
                            }
                        } else {
                            $time = $pickupPoint->pickup_time;
                            $estimated_time = $pickupPoint->pickup_diff_minutes;
                            if ($routeVehicleHistory->last_pickup_point_id) {
                                $actual_time = null;
                                $attendance = $transportationAttendance
                                    ->where('pickup_point_id', $pickupPoint->pickup_point_id)
                                    ->sortByDesc('created_at')
                                    ->first();
                                $actual_time = $attendance ? date('h:i A', strtotime($attendance->created_at)) : null;
                                $lastPickupPoint = $routePickupPoints->firstWhere('pickup_point_id', $routeVehicleHistory->last_pickup_point_id);
                                $lastEstimated_time = $lastPickupPoint->pickup_diff_minutes;
                                $estimated_time_diff = Carbon::parse($lastEstimated_time)->diffInMinutes(Carbon::parse(date('H:i:s', strtotime($routeVehicleHistory->updated_at))), false);
                                if ($estimated_time_diff < 0) {
                                    $estimated_time_diff = $estimated_time_diff - 1;
                                }
                                $estimated_time = Carbon::parse($estimated_time)->addMinutes($estimated_time_diff);
                                if ($actual_time) {
                                    $estimated_time = null;
                                }
                            }
                        }
                        if ($users->where('pickup_point_id', $pickupPoint->pickup_point_id)->values()->isNotEmpty()) {
                            $stops[] = [
                                'id' => $pickupPoint->pickup_point_id,
                                'name' => $pickupPoint->pickupPoint->name,
                                'scheduled_time' => date('h:i A', strtotime($time)),
                                'estimated_time' => $estimated_time ? date('h:i A', strtotime($estimated_time)) : "Reached",
                                'actual_time' => $actual_time ?? "Pending",
                                'passengers' => $users->where('pickup_point_id', $pickupPoint->pickup_point_id)
                                    ->values()
                                    ->map(function ($user) use ($transportationAttendance, $pickupPoint) {
                                        // find attendance for this passenger at this pickup point
                                        $attendance = $transportationAttendance
                                            ->firstWhere(function ($att) use ($user, $pickupPoint) {
                                            return $att->user_id == $user->user->id &&
                                                $att->pickup_point_id == $pickupPoint->pickup_point_id;
                                        });

                                        return [
                                            'id' => $user->user->id,
                                            'name' => $user->user->full_name,
                                            'role' => $user->user->role,
                                            'image' => $user->user->image,
                                            'attendance_status' => $attendance->status ?? 'pending',
                                        ];
                                    }),
                            ];
                        }
                    }
                    $allStopsCompleted = collect($stops)->every(function ($stop) {
                        return isset($stop['actual_time']) && $stop['actual_time'] !== "Pending";
                    });
                    $stops[] = [
                        'name' => "School",
                        'scheduled_time' => date('h:i A', strtotime($routeVehicleHistory->end_time)),
                        'actual_time' => $routeVehicleHistory->actual_end_time ? date('h:i A', strtotime($routeVehicleHistory->actual_end_time)) : "Pending",
                    ];
                    if ($routeVehicleHistory->type == 'drop') {
                        // Drop: School → first pickup
                        $from = "School";
                        $to = optional($routeVehicleHistory->route->routePickupPoints->first()->pickupPoint)->name;
                    } elseif ($routeVehicleHistory->type == 'pickup') {
                        // Pickup: Last pickup → School
                        $from = optional($routeVehicleHistory->route->routePickupPoints->first()->pickupPoint)->name;
                        $to = "School";
                    }


                    if ($routeVehicleHistory->status == 'inprogress') {
                        $inprogress_data[] = [
                            'trip_id' => $routeVehicleHistory->id,
                            'status' => $routeVehicleHistory->status,
                            'shift_time' => [
                                'id' => $routeVehicleHistory->shift->id,
                                'label' => (function ($start) {
                                    $hour = date('H', strtotime($start)); // 24-hour format
        
                                    if ($hour >= 5 && $hour < 12) {
                                        return 'Morning';
                                    } elseif ($hour >= 12 && $hour < 17) {
                                        return 'Noon';
                                    } elseif ($hour >= 17 && $hour < 21) {
                                        return 'Evening';
                                    } else {
                                        return 'Night';
                                    }
                                })($routeVehicleHistory->start_time),
                                'from' => date('h:i A', strtotime($routeVehicleHistory->start_time)),
                                'to' => date('h:i A', strtotime($routeVehicleHistory->end_time)),
                            ],
                            'route' => [
                                'id' => $routeVehicleHistory->route->id,
                                'route_vehicle_id' => $routeVehicleHistory->route->routeVehicle->first()->id,
                                'name' => $routeVehicleHistory->route->name,
                            ],
                            'stops' => $stops,
                            'all_stops_completed' => $allStopsCompleted,
                            'type' => $routeVehicleHistory->type,
                            'last_reached_stop' => [
                                'id' => $routeVehicleHistory->last_pickup_point_id ? optional($routeVehicleHistory->route->routePickupPoints->where('pickup_point_id', $routeVehicleHistory->last_pickup_point_id)->first())->pickupPoint->id : null,
                                'name' => $routeVehicleHistory->last_pickup_point_id ? optional($routeVehicleHistory->route->routePickupPoints->where('pickup_point_id', $routeVehicleHistory->last_pickup_point_id)->first())->pickupPoint->name : null
                            ],
                        ];
                    } else {
                        $completed_data[] = [
                            'trip_id' => $routeVehicleHistory->id,
                            'status' => $routeVehicleHistory->status,
                            'shift_time' => [
                                'id' => $routeVehicleHistory->shift->id,
                                'label' => (function ($start) {
                                    $hour = date('H', strtotime($start)); // 24-hour format
        
                                    if ($hour >= 5 && $hour < 12) {
                                        return 'Morning';
                                    } elseif ($hour >= 12 && $hour < 17) {
                                        return 'Noon';
                                    } elseif ($hour >= 17 && $hour < 21) {
                                        return 'Evening';
                                    } else {
                                        return 'Night';
                                    }
                                })($routeVehicleHistory->start_time),
                                'from' => date('h:i A', strtotime($routeVehicleHistory->start_time)),
                                'to' => date('h:i A', strtotime($routeVehicleHistory->end_time)),
                            ],
                            'route' => [
                                'id' => $routeVehicleHistory->route->id,
                                'route_vehicle_id' => $routeVehicleHistory->route->routeVehicle->first()->id,
                                'name' => $routeVehicleHistory->route->name,
                            ],
                            'stops' => $stops,
                            'all_stops_completed' => $allStopsCompleted,
                            'type' => $routeVehicleHistory->type,
                            'last_reached_stop' => [
                                'id' => $routeVehicleHistory->last_pickup_point_id ? optional($routeVehicleHistory->route->routePickupPoints->where('pickup_point_id', $routeVehicleHistory->last_pickup_point_id)->first())->pickupPoint->id : null,
                                'name' => $routeVehicleHistory->last_pickup_point_id ? optional($routeVehicleHistory->route->routePickupPoints->where('pickup_point_id', $routeVehicleHistory->last_pickup_point_id)->first())->pickupPoint->name : null
                            ],
                        ];
                    }
                }
            }

            $activeTrips = collect($inprogress_data)->map(function ($trip) {
                return $trip['route']['route_vehicle_id'] . '-' . $trip['shift_time']['id'] . '-' . $trip['type'];
            })->toArray();

            if (!$request->filled('trip_id')) {
                $routeVehicles = RouteVehicle::with(['vehicle', 'route', 'route.shift', 'route.routePickupPoints', 'route.routePickupPoints.pickupPoint'])->where(function ($q) use ($user_id) {
                    $q->where('driver_id', $user_id)
                        ->orWhere('helper_id', $user_id);
                })->where('status', 1);
                if ($request->has('route_id') && $request->route_id) {
                    $routeVehicles->where('route_id', $request->route_id);
                }
                $routeVehicles = $routeVehicles->get();


                foreach ($routeVehicles as $routeVehicle) {

                    $users = TransportationPayment::with(['user', 'user.roles', 'pickupPoint', 'shift'])
                        ->where('route_vehicle_id', $routeVehicle->id)
                        ->where('shift_id', $routeVehicle->route->shift_id)
                        ->where('expiry_date', '>', $today)
                        ->get();
                    $stops = [];
                    $stops[] = [
                        'name' => "School",
                        'scheduled_time' => date('h:i A', strtotime($routeVehicle->pickup_start_time)),
                    ];
                    $routePickupPoints = $routeVehicle->route->routePickupPoints->sortBy(fn($p) => $p->pickup_time)->values();
                    foreach ($routePickupPoints as $pickupPoint) {
                        if ($users->where('pickup_point_id', $pickupPoint->pickup_point_id)->values()->isNotEmpty()) {
                            $stops[] = [
                                'id' => $pickupPoint->pickup_point_id,
                                'name' => $pickupPoint->pickupPoint->name,
                                'scheduled_time' => date('h:i A', strtotime($pickupPoint->pickup_time)),
                                'passengers' => $users->where('pickup_point_id', $pickupPoint->pickup_point_id)->values()->map(function ($user) {
                                    return [
                                        'id' => $user->user->id,
                                        'name' => $user->user->full_name,
                                        'role' => $user->user->role,
                                        'image' => $user->user->image,
                                    ];
                                }),
                            ];
                        }
                    }
                    $stops[] = [
                        'name' => "School",
                        'scheduled_time' => date('h:i A', strtotime($routeVehicle->pickup_end_time)),
                    ];
                    $key = $routeVehicle->id . '-' . $routeVehicle->route->shift_id . '-pickup';
                    if (!in_array($key, $activeTrips)) {
                        $upcoming_data[] = [
                            'status' => 'upcoming',
                            'shift_time' => [
                                'id' => $routeVehicle->route->shift_id,
                                'label' => (function ($start) {
                                    $hour = date('H', strtotime($start)); // 24-hour format
        
                                    if ($hour >= 5 && $hour < 12) {
                                        return 'Morning';
                                    } elseif ($hour >= 12 && $hour < 17) {
                                        return 'Noon';
                                    } elseif ($hour >= 17 && $hour < 21) {
                                        return 'Evening';
                                    } else {
                                        return 'Night';
                                    }
                                })($routeVehicle->pickup_start_time),
                                'from' => date('h:i A', strtotime($routeVehicle->pickup_start_time)),
                                'to' => date('h:i A', strtotime($routeVehicle->pickup_end_time)),
                            ],
                            'route' => [
                                'id' => $routeVehicle->route->id,
                                'route_vehicle_id' => $routeVehicle->id,
                                'name' => $routeVehicle->route->name,
                            ],
                            'stops' => $stops,
                            'type' => 'pickup',
                        ];
                    }
                    $stops = [];
                    $stops[] = [
                        'name' => "School",
                        'scheduled_time' => date('h:i A', strtotime($routeVehicle->drop_start_time)),
                    ];
                    $routePickupPoints = $routeVehicle->route->routePickupPoints->sortBy(fn($p) => $p->drop_time)->values();
                    foreach ($routePickupPoints as $pickupPoint) {
                        if ($users->where('pickup_point_id', $pickupPoint->pickup_point_id)->values()->isNotEmpty()) {
                            $stops[] = [
                                'id' => $pickupPoint->pickup_point_id,
                                'name' => $pickupPoint->pickupPoint->name,
                                'scheduled_time' => date('h:i A', strtotime($pickupPoint->drop_time)),
                                'passengers' => $users->where('pickup_point_id', $pickupPoint->pickup_point_id)->values()->map(function ($user) {
                                    return [
                                        'id' => $user->user->id,
                                        'name' => $user->user->full_name,
                                        'role' => $user->user->role,
                                        'image' => $user->user->image,
                                    ];
                                }),
                            ];
                        }
                    }
                    $stops[] = [
                        'name' => "School",
                        'scheduled_time' => date('h:i A', strtotime($routeVehicle->drop_end_time)),
                    ];
                    $key = $routeVehicle->id . '-' . $routeVehicle->route->shift_id . '-drop';
                    if (!in_array($key, $activeTrips)) {
                        $upcoming_data[] = [
                            'status' => 'upcoming',
                            'shift_time' => [
                                'id' => $routeVehicle->route->shift_id,
                                'label' => (function ($start) {
                                    $hour = date('H', strtotime($start)); // 24-hour format
        
                                    if ($hour >= 5 && $hour < 12) {
                                        return 'Morning';
                                    } elseif ($hour >= 12 && $hour < 17) {
                                        return 'Noon';
                                    } elseif ($hour >= 17 && $hour < 21) {
                                        return 'Evening';
                                    } else {
                                        return 'Night';
                                    }
                                })($routeVehicle->drop_start_time),
                                'from' => date('h:i A', strtotime($routeVehicle->drop_start_time)),
                                'to' => date('h:i A', strtotime($routeVehicle->drop_end_time)),
                            ],
                            'route' => [
                                'id' => $routeVehicle->route->id,
                                'route_vehicle_id' => $routeVehicle->id,
                                'name' => $routeVehicle->route->name,
                            ],
                            'stops' => $stops,
                            'type' => 'drop',
                        ];
                    }
                }
            }

            $data = array_merge($inprogress_data, $upcoming_data, $completed_data);

            return ResponseService::successResponse("Trips fetched successfully", $data);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            return ResponseService::errorResponse();
        }
    }

    public function getTransportationAteendaceRecordForUser(request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'month' => 'sometimes',
            'trip_type' => 'sometimes|in:pickup,drop,all',
        ], [
            'user_id.required' => 'User is required.',
            'user_id.exists' => 'User does not exist.',
            'trip_type.in' => 'Trip type must be pickup, drop or all.',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }
        try {

            $data = [];

            $month = $request->month;
            if ($request->trip_type == 'pickup') {
                $trip_type = 0;
            } else if ($request->trip_type == 'drop') {
                $trip_type = 1;
            } else {
                $trip_type = null;
            }

            $transportationAttendance = TransportationAttendance::with(['pickupPoint', 'routeVehicle', 'shift'])
                ->where('user_id', $request->user_id)
                ->when($month && !empty($month), function ($query) use ($month) {
                    $query->where('date', 'like', '%' . $month . '%');
                })
                ->when($trip_type && !empty($trip_type), function ($query) use ($trip_type) {
                    $query->where('pickup_drop', $trip_type);
                })->get();
            $total_present = $transportationAttendance->where('status', 'present')->count();
            $total_absent = $transportationAttendance->where('status', 'absent')->count();

            if ($transportationAttendance->isEmpty()) {
                return ResponseService::errorResponse("No attendance records found");
            }
            $data['summary'] = [
                'present' => $total_present,
                'absent' => $total_absent,
            ];
            foreach ($transportationAttendance as $attendance) {
                $data['records'][] = [
                    'date' => $attendance->date,
                    'trip_type' => $attendance->pickup_drop == 0 ? 'pickup' : 'drop',
                    'status' => $attendance->status == 'present' ? 'P' : 'A',
                ];
            }

            return ResponseService::successResponse("Attendance records fetched successfully", $data);

        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            return ResponseService::errorResponse();
        }
    }
}
