<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TransportationPayment;
use App\Models\Vehicle;
use App\Models\Shift;
use App\Models\PickupPoint;
use App\Models\RouteVehicle;
use App\Models\TransportationFee;
use App\Models\PaymentTransaction;
use App\Repositories\User\UserInterface;
use App\Services\ResponseService;
use App\Services\BootstrapTableService;
use Throwable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Auth;
use App\Services\CachingService;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Repositories\ClassSection\ClassSectionInterface;

class TransportationRequestController extends Controller
{
    private UserInterface $user;
    private CachingService $cache;
    private ClassSectionInterface $classSection;
    public function __construct(UserInterface $user, CachingService $cache, ClassSectionInterface $classSection, )
    {
        $this->user = $user;
        $this->cache = $cache;
        $this->classSection = $classSection;
    }
    public function index()
    {
        ResponseService::noAnyPermissionThenSendJson(['transportationRequests-list']);
        $transportationRequests = TransportationPayment::with(['user', 'pickupPoint', 'shift'])
            ->where('status', 'paid')
            ->get();

        return view('transportation-request.index', compact('transportationRequests'));
    }

    public function show()
    {
        ResponseService::noPermissionThenRedirect('transportationRequests-list');
        $today = now();
        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'desc');
        $search = request('search');
        $showDeleted = request('show_deleted');
        $pickupPointId = request('pickup_point_id');
        $shiftId = request('shift_id');

        $sql = TransportationPayment::with([
            'user',
            'pickupPoint',
            'shift',
            'pickupPoint.routePickupPoints.route',
            'pickupPoint.routePickupPoints.route.routeVehicle',
            'pickupPoint.routePickupPoints.route.routeVehicle.vehicle',
            'transportationFee'
        ])->where('expiry_date', '>', $today)
            ->where('status', 'paid')->when(!empty($showDeleted), function ($query) {
                $query->whereNotNull('route_vehicle_id');
            })->when(empty($showDeleted), function ($query) {
                $query->whereNull('route_vehicle_id');
            })->when(!empty($pickupPointId), function ($query) use ($pickupPointId) {
                $query->where('pickup_point_id', $pickupPointId);
            })->when(!empty($shiftId), function ($query) use ($shiftId) {
                $query->where('shift_id', $shiftId);
            });

        if (!empty($search)) {
            $sql->where(function ($q) use ($search) {
                $q->orWhereHas('user', function ($d) use ($search) {
                    $d->where('first_name', 'LIKE', "%$search%")
                        ->orWhere('last_name', 'LIKE', "%$search%")
                        ->orWhere('email', 'LIKE', "%$search%")
                        ->orWhereRaw("concat(first_name,' ',last_name) LIKE '%" . $search . "%'");
                });
                $q->orWhereHas('pickupPoint', function ($d) use ($search) {
                    $d->where('name', 'LIKE', "%$search%");
                });
            });
        }

        $total = $sql->count();
        if ($offset >= $total && $total > 0) {
            $lastPage = floor(($total - 1) / $limit) * $limit; // calculate last page offset
            $offset = $lastPage;
        }
        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $res = $sql->get();

        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();
        $no = $offset + 1;

        $baseUrl = url('/');
        $baseUrlWithoutScheme = preg_replace("(^https?://)", "", $baseUrl);
        $baseUrlWithoutScheme = str_replace("www.", "", $baseUrlWithoutScheme);

