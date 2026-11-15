<?php

namespace App\Models;

use App\Enums\ReservationStatusEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $created_at
 */
class Reservation extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'product_id',
        'quantity',
        'idempotency_key',
        'status',
        'expires_at',
        'finalized_at',
        'created_at',
        'updated_at',
    ];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * Get the reservation owner.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the reserved product.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'user_id' => 'integer',
        'product_id' => 'integer',
        'quantity' => 'integer',
        'status' => ReservationStatusEnum::class,
        'expires_at' => 'immutable_datetime',
        'finalized_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];
}
