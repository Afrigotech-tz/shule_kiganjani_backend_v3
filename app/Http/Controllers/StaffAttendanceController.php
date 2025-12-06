<?php

namespace App\Http\Controllers;

use App\Models\SessionYear;
use App\Repositories\StaffAttendance\StaffAttendanceInterface;
use App\Repositories\Staff\StaffInterface;
use App\Services\CachingService;
use App\Services\ResponseService;
use App\Services\SessionYearsTrackingsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class StaffAttendanceController extends Controller
{

    private StaffAttendanceInterface $staffAttendance;
    private StaffInterface $staff;
    private CachingService $cache;
    private SessionYearsTrackingsService $sessionYearsTrackingsService;

    public function __construct(StaffAttendanceInterface $staffAttendance, StaffInterface $staff, CachingService $cachingService, SessionYearsTrackingsService $sessionYearsTrackingsService)
    {
        $this->staffAttendance = $staffAttendance;
        $this->staff = $staff;
        $this->cache = $cachingService;
        $this->sessionYearsTrackingsService = $sessionYearsTrackingsService;
    }

    public function index()
    {
        ResponseService::noFeatureThenRedirect('Staff Attendance Management');
        ResponseService::noAnyPermissionThenRedirect(['staff-attendance-list']);

        return view('staff-attendance.index');
    }

    public function view()
    {
        ResponseService::noFeatureThenRedirect('Staff Attendance Management');
        ResponseService::noAnyPermissionThenRedirect(['staff-attendance-list']);

        return view('staff-attendance.view');
    }

    public function getAttendanceData(Request $request)
    {
        ResponseService::noFeatureThenRedirect('Staff Attendance Management');
        $response = $this->staffAttendance->builder()->select('type')->where(['date' => date('Y-m-d', strtotime($request->date))])->pluck('type')->first();
        return response()->json($response);
    }

    public function store(Request $request)
    {
        ResponseService::noFeatureThenRedirect('Staff Attendance Management');
        ResponseService::noAnyPermissionThenRedirect(['staff-attendance-create', 'staff-attendance-edit']);

        $request->validate([
            'date' => 'required',
        ]);

        try {
            DB::beginTransaction();
            $attendanceData = array();
            $sessionYear = $this->cache->getDefaultSessionYear();
            $staff_ids = array();
            // dd($request->attendance_data);

            foreach ($request->attendance_data as $value) {
                $data = (object) $value;
                $attendanceData[] = array(
                    "id" => $data->id ?? null,
                    'staff_id' => $data->staff_id,
                    'session_year_id' => $sessionYear->id,
                    'type' => $request->holiday ?? $data->type,
                    'date' => date('Y-m-d', strtotime($request->date)),
                );

                if ($data->type == 0) {
                    $staff_ids[] = $data->staff_id;
                }
            }
            // dd($attendanceData);
            $this->staffAttendance->upsert($attendanceData, ["id"], ["staff_id", "session_year_id", "type", "date"]);

            DB::commit();

            if ($request->absent_notification && !empty($staff_ids)) {
                $date = Carbon::parse(date('Y-m-d', strtotime($request->date)))->format('F jS, Y');
                $title = 'Absent';
                $body = 'You are marked absent on ' . $date;
                $type = "attendance";
                if (!$request->holiday) {
                    send_notification($staff_ids, $title, $body, $type);
                }
            }

            ResponseService::successResponse('Data Stored Successfully');
        } catch (Throwable $e) {
            if (
                Str::contains($e->getMessage(), [
                    'does not exist',
                    'file_get_contents'
                ])
            ) {
                DB::commit();
                ResponseService::warningResponse("Data Stored successfully. But App push notification not send.");
            } else {
                DB::rollback();
                ResponseService::logErrorResponse($e, "Staff Attendance Controller -> Store method");
                ResponseService::errorResponse();
            }
        }
    }

    public function show(Request $request)
    {
        ResponseService::noFeatureThenRedirect('Staff Attendance Management');
        ResponseService::noAnyPermissionThenRedirect(['staff-attendance-list']);

        $sort = $request->input('sort', 'id');
        $order = $request->input('order', 'ASC');
        $search = $request->input('search');

        $date = date('Y-m-d', strtotime($request->date));

        $attendanceData = array();
        $total = 0;

        $attendanceQuery = $this->staffAttendance->builder()->with('user.staff')->where(['date' => $date])->whereHas('user', function ($q) {
            $q->whereNull('deleted_at');
        });

        if ($date != '' && $attendanceQuery->count() > 0) {
            $attendanceData = $attendanceQuery->orderBy($sort, $order)->get();
            // need to add $no to the attendanceData
            $no = 1;
            foreach ($attendanceData as $attendance) {
                $attendance->no = $no++;
            }
            $total = $attendanceData->count();
        } else {
            // Get all staff members for the current session year
            $staffMembers = $this->staff->builder()->with('user');
            if (!empty($search)) {
                $staffMembers->where('user_id', 'like', "%{$search}%");
                $staffMembers->orWhereHas('user', function ($q) use ($search) {
                    $q->where(function ($sub) use ($search) {
                        $sub->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", ["%{$search}%"]);
                    });
                });
            }
            $staffMembers = $staffMembers->get();
            $no = 1;
            foreach ($staffMembers as $staff) {
                $attendanceData[] = [
                    'id' => 'N/A',
                    'no' => $no++,
                    'staff_id' => $staff->user_id,
                    'user' => [
                        'full_name' => $staff->user->full_name,
                        'staff' => [
                            'id' => $staff->id,
                            'user_id' => $staff->user_id ?? '',
                        ]
                    ],
                    'type' => null,
                    'date' => $date
                ];
            }
            $total = count($attendanceData);
        }

        $data = [
            'total' => $total,
            'rows' => $attendanceData
        ];

        return response()->json($data);
    }

    public function attendance_show(Request $request)
    {
        ResponseService::noFeatureThenRedirect('Staff Attendance Management');
        ResponseService::noAnyPermissionThenRedirect(['staff-attendance-list']);

        $offset = request('offset', 0);
        $limit = request('limit');
        $sort = request('sort', 'staff_id');
        $order = request('order', 'ASC');
        $search = request('search');
        $attendanceType = request('attendance_type');

        $date = date('Y-m-d', strtotime(request('date')));

        $validator = Validator::make($request->all(), ['date' => 'required']);
        if ($validator->fails()) {
            ResponseService::errorResponse($validator->errors()->first());
        }

        $sessionYear = $this->cache->getDefaultSessionYear();

        $sql = $this->staffAttendance->builder()->where(['date' => $date, 'session_year_id' => $sessionYear->id])->with('user.staff');

        if ($attendanceType != null) {
            $sql = $sql->where('type', $attendanceType);
        }

        if ($search) {
            $sql = $sql->whereHas('user', function ($q) use ($search) {
                $q->where('first_name', 'like', '%' . $search . '%')
                    ->orWhere('last_name', 'like', '%' . $search . '%')
                    ->orwhereRaw("concat(users.first_name,' ',users.last_name) LIKE '%" . $search . "%'")
                    ->orwhere('id', 'like', '%' . $search . '%');
            });
        }

        $total = $sql->count();
        $sql = $sql->orderBy($sort, $order);

        if ($limit) {
            if ($offset >= $total && $total > 0) {
                $lastPage = floor(($total - 1) / $limit) * $limit; // calculate last page offset
                $offset = $lastPage;
            }
            $sql = $sql->skip($offset)->take($limit);
        }

        $attendanceData = $sql->get();

        $no = 1;
        foreach ($attendanceData as $attendance) {
            $attendance->no = $no++;
        }

        $data = [
            'total' => $total,
            'rows' => $attendanceData
        ];

        return response()->json($data);
    }

    public function monthWiseIndex()
    {
        ResponseService::noFeatureThenRedirect('Staff Attendance Management');
        ResponseService::noAnyPermissionThenRedirect(['staff-attendance-list']);

        $sessionYears = SessionYear::pluck('name', 'id');
        return view('staff-attendance.month-wise', compact('sessionYears'));
    }

    public function monthWiseShow(Request $request, $user_id = null)
    {
        ResponseService::noFeatureThenRedirect('Staff Attendance Management');
        // ResponseService::noAnyPermissionThenRedirect(['staff-attendance-list']);
        $limit = request('limit');
        $offset = request('offset', 0);

        $sql = $this->staff->builder()->with('user')->whereHas('staffAttendance', function ($q) use ($request) {
            $q->whereMonth('date', $request->month)
                ->where('session_year_id', $request->session_year_id);
        })->orderBy('user_id', 'ASC');

        if ($user_id) {
            $sql = $sql->where('user_id', $user_id);
        }

        if ($request->search) {
            $sql = $sql->whereHas('user', function ($q) use ($request) {
                $q->where('first_name', 'like', '%' . $request->search . '%')
                    ->orWhere('last_name', 'like', '%' . $request->search . '%')
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE '%" . $request->search . "%'");
            });
        }

        $total = $sql->count();
        if ($limit) {
            if ($offset >= $total && $total > 0) {
                $lastPage = floor(($total - 1) / $limit) * $limit; // calculate last page offset
                $offset = $lastPage;
            }
            $sql = $sql->skip($offset)->take($limit);
        }
        $res = $sql->get();
        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();


        $month = $request->month;
        $date = Carbon::create(null, $month, 1);

        foreach ($res as $row) {
            $staffAttendance = ['full_name' => $row->user->full_name, 'user_id' => $row->user_id];

            for ($day = 1; $day <= $date->daysInMonth; $day++) {
                $currentDate = $date->copy()->day($day)->format('Y-m-d');
                $attendance = $row->staffAttendance()->where('staff_id', $row->user_id)->where('date', $currentDate)->first();
                $staffAttendance["day_$day"] = $attendance ? $attendance->type : null;

            }
            $tempRow[] = $staffAttendance;
            $rows = $tempRow;
        }
        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function yourIndex()
    {
        ResponseService::noFeatureThenRedirect('Staff Attendance Management');

        $sessionYears = SessionYear::pluck('name', 'id');
        $sessionYear = $this->cache->getDefaultSessionYear();

        return view('staff-attendance.your-index', compact('sessionYears', 'sessionYear'));
    }
}