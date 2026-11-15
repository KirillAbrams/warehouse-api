<?php

namespace App\Actions\Reservations;

use App\Contracts\Clock;
use App\Enums\ReservationStatus;
use App\Models\Product;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;

/**
 * Освобождает товар после окончания срока резерва. Повторная доставка задания не должна возвращать одну и ту же
 * единицу товара несколько раз.
 */
final readonly class ExpireReservation
{
    /**
     * Получает часы сервера, чтобы сравнивать срок после ожидания записей в базе.
     */
    public function __construct(private Clock $clock) {}

    /**
     * Блокирует сначала резерв, затем товар и только после этого читает время. Для ещё действующего или уже
     * завершённого резерва ничего не делает. Просроченный резерв завершает и возвращает удержанное количество в
     * той же операции с базой.
     */
    public function handle(int $reservationId): bool
    {
        // Возврат количества и завершение резерва сохраняются вместе, чтобы не освободить товар дважды.
        return DB::transaction(function () use ($reservationId): bool {
            $reservation = Reservation::query()
                ->lockForUpdate()
                ->find($reservationId);

            if ($reservation === null || $reservation->status !== ReservationStatus::Reserved) {
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

            $reservation->status = ReservationStatus::Expired;
            $reservation->finalized_at = $now;
            $reservation->updated_at = $now;
            $reservation->save();

            return true;
        }, attempts: 3);
    }
}
