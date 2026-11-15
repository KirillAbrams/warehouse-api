<?php

namespace App\Actions\Reservations;

use App\Contracts\Clock;
use App\Data\Reservations\ReservationCreation;
use App\Enums\ReservationStatus;
use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;

/**
 * Создаёт резерв и удерживает товар в одной операции с базой. Здесь же находится защита от повторной отправки
 * одного запроса.
 */
final readonly class CreateReservation
{
    /**
     * Получает часы сервера. Отдельные часы позволяют проверять срок в тестах без ожидания десяти минут.
     */
    public function __construct(private Clock $clock) {}

    /**
     * Сначала согласует запросы с одинаковым ключом пользователя. Готовый резерв возвращается повторно без
     * нового удержания; другое количество с тем же ключом вызывает отказ. Для нового резерва запись товара
     * блокируется, затем вместе сохраняются остатки, резерв и его первоначальный срок.
     */
    public function handle(
        int $userId,
        int $productId,
        int $quantity,
        string $key,
    ): ReservationCreation {
        // База сохранит резерв и количество вместе либо отменит все изменения при ошибке.
        return DB::transaction(function () use ($userId, $productId, $quantity, $key): ReservationCreation {
            // Эта блокировка работает ещё до появления записи с ключом и живёт до конца операции базы.
            // Совпадение хешей лишь заставит независимые запросы подождать друг друга.
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

                return new ReservationCreation($existing, false);
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
                'status' => ReservationStatus::Reserved,
                'created_at' => $now,
                'updated_at' => $now,
                'expires_at' => $now->addSeconds(config()->integer('reservations.ttl_seconds')),
            ]);

            return new ReservationCreation($reservation, true);
        }, attempts: 3);
    }
}