        foreach ($res as $row) {
            $operate = BootstrapTableService::editButton(route('transportation-requests.update', $row->id));
            if (!$row->user->hasrole('Student')) {
                $operate .= BootstrapTableService::button(
                    'fa fa-times',
                    route('transportation-requests.cancel', $row->id),
                    ['btn', 'btn-xs', 'btn-gradient-danger', 'btn-rounded', 'btn-icon', 'cancel-service'],
                    ['data-id' => $row->id, 'title' => trans('end_transportation_service')]
                );
            } else {
                $operate .= BootstrapTableService::button('fa fa-file-pdf-o', route('transportation-requests.fee-receipt', $row->id), ['btn', 'btn-xs', 'btn-gradient-info', 'btn-rounded', 'btn-icon', 'generate-paid-fees-pdf'], ['target' => "_blank", 'data-id' => $row->id, 'title' => trans('generate_pdf') . ' ' . trans('fees')]);
            }

            if ($row->user->hasrole('Student')) {
                $role = "Student";
            } else if ($row->user->hasrole('Teacher')) {
                $role = "Teacher";
            } else {
                $role = 'Staff';
            }

            $tempRow = $row->toArray();
            $tempRow['role'] = $role;
            $tempRow['no'] = $no++;
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function update(Request $request, $id)
    {
        ResponseService::noPermissionThenSendJson(['transportationRequests-edit']);

        $validator = Validator::make($request->all(), [
            'edit_route_id' => 'required|numeric|exists:route_vehicles,id',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::beginTransaction();
            $requestData = [
                'route_vehicle_id' => $request->edit_route_id
            ];

            $transportationPayment = TransportationPayment::find($id);

            $sessionYear = $this->cache->getDefaultSessionYear();
            $today = now();
            $existingPayment = TransportationPayment::where('user_id', $transportationPayment->user_id)
                ->whereNotNull('route_vehicle_id')
                ->where('session_year_id', $sessionYear->id)
                ->where('expiry_date', '>', $today)
                ->where('status', 'paid')
                ->first();

            if ($existingPayment) {
                ResponseService::errorResponse('This user already has an active paid record in the current session year.');
            }

            $routes = RouteVehicle::with('vehicle')->where('id', $request->edit_route_id)->first();

            $assignedCounts = TransportationPayment::selectRaw('route_vehicle_id, COUNT(*) as assigned_students')
                ->where('route_vehicle_id', $request->edit_route_id)
                ->where('status', 'paid')
                ->groupBy('route_vehicle_id')->first();

            if (!empty($routes) && !empty($assignedCounts) && ((int) $routes->vehicle->capacity - (int) $assignedCounts->assigned_students) == 0) {
                ResponseService::errorResponse("No seats left in this vehicle");
            }

            if (!$transportationPayment) {
                return redirect()->back()->with('error', 'Transportation payment not found.');
            }

            // Update attributes
            $transportationPayment->update($requestData);

            DB::commit();
            ResponseService::successResponse('Data updated successfully');
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, 'TransportationRequestController -> update');
            ResponseService::errorResponse();
        }
    }

    public function cancelTransportationService($id)
    {
        ResponseService::noPermissionThenSendJson(['transportationRequests-edit']);

        try {
            DB::beginTransaction();
            $today = now();
            $transportationPayment = TransportationPayment::find($id);

            if (!$transportationPayment) {
                return redirect()->back()->with('error', 'Transportation payment not found.');
            }


            // Update attributes
            $transportationPayment->update(['expiry_date' => $today->format('Y-m-d')]);

            DB::commit();
            ResponseService::successResponse('Service cancelled successfully');
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, 'TransportationRequestController -> cancelTransportationService');
            ResponseService::errorResponse();
        }
    }

    public function getVehicleRoutes($pickupPointId)
    {
        ResponseService::noPermissionThenSendJson(['transportationRequests-list']);
        $validator = Validator::make(['pickup_point_id' => $pickupPointId], [
            'pickup_point_id' => 'required|numeric|exists:pickup_points,id',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $today = now();
            $routes = RouteVehicle::with('vehicle', 'route.shift')
                ->whereHas('route.routePickupPoints', function ($query) use ($pickupPointId) {
                    $query->where('pickup_point_id', $pickupPointId);
                });
            $routes = $routes->get();

            $assignedCounts = TransportationPayment::selectRaw('route_vehicle_id, COUNT(*) as assigned_students')
                ->whereNotNull('route_vehicle_id')
                ->where('status', 'paid')
                ->where('expiry_date', '>', $today)
                ->groupBy('route_vehicle_id')
                ->pluck('assigned_students', 'route_vehicle_id');

            $fees = TransportationFee::where('pickup_point_id', $pickupPointId)->get();

            return response()->json([
                'success' => true,
                'data' => $routes,
                'assignedCounts' => $assignedCounts,
                'fees' => $fees,
            ]);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, 'TransportationRequestController -> getVehicleRoutes');
            return ResponseService::errorResponse();
        }
    }

