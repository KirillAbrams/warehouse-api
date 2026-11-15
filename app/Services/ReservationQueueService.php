<?php

namespace App\Services;

use App\Jobs\ExpireReservationJob;
use Illuminate\Contracts\Queue\Factory;
use Illuminate\Queue\RedisQueue;
use LogicException;
use Predis\Transaction\MultiExec;

class ReservationQueueService
{
    /**
     * Read all Redis queue states atomically and collect expiration IDs.
     *
     * @return array<int, true>
     */
    public static function getQueuedReservationIds(): array
    {
        $queue = app(Factory::class)->connection('redis');

        if (!$queue instanceof RedisQueue) {
            throw new LogicException('Expiration dispatch requires the Redis queue.');
        }

        $key = $queue->getQueue('reservations');

        // Read all queue states atomically while jobs move between them.
        /** @var array{list<string>, list<string>, list<string>} $states */
        $states = $queue->getConnection()
            ->command('transaction', [static function (MultiExec $transaction) use ($key): void {
                $transaction->lrange($key, 0, -1);
                $transaction->zrange($key . ':reserved', 0, -1);
                $transaction->zrange($key . ':delayed', 0, -1);
            }]);

        $ids = [];

        foreach ($states as $payloads) {
            foreach ($payloads as $payload) {
                $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

                if (!\in_array($data['data']['commandName'] ?? null, [ExpireReservationJob::class, ExpireReservationJob::LEGACY_CLASS], true)) {
                    continue;
                }

                $id = $data['expiration_reservation_id'] ?? null;

                if ($id === null) {
                    // Accept messages written before explicit reservation metadata was introduced.
                    $job = unserialize($data['data']['command'], ['allowed_classes' => [ExpireReservationJob::class, ExpireReservationJob::LEGACY_CLASS]]);

                    if (!$job instanceof ExpireReservationJob) {
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
