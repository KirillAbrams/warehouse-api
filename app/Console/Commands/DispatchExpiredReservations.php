<?php

namespace App\Console\Commands;

use App\Actions\Reservations\ExpireReservation as ExpireReservationAction;
use App\Contracts\Clock;
use App\Enums\ReservationStatus;
use App\Jobs\ExpireReservation;
use App\Models\Reservation;
use App\Queries\Reservations\QueuedReservationExpirations;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Повторно находит просроченные резервы в основной базе. Поэтому истечение можно восстановить после остановки
 * обработчика или потери сообщения очереди.
 */
class DispatchExpiredReservations extends Command
{
    protected $signature = 'reservations:expire {--sync : Process directly without Redis}';

    protected $description = 'Rediscover expired reservations in PostgreSQL and queue their expiration';

    /**
     * Выбирает постановку заданий в очередь либо обработку сразу в этом процессе. При работе с очередью
     * удерживает общую блокировку до окончания отправки, чтобы два запуска не создали одинаковые задания.
     */
    public function handle(
        Clock $clock,
        ExpireReservationAction $expire,
        QueuedReservationExpirations $queued,
    ): int {
        // При работе через очередь блокировка согласует отправителей до окончания записи сообщений.
        $count = $this->option('sync')
            ? $this->processDue($clock, $expire)
            : DB::transaction(function () use ($clock, $expire, $queued): int {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['reservation:expire:dispatch']);

                return $this->processDue($clock, $expire, $queued->ids());
            });

        $this->info("Processed {$count} due reservation(s).");

        return self::SUCCESS;
    }

    /**
     * Читает просроченные резервы небольшими порциями с точностью до микросекунд. При прямой обработке вызывает
     * общую операцию истечения; при работе с очередью пропускает уже существующие задания. Возвращает число
     * обработанных или поставленных резервов.
     *
     * @param  array<int, true>|null  $queuedIds
     */
    private function processDue(Clock $clock, ExpireReservationAction $expire, ?array $queuedIds = null): int
    {
        $count = 0;

        foreach (Reservation::query()
            ->where('status', ReservationStatus::Reserved)
            ->where('expires_at', '<=', $clock->now()->format('Y-m-d H:i:s.uP'))
            ->lazyById(200, 'id') as $reservation) {
            if ($queuedIds === null) {
                $count += (int) $expire->handle($reservation->id);
            } else {
                if (isset($queuedIds[$reservation->id])) {
                    continue;
                }

                // Отправляем сообщение до освобождения общей блокировки.
                // Отложенная отправка позволила бы соседнему запуску поставить ещё одну копию.
                ExpireReservation::dispatch($reservation->id)
                    ->onConnection('redis')
                    ->beforeCommit();
                $count++;
            }
        }

        return $count;
    }
}