    public function changeStatusBulk(Request $request)
    {
        ResponseService::noPermissionThenRedirect('transportationRequests-edit');
        $validator = Validator::make($request->all(), [
            'vehicle_route' => 'required|numeric|exists:route_vehicles,id',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            DB::beginTransaction();

            $paymentIds = json_decode($request->ids, true); // decode to array

            TransportationPayment::whereIn('id', $paymentIds)
                ->update(['route_vehicle_id' => $request->vehicle_route]);

            DB::commit();

            ResponseService::successResponse("Status Updated Successfully");
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function offlineEntry()
    {
        ResponseService::noAnyPermissionThenRedirect(['transportationRequests-create']);

        $class_sections = $this->classSection->all(['*'], ['class', 'class.stream', 'section', 'medium']);
        $pickupPoints = pickupPoint::where('status', 1)->get();
        $shifts = Shift::where('status', 1)->get();


        return view('transportation-request.offline_entry', compact('pickupPoints', 'class_sections', 'shifts'));

    }

    public function getStudents($id)
    {
        ResponseService::noPermissionThenRedirect('transportationRequests-create');
        try {
            $students = $this->user->builder()
                ->role('Student')
                ->select('id', 'first_name', 'last_name')
                ->with([
                    'student' => function ($query) {
                        $query->select('id', 'class_section_id', 'user_id', 'guardian_id')
                            ->with([
                                'class_section' => function ($query) {
                                    $query->select('id', 'class_id', 'section_id', 'medium_id')
                                        ->with('class:id,name', 'section:id,name', 'medium:id,name');
                                }
                            ]);
                    }
                ])
                ->whereHas('student', function ($q) use ($id) {
                    $q->where('class_section_id', $id);
                })
                ->get();

            return response()->json([
                'success' => true,
                'data' => $students,
            ]);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, 'TransportationRequestController -> getStudents');
            return ResponseService::errorResponse();
        }

    }
    public function getTeachers()
    {
        ResponseService::noPermissionThenRedirect('transportationRequests-create');
        try {
            $teachers = $this->user->builder()->role('Teacher')->select('*')->get();

            return response()->json([
                'success' => true,
                'data' => $teachers,
            ]);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, 'TransportationRequestController -> getTeacher');
            return ResponseService::errorResponse();
        }

    }
    public function getStaff()
    {
        ResponseService::noPermissionThenRedirect('transportationRequests-create');
        try {
            $staff = $this->user->builder()->select('id', 'first_name', 'last_name', 'image')->has('staff')->with('roles', 'support_school.school:id,name')->whereHas('roles', function ($q) {
                $q->where('custom_role', 1)->whereNot('name', 'Teacher');
            })->get();

            return response()->json([
                'success' => true,
                'data' => $staff,
            ]);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, 'TransportationRequestController -> getTeacher');
            return ResponseService::errorResponse();
        }

    }

