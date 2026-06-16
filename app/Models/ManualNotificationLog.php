<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class ManualNotificationLog extends Model
{
    use UsesLandlordConnection;

    protected $fillable = [
        'title',
        'message',
        'tenant_ids',
        'total_recipients',
        'sent_by',
    ];

    protected function casts(): array
    {
        return [
            'tenant_ids' => 'array',
        ];
    }

    /**
     * Get the landlord user who sent the notification.
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(Landlord::class, 'sent_by');
    }
}
