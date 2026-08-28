<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyDepartmentSchedule extends Model
{
    protected $table = 'company_department_schedules';

    protected $fillable = [
        'company_id',
        'department_name',
        'schedule_entry',
        'schedule_exit',
        'meal_start',
        'meal_end',
        'tolerance_minutes',
        'expected_daily_hours',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'tolerance_minutes' => 'integer',
        'expected_daily_hours' => 'float',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
