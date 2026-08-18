<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceLog extends Model
{
    protected $table = 'attendance_logs';

    protected $fillable = [
        'company_id',
        'intercompania',
        'device_id',
        'employee_id',
        'pin',
        'punch_time',
        'punch_type',
        'verify_type',
        'work_code',
        'raw_line',
    ];

    public function scopeByIntercompania($query, string $intercompania)
    {
        return $query->where('intercompania', $intercompania);
    }


    protected $casts = [
        'punch_time' => 'datetime',
        'punch_type' => 'integer',
        'verify_type' => 'integer',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
