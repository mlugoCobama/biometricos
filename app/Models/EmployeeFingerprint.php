<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeFingerprint extends Model
{
    protected $table = 'employee_fingerprints';

    protected $fillable = [
        'employee_id',
        'finger_index',
        'template_data',
        'template_version',
        'size',
        'valid',
    ];

    protected $casts = [
        'finger_index' => 'integer',
        'template_version' => 'integer',
        'size' => 'integer',
        'valid' => 'boolean',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
