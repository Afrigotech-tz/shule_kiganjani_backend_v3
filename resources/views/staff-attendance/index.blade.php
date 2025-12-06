@extends('layouts.master')

@section('title')
    {{ __('Staff Attendance') }}
@endsection

@section('content')
    <div class="content-wrapper">
        <div class="page-header">
            <h3 class="page-title">
                {{ __('manage_staff_attendance') }}
            </h3>
        </div>
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">
                            {{ __('create_staff_attendance') }}
                        </h4>
                        <form action="{{ route('staff-attendance.store') }}" class="create-form attendance-table" id="formdata">
                            @csrf
                            <div class="row" id="toolbar">
                                <div class="form-group col-sm-12 col-md-4">
                                    {!! Form::text('date', null, ['required', 'placeholder' => __('date'), 'class' => 'datepicker-popup form-control', 'id' => 'date','data-date-end-date'=>"0d"]) !!}
                                    <span class="input-group-addon input-group-append"></span>
                                </div>
                                <div class="form-group col-sm-12 col-md-3 holiday-div">
                                    <div class="form-check">
                                        <label class="form-check-label">
                                            <input type="checkbox" class="form-check-input" name="holiday" id="holiday" value="0">
                                            {{ __('holiday') }}
                                            <i class="input-helper"></i>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-12">

                                </div>
                                <div class="form-group col-sm-12 col-md-10">
                                    <div class="input-group mb-3">
                                        <div class="input-group-prepend">
                                          <div class="input-group-text">
                                            <input type="checkbox" name="absent_notification" class="cursor-pointer" aria-label="Checkbox for following text input" id="gridCheck">
                                          </div>
                                        </div>
                                        <label class="form-control cursor-pointer" for="gridCheck">
                                            {{ __('send_a_notification_to_staff_if_they_are_absent') }}
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="show_staff_list">
                                <table aria-describedby="mydesc" class='table staff_table' id='table_list'
                                       data-toggle="table" data-url="{{ route('staff-attendance.show',[1]) }}" data-click-to-select="true"
                                       data-side-pagination="server" data-pagination="false"
                                       data-page-list="[5, 10, 20, 50, 100, 200]" data-search="true" data-show-refresh="true"
                                       data-toolbar="#toolbar" data-show-columns="true" data-trim-on-search="false" data-mobile-responsive="true" data-sort-name="id"
                                       data-sort-order="asc" data-maintain-selected="true" data-export-data-type='all' data-show-export="true"
                                       data-export-options='{ "fileName": "staff-attendance-<?= date('d-m-y') ?>" ,"ignoreColumn": ["operate"]}'
                                       data-query-params="attendanceQueryParams" data-escape="true">
                                    <thead>
                                    <tr>
                                        <th scope="col" data-field="id" data-sortable="false" data-visible="false"> {{ __('id') }}</th>
                                        <th scope="col" data-field="no">{{ __('no.') }}</th>
                                        <th scope="col" data-field="staff_id" data-sortable="false" data-visible="false" data-formatter="addStaffIdInputAttendance"> {{ __('staff_id') }}</th>
                                        <th scope="col" data-field="user.staff.user_id" data-sortable="false">{{ __('user_id') }}</th>
                                        <th scope="col" data-field="user.full_name" data-escape="false">{{ __('name') }} </th>
                                        <th scope="col" data-field="type" data-formatter="addStaffRadioInputAttendance">{{ __('type') }} </th>
                                    </tr>
                                    </thead>
                                </table>
                            </div>
                            <input class="btn btn-theme btn_attendance mt-4 float-right" id="create-btn" type="submit" value={{ __('submit') }}>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')

    <script>
        $('#date').on('input change', function () {
            $('.staff_table').bootstrapTable('refresh');
        });

        $('.btn_attendance').hide();
        $('.holiday-div').hide();

        function set_data() {
            $(document).ready(function () {
                date = $('#date').val();

                if (date != '') {
                    $('.btn_attendance').show();
                    $('.holiday-div').show();
                } else {
                    $('.btn_attendance').hide();
                    $('.holiday-div').hide();
                }
            });
        }

        $('#date').on('change', function () {
            set_data();
        });
    </script>

    <script>
        $('input[name="holiday"]').click(function () {
            date = $('#date').val();
            checkBox = document.getElementById('holiday');
            if (date != '') {
                Swal.fire({
                    title: "{{ __('are_you_sure') }}",
                    text: "",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#d33',
                    confirmButtonText: "{{ __('Yes') }}",
                    cancelButtonText: window.trans["Cancel"]
                }).then((result) => {
                    if (checkBox.checked) {
                        if (result.isConfirmed == true) {
                            $("#holiday").val(3);
                            $('input[name="holiday"]').prop('checked', true);
                            $('.type').prop('required', false);
                            $('#table_list').slideUp(500);
                        } else {
                            checkBox.checked = false;
                        }
                    } else {
                        if (result.isConfirmed == true) {
                            $("#holiday").val(0);
                            $('#table_list').slideDown(500);
                            $('.type').prop('required', true);
                            return true;
                        } else {
                            checkBox.checked = true;
                        }

                    }
                })
            }
        });
    </script>
    <script>
        $('#date').on('change , input', function () {
            date = $('#date').val();
            $.ajax({
                url: "{{ url('staff-attendance/getAttendanceData') }}",
                type: "GET",
                data: {
                    date: date
                },
                success: function (response) {
                    if (response == 3) {
                        $('input[name="holiday"]').attr('checked', true);
                        $("#holiday").val(3);
                        $('.type').prop('required', false);
                        $('#table_list').slideUp(500);
                    } else {
                        $('input[name="holiday"]').attr('checked', false);
                        $("#holiday").val(0);
                        $('#table_list').slideDown(500);
                        $('.type').prop('required', true);
                    }
                }
            });
        });
    </script>

{{-- =================== --}}

<script>
    let attendanceState = {};
    // Save state when attendance changes
    $('#table_list').on('change', 'input[type="radio"]', function () {
        let staffNo = $(this).data('id');
        let attendanceType = $(this).val();
        attendanceState[staffNo] = attendanceType;
    });
    

    // Initialize table and restore attendance state
    $('#table_list').on('load-success.bs.table', function () {
        restoreAttendanceState();
    });

    // Function to restore attendance state
    function restoreAttendanceState() {
        for (let staffNo in attendanceState) {
            $(`input[type="radio"][data-id="`+staffNo+`"][value="${attendanceState[staffNo]}"]`).prop('checked', true);
        }
    }
</script>

@endsection 