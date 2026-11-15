<?php

namespace App\Actions\Reservations;

use App\Contracts\Clock;
use App\Enums\ReservationStatus;
use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Подтверждает или отменяет резерв по единым правилам. Решение о сроке и количестве принимается под
 * блокировками записей.
 */
final readonly class TransitionReservation
{
    /**
     * Получает часы сервера. Во время проверки их можно заменить управляемым временем.
     */
    public function __construct(private Clock $clock) {}

    /**
     * Разрешает только подтверждение и отмену. Находит резерв сразу с проверкой владельца, дожидается товара и
     * читает свежее время. Повтор выполненного действия возвращает прежний результат, а первый переход после
     * срока отклоняется без изменения остатков.
     */
    public function handle(int $userId, int $reservationId, ReservationStatus $target): Reservation
    {
        if (! \in_array($target, [ReservationStatus::Confirmed, ReservationStatus::Cancelled], true)) {
            throw new InvalidArgumentException('Only confirm and cancel are client transitions.');
        }

        // Состояние резерва и счётчики товара должны меняться как одно неделимое действие.
        return DB::transaction(function () use ($userId, $reservationId, $target): Reservation {
            $reservation = Reservation::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->find($reservationId)
                ?? throw ApiException::notFound('reservation');

            if ($reservation->status === $target) {
                return $reservation;
            }

            if ($reservation->status === ReservationStatus::Expired) {
                throw new ApiException(409, 'reservation_expired', 'The reservation has expired.');
            }

            if ($reservation->status !== ReservationStatus::Reserved) {
                throw new ApiException(
                    409,
                    'invalid_transition',
                    'The reservation is already in a different final state.',
                );
            }

            $product = Product::query()
                ->lockForUpdate()
                ->findOrFail($reservation->product_id);

            // Читаем фактическое время после ожидания обеих записей.
            // Время начала операции могло бы ошибочно разрешить уже просроченный переход.
            $now = $this->clock->now();

            if ($now->greaterThanOrEqualTo($reservation->expires_at)) {
                throw new ApiException(409, 'reservation_expired', 'The reservation has expired.');
            }

            $product->reserved_stock -= $reservation->quantity;

            if ($target === ReservationStatus::Confirmed) {
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
}
