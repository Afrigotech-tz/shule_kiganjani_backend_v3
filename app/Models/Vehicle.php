<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'vehicle_number',
        'capacity',
        'status',
    ];

    public function routeVehicles()
    {
        return $this->hasMany(RouteVehicle::class, 'vehicle_id');
    }
}
