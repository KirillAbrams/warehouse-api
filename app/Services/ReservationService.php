<?php

namespace App\Services;

use App\Contracts\Clock;
use App\Enums\ReservationStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReservationService
{
    public function __construct(private Clock $clock) {}

    /**
     * Reserve stock atomically or return an identical earlier request.
     *
     * @return array{reservation: Reservation, created: bool}
     */
    public function create(
        int $userId,
        int $productId,
        int $quantity,
        string $key,
    ): array {
        // Persist the reservation and stock counters in the same transaction.
        return DB::transaction(function () use ($userId, $productId, $quantity, $key): array {
            // Lock the user/key pair before its reservation row exists.
            // Hash collisions only serialize otherwise independent requests.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["reservation:create:{$userId}:{$key}"]);

            $existing = Reservation::query()
                ->where('user_id', $userId)
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->product_id !== $productId || $existing->quantity !== $quantity) {
                    throw new ApiException(
                        409,
                        'idempotency_conflict',
                        'This key was used with a different product or quantity.',
                    );
                }

                return ['reservation' => $existing, 'created' => false];
            }

            $product = Product::query()
                ->lockForUpdate()
                ->find($productId)
                ?? throw ApiException::notFound('product');

            if ($product->available_stock < $quantity) {
                throw new ApiException(409, 'insufficient_stock', 'Not enough stock is available.');
            }

            $now = $this->clock->now();
            $product->available_stock -= $quantity;
            $product->reserved_stock += $quantity;
            $product->save();

            $reservation = Reservation::query()->create([
                'user_id' => $userId,
                'product_id' => $productId,
                'quantity' => $quantity,
                'idempotency_key' => $key,
                'status' => ReservationStatusEnum::RESERVED,
                'created_at' => $now,
                'updated_at' => $now,
                'expires_at' => $now->addSeconds(config()->integer('reservations.ttl_seconds')),
            ]);

            return ['reservation' => $reservation, 'created' => true];
        }, attempts: 3);
    }

    /**
     * Confirm or cancel an owned reservation before its deadline.
     */
    public function transition(int $userId, int $reservationId, ReservationStatusEnum $target): Reservation
    {
        if (! \in_array($target, [ReservationStatusEnum::CONFIRMED, ReservationStatusEnum::CANCELLED], true)) {
            throw new InvalidArgumentException('Only confirm and cancel are client transitions.');
        }

        // Persist the final state and stock counters atomically.
        return DB::transaction(function () use ($userId, $reservationId, $target): Reservation {
            $reservation = Reservation::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->find($reservationId)
                ?? throw ApiException::notFound('reservation');

            if ($reservation->status === $target) {
                return $reservation;
            }

            if ($reservation->status === ReservationStatusEnum::EXPIRED) {
                throw new ApiException(409, 'reservation_expired', 'The reservation has expired.');
            }

            if ($reservation->status !== ReservationStatusEnum::RESERVED) {
                throw new ApiException(
                    409,
                    'invalid_transition',
                    'The reservation is already in a different final state.',
                );
            }

            $product = Product::query()
                ->lockForUpdate()
                ->findOrFail($reservation->product_id);

            // Read wall-clock time after acquiring both row locks.
            // Transaction start time may be stale after waiting for a lock.
            $now = $this->clock->now();

            if ($now->greaterThanOrEqualTo($reservation->expires_at)) {
                throw new ApiException(409, 'reservation_expired', 'The reservation has expired.');
            }

            $product->reserved_stock -= $reservation->quantity;

            if ($target === ReservationStatusEnum::CONFIRMED) {
                $product->confirmed_stock += $reservation->quantity;
            } else {
                $product->available_stock += $reservation->quantity;
            }

            $product->save();
            $reservation->status = $target;
            $reservation->finalized_at = $now;
            $reservation->updated_at = $now;
            $reservation->save();

            return $reservation;
        }, attempts: 3);
    }

    /**
     * Release reserved stock once after its deadline.
     */
    public function expire(int $reservationId): bool
    {
        // Finalize the reservation and release stock in one transaction.
        return DB::transaction(function () use ($reservationId): bool {
            $reservation = Reservation::query()
                ->lockForUpdate()
                ->find($reservationId);

            if ($reservation === null || $reservation->status !== ReservationStatusEnum::RESERVED) {
                return false;
            }

            $product = Product::query()
                ->lockForUpdate()
                ->findOrFail($reservation->product_id);
            $now = $this->clock->now();

            if ($now->lessThan($reservation->expires_at)) {
                return false;
            }

            $product->reserved_stock -= $reservation->quantity;
            $product->available_stock += $reservation->quantity;
            $product->save();

            $reservation->status = ReservationStatusEnum::EXPIRED;
            $reservation->finalized_at = $now;
            $reservation->updated_at = $now;
            $reservation->save();

            return true;
        }, attempts: 3);
    }
}
