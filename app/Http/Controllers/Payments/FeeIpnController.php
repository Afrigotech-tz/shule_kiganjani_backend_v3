<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FeeIpnController extends Controller
{
    public function payments(Request $request)
    {
        // test Log
        Log::info('RECEIVED FEES IPN DATA:', ['all_data' => $request->all()]);

        if ($request->school_code) {
            // Retrieve the school's database connection info
            $school = School::on('mysql')->where('code', $request->school_code)->where('installed', 1)->first();

            if (!$school) {
                return back()->withErrors(['school_code' => 'Invalid school identifier.']);
            }

            // Set the dynamic database connection
            Config::set('database.connections.school.database', $school->database_name);
            DB::purge('school');
            DB::connection('school')->reconnect();
            DB::setDefaultConnection('school');

            \Log::info('Payment data saved on the new Switched Database: ' . DB::connection('school')->getDatabaseName());

        }
        


    }

    
}
