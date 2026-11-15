<?php

namespace App\Jobs;

use App\Actions\Reservations\ExpireReservation as ExpireReservationAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Сообщение очереди об истечении одного резерва. Содержит только его номер, а окончательное решение принимает
 * операция в базе.
 */
class ExpireReservation implements ShouldQueue
{
    use Queueable;

    // После трёх неудачных попыток сведения о задании сохранятся для разбора.
    public int $tries = 3;

    // Одно выполнение ограничено тридцатью секундами.
    public int $timeout = 30;

    /**
     * Запоминает номер резерва и выбирает очередь его обработки. Старые сообщения сохраняют тот же состав и имя
     * класса.
     */
    public function __construct(public readonly int $reservationId)
    {
        $this->onQueue('reservations');
    }

    /**
     * Возвращает паузы между неудачными попытками: одну, пять и десять секунд. Паузы дают внешней службе время
     * восстановиться.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [1, 5, 10];
    }

    /**
     * Вызывает общую операцию истечения. Она сама повторно проверит состояние и время, поэтому раннее или
     * повторное сообщение безопасно.
     */
    public function handle(ExpireReservationAction $expire): void
    {
        $expire->handle($this->reservationId);
    }
}
