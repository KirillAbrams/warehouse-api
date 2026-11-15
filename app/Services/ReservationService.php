<?php

namespace App\Services;

use App\Contracts\ClockInterface;
use App\Enums\ReservationStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReservationService
{
    /**
     * Reserve stock atomically or return an identical earlier request.
     *
     * @return Reservation
     */
    public static function create(
        int $userId,
        int $productId,
        int $quantity,
        string $key,
    ): Reservation {
        // Persist the reservation and stock counters in the same transaction.
        return DB::transaction(function () use ($userId, $productId, $quantity, $key): Reservation {
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

                return $existing;
            }

            $product = Product::query()
                ->lockForUpdate()
                ->find($productId)
                ?? throw ApiException::notFound('product');

            if ($product->available_stock < $quantity) {
                throw new ApiException(409, 'insufficient_stock', 'Not enough stock is available.');
            }

            $now = app(ClockInterface::class)->now();
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

            return $reservation;
        }, attempts: 3);
    }

    /**
     * Confirm or cancel an owned reservation before its deadline.
     */
    public static function transition(int $userId, int $reservationId, ReservationStatusEnum $target): Reservation
    {
        if (!\in_array($target, [ReservationStatusEnum::CONFIRMED, ReservationStatusEnum::CANCELLED], true)) {
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
            $now = app(ClockInterface::class)->now();

            if ($now->greaterThanOrEqualTo($reservation->expires_at)) {
                throw new ApiException(409, 'reservation_expired', 'The reservation has expired.');
            }

            if ($target === ReservationStatusEnum::CONFIRMED) {
                $reservation->setConfirmed($product, $now);
            } else {
                $reservation->setCancelled($product, $now);
            }

            return $reservation;
        }, attempts: 3);
    }

    /**
     * Release reserved stock once after its deadline.
     */
    public static function expire(int $reservationId): bool
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
            $now = app(ClockInterface::class)->now();

            if ($now->lessThan($reservation->expires_at)) {
                return false;
            }

            $reservation->setExpired($product, $now);

            return true;
        }, attempts: 3);
    }
}
