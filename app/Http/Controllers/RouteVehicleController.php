<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Repositories\RouteVehicle\RouteVehicleRepositoryInterface;
use App\Repositories\Transportation\VehicleRepositoryInterface;
use App\Repositories\Shift\ShiftInterface;
use App\Services\CachingService;
use App\Repositories\User\UserInterface;
use App\Models\Route;
use App\Models\TransportationPayment;
use Carbon\Carbon;
use Throwable;
use Illuminate\Validation\Rule;
use App\Services\BootstrapTableService;
use App\Services\ResponseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RouteVehicleController extends Controller
{
    private RouteVehicleRepositoryInterface $routeVehicle;
    private VehicleRepositoryInterface $vehicle;
    private UserInterface $user;
    private ShiftInterface $shift;
    private CachingService $cache;

    public function __construct(RouteVehicleRepositoryInterface $routeVehicle, VehicleRepositoryInterface $vehicle, UserInterface $user, ShiftInterface $shift, CachingService $cache)
    {
        $this->routeVehicle = $routeVehicle;
        $this->vehicle = $vehicle;
        $this->user = $user;
        $this->shift = $shift;
        $this->cache = $cache;
    }

    public function index()
    {
        ResponseService::noAnyPermissionThenSendJson(['RouteVehicle-list']);
        $routeVehicles = $this->routeVehicle->all();
        $routes = Route::with('shift')->where('status', 1)->get();
        $vehicles = $this->vehicle->builder()->where('status', 1)->get();
        $shifts = $this->shift->all();
        $drivers = $this->user->builder()
            ->where(function ($query) {
                $query->whereHas('roles', function ($q) {
                    $q->where('custom_role', 0);
                })->WhereHas('roles', function ($q) {
                    $q->where('name', 'Driver');
                });
            })
            ->with('staff', 'roles', 'support_school.school')->get();
        $helpers = $this->user->builder()
            ->where(function ($query) {
                $query->whereHas('roles', function ($q) {
                    $q->where('custom_role', 0);
                })->WhereHas('roles', function ($q) {
                    $q->where('name', 'Helper');
                });
            })
            ->with('staff', 'roles', 'support_school.school')->get();
        return view('route-vehicle.index', compact('routeVehicles', 'vehicles', 'drivers', 'helpers', 'routes', 'shifts'));
    }

    public function store(Request $request)
    {
        ResponseService::noAnyPermissionThenSendJson(['RouteVehicle-create']);

        $validator = Validator::make(
            $request->all(),
            [
                'route_id' => ['required', 'exists:routes,id'],
                'vehicle_id' => ['required', 'exists:vehicles,id'],
                'driver_id' => [
                    'required',
                    'exists:users,id',

                ],
                'helper_id' => [
                    'required',
                    'exists:users,id',

                ],

                'pickup_trip_start_time' => ['required', 'date_format:H:i', 'before:pickup_trip_end_time'],
                'pickup_trip_end_time' => ['required', 'date_format:H:i', 'after:pickup_trip_start_time'],
                'drop_trip_start_time' => ['required', 'date_format:H:i', 'before:drop_trip_end_time'],
                'drop_trip_end_time' => ['required', 'date_format:H:i', 'after:drop_trip_start_time'],
            ],
            [
                'pickup_trip_start_time.before' => 'The pickup trip start time must be before the pickup trip end time.',
                'pickup_trip_end_time.after' => 'The pickup trip end time must be after the pickup trip start time.',
                'drop_trip_start_time.before' => 'The drop trip start time must be before the drop trip end time.',
                'drop_trip_end_time.after' => 'The drop trip end time must be after the drop trip start time.',
            ]
        );

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::beginTransaction();
            $user = auth()->user();
            $sessionYear = $this->cache->getDefaultSessionYear();

            $today = Carbon::today()->toDateString();
            $schoolSettings = $this->cache->getSchoolSettings();

            // Get the route to fetch its shift_id
            $route = Route::with('pickupPoints')->findOrFail($request->route_id);
            $earliestPickup = $route->pickupPoints->min(fn($p) => $p->pivot->pickup_time);
            $latestPickup = $route->pickupPoints->max(fn($p) => $p->pivot->pickup_time);
            $earliestDrop = $route->pickupPoints->min(fn($p) => $p->pivot->drop_time);
            $latestDrop = $route->pickupPoints->max(fn($p) => $p->pivot->drop_time);

            if (Carbon::parse($request->pickup_trip_start_time) >= Carbon::parse($earliestPickup)) {
                ResponseService::validationError(
                    "Pickup trip start time must be less than " . Carbon::parse($earliestPickup)->format($schoolSettings['time_format']) . ". <br> Because Route's earliest pickup point time is " . Carbon::parse($earliestPickup)->format($schoolSettings['time_format'])
                );
            }

            if (Carbon::parse($request->pickup_trip_end_time) <= Carbon::parse($latestPickup)) {
                ResponseService::validationError(
                    "Pickup trip end time must be greater than " . Carbon::parse($latestPickup)->format($schoolSettings['time_format']) . ". <br> Because Route's latest pickup point time is " . Carbon::parse($latestPickup)->format($schoolSettings['time_format'])
                );
            }

            if (Carbon::parse($request->drop_trip_start_time) >= Carbon::parse($earliestDrop)) {
                ResponseService::validationError(
                    "Drop trip start time must be less than " . Carbon::parse($earliestDrop)->format($schoolSettings['time_format']) . ". <br> Because Route's earliest drop point time is " . Carbon::parse($earliestDrop)->format($schoolSettings['time_format'])
                );
            }

            if (Carbon::parse($request->drop_trip_end_time) <= Carbon::parse($latestDrop)) {
                ResponseService::validationError(
                    "Drop trip end time must be greater than " . Carbon::parse($latestDrop)->format($schoolSettings['time_format']) . ". <br> Because Route's latest drop point time is " . Carbon::parse($latestDrop)->format($schoolSettings['time_format'])
                );
            }

            $data = [
                'route_id' => $request->route_id,
                'vehicle_id' => $request->vehicle_id,
                'driver_id' => $request->driver_id ?? null,
                'helper_id' => $request->helper_id ?? null,
                'status' => $request->status ?? 1,
                'pickup_start_time' => $request->pickup_trip_start_time,
                'pickup_end_time' => $request->pickup_trip_end_time,
                'drop_start_time' => $request->drop_trip_start_time,
                'drop_end_time' => $request->drop_trip_end_time,
            ];

            $this->routeVehicle->create($data);


            DB::commit();
            ResponseService::successResponse('Vehicle Route created successfully');
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, "RouteVehicleController -> store");
            ResponseService::errorResponse();
        }
    }

    public function show()
    {
        ResponseService::noAnyPermissionThenSendJson(['RouteVehicle-list']);

        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'desc');
        $search = request('search');
        $showDeleted = request('show_deleted');

        $sql = $this->routeVehicle->builder()
            ->with(['vehicle', 'driver', 'helper', 'route.shift']) // preload relationships
            ->when(!empty($showDeleted), function ($query) {
                $query->onlyTrashed();
            });

        if (!empty($search)) {
            $sql->where(function ($q) use ($search) {
                $q->whereHas('vehicle', function ($v) use ($search) {
                    $v->where('name', 'LIKE', "%$search%");
                });

                $q->whereHas('route', function ($r) use ($search) {
                    $r->where('name', 'LIKE', "%$search%");
                })->whereHas('route.shift', function ($s) use ($search) {
                    $s->where('name', 'LIKE', "%$search%");
                });


                $q->orWhereHas('driver', function ($d) use ($search) {
                    $d->where('first_name', 'LIKE', "%$search%")
                        ->orWhere('last_name', 'LIKE', "%$search%")
                        ->orWhereRaw("concat(first_name,' ',last_name) LIKE '%" . $search . "%'");
                });

                $q->orWhereHas('helper', function ($h) use ($search) {
                    $h->where('first_name', 'LIKE', "%$search%")
                        ->orWhere('last_name', 'LIKE', "%$search%")
                        ->orWhereRaw("concat(first_name,' ',last_name) LIKE '%" . $search . "%'");
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

        $bulkData = [];
        $bulkData['total'] = $total;
        $rows = [];
        $no = $offset + 1;

        $baseUrl = url('/');
        $baseUrlWithoutScheme = preg_replace("(^https?://)", "", $baseUrl);
        $baseUrlWithoutScheme = str_replace("www.", "", $baseUrlWithoutScheme);

        foreach ($res as $row) {
            $operate = '';
            if ($showDeleted) {
                $operate .= BootstrapTableService::menuRestoreButton('restore', route('route-vehicle.restore', $row->id));
                $operate .= BootstrapTableService::menuTrashButton('delete', route('route-vehicle.trash', $row->id));
            } else {
                $operate .= BootstrapTableService::menuEditButton('edit', route('route-vehicle.update', $row->id));
                $operate .= BootstrapTableService::menuDeleteButton('delete', route('route-vehicle.destroy', $row->id));
            }

            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['status'] = $row->status ? __('active') : __('inactive');
            $tempRow['operate'] = BootstrapTableService::menuItem($operate);
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function update(Request $request, $id)
    {
        ResponseService::noPermissionThenSendJson(['RouteVehicle-edit']);

        $validator = Validator::make($request->all(), [
            'edit_route_id' => ['required', 'exists:routes,id'],
            'edit_vehicle_id' => ['required', 'exists:vehicles,id'],
            'edit_driver_id' => [
                'nullable',
                'exists:users,id',
            ],
            'edit_helper_id' => [
                'nullable',
                'exists:users,id',
            ],

            'edit_pickup_trip_start_time' => ['required', 'date_format:H:i'],
            'edit_pickup_trip_end_time' => ['required', 'date_format:H:i'],
            'edit_drop_trip_start_time' => ['required', 'date_format:H:i'],
            'edit_drop_trip_end_time' => ['required', 'date_format:H:i'],
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::beginTransaction();
            $user = auth()->user();
            $sessionYear = $this->cache->getDefaultSessionYear();

            $today = Carbon::today()->toDateString();
            $schoolSettings = $this->cache->getSchoolSettings();
            $transportationPaymentsCount = TransportationPayment::where('route_vehicle_id', $id)->count();
            if ($transportationPaymentsCount > 0) {
                $validator = Validator::make($request->all(), [
                    'edit_driver_id' => [
                        'required',
                        'exists:users,id',
                    ],
                    'edit_helper_id' => [
                        'required',
                        'exists:users,id',
                    ],
                ]);

                if ($validator->fails()) {
                    ResponseService::validationError($validator->errors()->first());
                }
            }

            // Get the route to fetch its shift_id
            $route = Route::with('pickupPoints')->findOrFail($request->edit_route_id);
            $earliestPickup = $route->pickupPoints->min(fn($p) => $p->pivot->pickup_time);
            $latestPickup = $route->pickupPoints->max(fn($p) => $p->pivot->pickup_time);
            $earliestDrop = $route->pickupPoints->min(fn($p) => $p->pivot->drop_time);
            $latestDrop = $route->pickupPoints->max(fn($p) => $p->pivot->drop_time);

            if (Carbon::parse($request->edit_pickup_trip_start_time) >= Carbon::parse($earliestPickup)) {
                ResponseService::validationError(
                    "Pickup trip start time must be less than " . Carbon::parse($earliestPickup)->format($schoolSettings['time_format']) . " <br> Because Route's earliest pickup point time is " . Carbon::parse($earliestPickup)->format($schoolSettings['time_format'])
                );
            }

            if (Carbon::parse($request->edit_pickup_trip_end_time) <= Carbon::parse($latestPickup)) {
                ResponseService::validationError(
                    "Pickup trip end time must be greater than " . Carbon::parse($latestPickup)->format($schoolSettings['time_format']) . " <br> Because Route's latest pickup point time is " . Carbon::parse($latestPickup)->format($schoolSettings['time_format'])
                );
            }

            if (Carbon::parse($request->edit_drop_trip_start_time) >= Carbon::parse($earliestDrop)) {
                ResponseService::validationError(
                    "Drop trip start time must be less than " . Carbon::parse($earliestDrop)->format($schoolSettings['time_format']) . " <br> Because Route's earliest drop point time is " . Carbon::parse($earliestDrop)->format($schoolSettings['time_format'])
                );
            }

            if (Carbon::parse($request->edit_drop_trip_end_time) <= Carbon::parse($latestDrop)) {
                ResponseService::validationError(
                    "Drop trip end time must be greater than " . Carbon::parse($latestDrop)->format($schoolSettings['time_format']) . " <br> Because Route's latest drop point time is " . Carbon::parse($latestDrop)->format($schoolSettings['time_format'])
                );
            }

            $data = [
                'route_id' => $request->edit_route_id,
                'vehicle_id' => $request->edit_vehicle_id,
                'driver_id' => $request->edit_driver_id ?? null,
                'helper_id' => $request->edit_helper_id ?? null,
                'pickup_start_time' => $request->edit_pickup_trip_start_time,
                'pickup_end_time' => $request->edit_pickup_trip_end_time,
                'drop_start_time' => $request->edit_drop_trip_start_time,
                'drop_end_time' => $request->edit_drop_trip_end_time,
            ];

            // Call repository update
            $this->routeVehicle->update($id, $data);

            DB::commit();
            ResponseService::successResponse('Vehicle Route updated successfully');
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, 'RouteVehicleController -> update');
            ResponseService::errorResponse();
        }
    }
    public function destroy($id)
    {
        ResponseService::noPermissionThenSendJson(['RouteVehicle-delete']);

        try {
            DB::beginTransaction();

            // Find the vehicle
            $routeVehicle = $this->routeVehicle->findById($id);

            if (!$routeVehicle) {
                ResponseService::errorResponse('Vehicle not found.');
            }

            $transportationPaymentsCount = TransportationPayment::where('route_vehicle_id', $id)->count();
            if ($transportationPaymentsCount > 0) {
                ResponseService::errorResponse('Cannot delete this Vehicle Route because it is associated with existing Transportation Payments.');
            }

            $this->routeVehicle->builder()
                ->where('id', $id)
                ->update(['status' => 0]);
            // Soft delete vehicle
            $routeVehicle->delete();

            DB::commit();
            ResponseService::successResponse('Vehicle Route deleted successfully');
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, "RouteVehicleController -> destroy method");
            ResponseService::errorResponse();
        }
    }

    public function restore(int $id)
    {
        ResponseService::noPermissionThenSendJson(['RouteVehicle-delete']);
        try {
            // Restore soft-deleted vehicle
            $this->routeVehicle->findOnlyTrashedById($id)->restore();
            $this->routeVehicle->builder()
                ->where('id', $id)
                ->update(['status' => 1]);

            ResponseService::successResponse("Vehicle Route restored successfully");
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, "RouteVehicleController -> restore");
            ResponseService::errorResponse();
        }
    }
    public function trash($id)
    {
        ResponseService::noPermissionThenSendJson(['RouteVehicle-delete']);

        try {
            $transportationPaymentsCount = TransportationPayment::where('route_vehicle_id', $id)->count();
            if ($transportationPaymentsCount > 0) {
                ResponseService::errorResponse('Cannot delete this Vehicle Route because it is associated with existing Transportation Payments.');
            }
            $vehicle = $this->routeVehicle->builder()->withTrashed()->where('id', $id)->firstOrFail();

            $vehicle->forceDelete();

            ResponseService::successResponse("Vehicle Route deleted permanently");
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, "RouteVehicleController -> trash", 'cannot_delete_because_data_is_associated_with_other_data');
            ResponseService::errorResponse();
        }
    }
}
