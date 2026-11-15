<?php

namespace App\Data\Reservations;

use App\Models\Reservation;

/**
 * Результат создания резерва. Вместе с записью передаёт признак первого создания, чтобы ответ отличался от
 * ответа на повтор.
 */
final readonly class ReservationCreation
{
    /**
     * Сохраняет резерв и признак нового создания без возможности заменить эти свойства. Признак нужен
     * контроллеру для выбора номера ответа: первое создание или успешный повтор.
     */
    public function __construct(
        public Reservation $reservation,
        public bool $created,
    ) {}
}
