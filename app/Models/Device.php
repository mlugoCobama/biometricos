<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    protected $table = 'devices';

    protected $fillable = [
        'company_id',
        'intercompania',
        'name',
        'serial_number',
        'ip_address',
        'mac_address',
        'push_version',
        'firmware_version',
        'user_count',
        'fingerprint_count',
        'att_log_count',
        'last_heartbeat',
        'status',
        'location',
    ];

    public function scopeByIntercompania($query, string $intercompania)
    {
        return $query->where('intercompania', $intercompania);
    }


    protected $casts = [
        'last_heartbeat' => 'datetime',
        'user_count' => 'integer',
        'fingerprint_count' => 'integer',
        'att_log_count' => 'integer',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function commands()
    {
        return $this->hasMany(DeviceCommand::class);
    }

    public function attendanceLogs()
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function pendingCommands()
    {
        return $this->hasMany(DeviceCommand::class)->where('status', 'pending');
    }
}
