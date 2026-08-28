<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $table = 'companies';

    protected $fillable = [
        'name',
        'code',
        'intercompania',
        'status',
        'report_emails',
    ];

    protected $casts = [
        'report_emails' => 'array',
    ];

    public function scopeByIntercompania($query, string $intercompania)
    {
        return $query->where(function ($q) use ($intercompania) {
            $q->where('intercompania', $intercompania)
              ->orWhere('code', $intercompania);
        });
    }


    public function devices()
    {
        return $this->hasMany(Device::class);
    }

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

    public function attendanceLogs()
    {
        return $this->hasMany(AttendanceLog::class);
    }
}
