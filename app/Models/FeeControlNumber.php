<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use App\Traits\DateFormatTrait;

class FeeControlNumber extends Model
{
    use HasFactory, DateFormatTrait;

    protected $fillable = [
        'school_id',
        'student_id',
        'fees_id',
        'fee_type',
        'class_id',
        'session_year_id',
        'control_number',
        'amount_required',
        'amount_paid',
        'balance',
        'status',
        'payload',
        'gateway_created_at'
    ];

    protected $casts = [
        'payload' => 'array',
        'gateway_created_at' => 'datetime',
        'amount_required' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'balance' => 'decimal:2'
    ];

    public function student()
    {
        return $this->belongsTo(Students::class, 'student_id')->withTrashed();
    }

    public function fee()
    {
        return $this->belongsTo(Fee::class, 'fees_id')->withTrashed();
    }

    public function class()
    {
        return $this->belongsTo(ClassSchool::class, 'class_id')->withTrashed();
    }

    public function session_year()
    {
        return $this->belongsTo(SessionYear::class)->withTrashed();
    }

    public function school()
    {
        return $this->belongsTo(School::class)->withTrashed();
    }

    public function scopeOwner($query)
    {
        if (Auth::user()) {
            if (Auth::user()->hasRole('Super Admin')) {
                return $query;
            }

            if (Auth::user()->hasRole('School Admin')) {
                return $query->where('school_id', Auth::user()->school_id);
            }

            if (Auth::user()->hasRole('Student')) {
                return $query->where('school_id', Auth::user()->school_id);
            }
        }

        return $query;
    }

    public function getCreatedAtAttribute()
    {
        return $this->formatDateValue($this->getRawOriginal('created_at'));
    }

    public function getUpdatedAtAttribute()
    {
        return $this->formatDateValue($this->getRawOriginal('updated_at'));
    }
}
