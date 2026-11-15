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
    /**
     * Define the fields accepted when creating or updating the model.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'product_id',
        'quantity',
        'idempotency_key',
        'status',
        'expires_at',
        'finalized_at',
        'created_at',
        'updated_at'
    ];

    /**
     * Preserve microseconds in reservation deadlines and finalization timestamps.
     *
     * @var string
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * Convert stored values to the types used by the application.
     *
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
        'updated_at' => 'immutable_datetime'
    ];

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
     * Confirm stock after the service has locked this reservation and its product.
     *
     * @param Product $product
     * @param CarbonImmutable $now
     * @return void
     */
    public function setConfirmed(Product $product, CarbonImmutable $now): void
    {
        $product->reserved_stock -= $this->quantity;
        $product->confirmed_stock += $this->quantity;
        $product->save();

        $this->status = ReservationStatusEnum::CONFIRMED;
        $this->finalized_at = $now;
        $this->updated_at = $now;
        $this->save();
    }

    /**
     * Return cancelled stock within the service's existing transaction.
     *
     * @param Product $product
     * @param CarbonImmutable $now
     * @return void
     */
    public function setCancelled(Product $product, CarbonImmutable $now): void
    {
        $product->reserved_stock -= $this->quantity;
        $product->available_stock += $this->quantity;
        $product->save();

        $this->status = ReservationStatusEnum::CANCELLED;
        $this->finalized_at = $now;
        $this->updated_at = $now;
        $this->save();
    }

    /**
     * Return expired stock after the locked deadline check has passed.
     *
     * @param Product $product
     * @param CarbonImmutable $now
     * @return void
     */
    public function setExpired(Product $product, CarbonImmutable $now): void
    {
        $product->reserved_stock -= $this->quantity;
        $product->available_stock += $this->quantity;
        $product->save();

        $this->status = ReservationStatusEnum::EXPIRED;
        $this->finalized_at = $now;
        $this->updated_at = $now;
        $this->save();
    }
}
