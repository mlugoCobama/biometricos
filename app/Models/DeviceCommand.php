<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceCommand extends Model
{
    protected $table = 'device_commands';

    protected $fillable = [
        'device_id',
        'command_type',
        'command_text',
        'status',
        'return_code',
        'response_text',
        'sent_at',
        'executed_at',
    ];

    protected $casts = [
        'return_code' => 'integer',
        'sent_at' => 'datetime',
        'executed_at' => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}
