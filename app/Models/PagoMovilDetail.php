<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class PagoMovilDetail extends Model
{
    use HasFactory;
    use UsesLandlordConnection;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The primary key is payment_id, not id.
     *
     * @var string
     */
    protected $primaryKey = 'payment_id';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The type of the auto-incrementing ID.
     *
     * @var string
     */
    protected $keyType = 'int';

    protected $fillable = [
        'payment_id',
        'phone',
        'bank',
        'rif',
        'sender_bank',
        'sender_phone',
        'sender_id',
        'payment_date',
        'concept',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
