<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyHoliday extends Model
{
    protected $table = 'company_holidays';

    protected $fillable = [
        'company_id',
        'holiday_date',
        'description',
        'is_mandatory',
    ];

    protected $casts = [
        'holiday_date' => 'date:Y-m-d',
        'is_mandatory' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
