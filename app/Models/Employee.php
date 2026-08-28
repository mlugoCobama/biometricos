<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $table = 'employees';

    protected $fillable = [
        'company_id',
        'intercompania',
        'pin',
        'first_name',
        'last_name',
        'department',
        'document_number',
        'card_number',
        'privilege',
        'password',
        'status',
    ];

    public function scopeByIntercompania($query, string $intercompania)
    {
        return $query->where('intercompania', $intercompania);
    }


    protected $casts = [
        'privilege' => 'integer',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function fingerprints()
    {
        return $this->hasMany(EmployeeFingerprint::class);
    }

    public function attendanceLogs()
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