    public function offlineEntryStore(Request $request)
    {
        ResponseService::noPermissionThenRedirect('transportationRequests-create');

        $validator = Validator::make(
            $request->all(),
            [
                'user_id' => 'required|numeric|exists:users,id',
                // Remove shift_id from validation, as we will get it from routes table
                // 'shift_id' => 'nullable|numeric|exists:shifts,id',
                'pickup_point_id' => 'required|numeric|exists:pickup_points,id',
                'fee_id' => 'nullable|numeric|exists:transportation_fees,id',
                'route_vehicle_id' => 'required|numeric|exists:route_vehicles,id',
                'amount' => 'nullable|numeric',
                'mode' => 'nullable|in:1,2',
                'cheque_no' => 'required_if:mode,2',
            ],
            [
                'user_id.required' => 'Please select a user.',
                'user_id.exists' => 'Selected user does not exist.',
                // 'shift_id.exists' => 'Selected shift does not exist.',
                'pickup_point_id.required' => 'Please select a pickup point.',
                'pickup_point_id.exists' => 'Selected pickup point does not exist.',
                'fee_id.exists' => 'Selected fee does not exist.',
                'route_vehicle_id.required' => 'Please select a vehicle route.',
                'route_vehicle_id.exists' => 'Selected route vehicle does not exist.',
                'mode.in' => 'Invalid payment mode selected.',
                'cheque_no.required_if' => 'Cheque number is required when payment mode is Cheque.',
            ]
        );

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::beginTransaction();
            $sessionYear = $this->cache->getDefaultSessionYear();
            $today = now();
            $existingPayment = TransportationPayment::where('user_id', $request->user_id)
                ->whereNotNull('route_vehicle_id')
                ->where('session_year_id', $sessionYear->id)
                ->where('expiry_date', '>', $today)
                ->where('status', 'paid')
                ->first();

            if ($existingPayment) {
                ResponseService::errorResponse('This user already has an active paid record in the current session year.');
            }

            // Get the RouteVehicle and its related Route (to get shift_id from routes table)
            $routeVehicle = RouteVehicle::with(['vehicle', 'route'])->where('id', $request->route_vehicle_id)->first();

            // Get shift_id from the related route
            $shiftId = $routeVehicle && $routeVehicle->route ? $routeVehicle->route->shift_id : null;

            $assignedCounts = TransportationPayment::selectRaw('route_vehicle_id, COUNT(*) as assigned_students')
                ->where('route_vehicle_id', $request->route_vehicle_id)
                ->where('status', 'paid')
                ->where('expiry_date', '>', $today)
                ->groupBy('route_vehicle_id')->first();

            if (!empty($assignedCounts)) {
                if (!empty($routeVehicle) && ((int) $routeVehicle->vehicle->capacity - (int) $assignedCounts->assigned_students) == 0) {
                    ResponseService::errorResponse("No seats left in this vehicle");
                }
            }

            if ($request->fee_id) {
                $transportationFee = TransportationFee::where('id', $request->fee_id)->first();
                $expiryDate = null;
                if ($transportationFee) {
                    if (!empty($transportationFee->duration)) {
                        $expiryDate = now()->addDays($transportationFee->duration);
                    }
                }

                $mode = (int) $request->mode;
                $paymentTransactionData = [
                    'user_id' => $request->user_id,
                    'amount' => $request->amount,
                    'payment_gateway' => $mode === 1
                        ? 'cash'
                        : ($mode === 2 ? 'cheque' : null),
                    'order_id' => $request->cheque_no,
                    'payment_status' => 'succeed',
                    'type' => 'transportation_fee',
                    'school_id' => Auth::user()->school_id
                ];

                $paymentTransaction = PaymentTransaction::create($paymentTransactionData);
            } else {
                $expiryDate = $sessionYear->end_date;
            }

            if ($expiryDate == $today->format('Y-m-d') || $expiryDate < $today->format('Y-m-d')) {
                ResponseService::errorResponse('Cannot add Staff/Teacher on last date of session year.');
            }

            $transportationPaymentData = [
                'route_vehicle_id' => $request->route_vehicle_id,
                'shift_id' => $shiftId, // Use shift_id from routes table
                'pickup_point_id' => $request->pickup_point_id,
                'user_id' => $request->user_id,
                'payment_transaction_id' => $paymentTransaction->id ?? null,
                'transportation_fee_id' => $request->fee_id ?? null,
                'amount' => $request->amount ?? 0,
                'paid_at' => now(),
                'session_year_id' => $sessionYear->id,
                'status' => 'paid',
                'expiry_date' => $expiryDate ?? null
            ];

            TransportationPayment::create($transportationPaymentData);

            DB::commit();
            ResponseService::successResponse('Data stored successfully');
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, 'TransportationRequestController -> offlineEntryStore');
            ResponseService::errorResponse();
        }
    }

    public function feeReceipt($id)
    {

        ResponseService::noAnyPermissionThenRedirect(['transportationRequests-receipt']);

        try {

            $TransportationPayment = TransportationPayment::where('status', 'paid')->with('pickupPoint', 'transportationFee', 'paymentTransaction')->where('id', $id)->first();

            $student = $this->user->builder()->role('Student')->select('id', 'first_name', 'last_name')
                ->with([
                    'student' => function ($query) {
                        $query->select('id', 'class_section_id', 'user_id', 'guardian_id', 'admission_no')->with([
                            'class_section' => function ($query) {
                                $query->select('id', 'class_id', 'section_id', 'medium_id')->with('class:id,name', 'section:id,name', 'medium:id,name');
                            }
                        ]);
                    }
                ])->where('id', $TransportationPayment->user_id)->first();

            $school = $this->cache->getSchoolSettings();

            $data = explode("storage/", $school['horizontal_logo'] ?? '');
            $school['horizontal_logo'] = end($data);

            if ($school['horizontal_logo'] == null) {
                $systemSettings = $this->cache->getSystemSettings();
                $data = explode("storage/", $systemSettings['horizontal_logo'] ?? '');
                $school['horizontal_logo'] = end($data);
            }

            $pdf = Pdf::loadView('transportation-request.fee_receipt', compact('school', 'TransportationPayment', 'student'));
            return $pdf->stream('transportation-fees-receipt.pdf');

        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, 'TransportationRequestController -> feeReceipt');
            ResponseService::errorResponse();
        }
    }
}
