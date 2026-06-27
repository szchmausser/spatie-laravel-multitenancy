<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class DeviceHeartbeat extends Model
{
    use UsesLandlordConnection;

    protected $fillable = [
        'device_id',
        'battery_level',
        'pending_count',
        'ip',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
