<?php

namespace App\Queries\Reservations;

use App\Jobs\ExpireReservation;
use Illuminate\Contracts\Queue\Factory;
use Illuminate\Queue\RedisQueue;
use LogicException;
use Predis\Transaction\MultiExec;

/**
 * Находит номера резервов, задания которых уже находятся в очереди. Учитывает ожидание, выполнение и отложенную
 * повторную попытку.
 */
final readonly class QueuedReservationExpirations
{
    /**
     * Получает доступ к соединениям очереди. Используется именно именованное соединение, с которым работает
     * отправка истечений.
     */
    public function __construct(private Factory $queues) {}

    /**
     * Читает три состояния очереди одним согласованным снимком и собирает номера резервов. Старые сообщения
     * разбирает только как ожидаемый класс задания. Неподходящий вид очереди или неверное старое сообщение
     * вызывает явный отказ вместо молчаливой потери работы.
     *
     * @return array<int, true>
     */
    public function ids(): array
    {
        $queue = $this->queues->connection('redis');

        if (! $queue instanceof RedisQueue) {
            throw new LogicException('Expiration dispatch requires the Redis queue.');
        }

        $key = $queue->getQueue('reservations');

        // Читаем состояния вместе, чтобы не потерять сообщение во время его переноса между ними.
        /** @var array{list<string>, list<string>, list<string>} $states */
        $states = $queue->getConnection()
            ->command('transaction', [static function (MultiExec $transaction) use ($key): void {
                $transaction->lrange($key, 0, -1);
                $transaction->zrange($key.':reserved', 0, -1);
                $transaction->zrange($key.':delayed', 0, -1);
            }]);

        $ids = [];

        foreach ($states as $payloads) {
            foreach ($payloads as $payload) {
                $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

                if (($data['data']['commandName'] ?? null) !== ExpireReservation::class) {
                    continue;
                }

                $id = $data['expiration_reservation_id'] ?? null;

                if ($id === null) {
                    // Поддерживаем старые сообщения, в которых номер резерва есть только внутри задания.
                    $job = unserialize($data['data']['command'], ['allowed_classes' => [ExpireReservation::class]]);

                    if (! $job instanceof ExpireReservation) {
                        throw new LogicException('Invalid legacy expiration payload.');
                    }

                    $id = $job->reservationId;
                }

                $ids[$id] = true;
            }
        }

        return $ids;
    }
}
