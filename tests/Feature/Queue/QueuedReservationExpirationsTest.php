<?php

namespace Tests\Feature\Queue;

use App\Jobs\ExpireReservationJob;
use App\Services\ReservationQueueService;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\RedisQueue;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RecordQueueProbe;
use Tests\TestCase;

class QueuedReservationExpirationsTest extends TestCase
{
    public function test_a_non_redis_backend_is_rejected_explicitly(): void
    {
        $this->mock(QueueFactory::class, function (MockInterface $queues): void {
            $queues->shouldReceive('connection')
                ->once()
                ->with('redis')
                ->andReturn(new SyncQueue);
        });

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Expiration dispatch requires the Redis queue.');

        ReservationQueueService::getQueuedReservationIds();
    }

    #[DataProvider('invalidLegacyCommands')]
    public function test_an_invalid_legacy_command_is_rejected_without_changing_the_queued_message(
        ?string $commandClass,
    ): void {
        /** @var RedisQueue $queue */
        $queue = Queue::connection('redis');
        $queue->clear('reservations');

        try {
            $queue->push(new ExpireReservationJob(42), '', 'reservations');
            $payload = $queue->pendingJobs('reservations')->sole()->payload;
            $queue->clear('reservations');
            unset($payload['expiration_reservation_id']);
            $command = $commandClass === null ? null : new $commandClass;
            $payload['data']['command'] = serialize($command);
            $queue->pushRaw(json_encode($payload, JSON_THROW_ON_ERROR), 'reservations');
            $before = $queue->pendingJobs('reservations')->sole()->payload;

            try {
                ReservationQueueService::getQueuedReservationIds();
                $this->fail('A legacy envelope must contain the expected expiration job.');
            } catch (LogicException $exception) {
                $this->assertSame('Invalid legacy expiration payload.', $exception->getMessage());
            }

            $this->assertSame(1, $queue->size('reservations'));
            $this->assertSame($before, $queue->pendingJobs('reservations')->sole()->payload);
        } finally {
            $queue->clear('reservations');
        }
    }

    /**
     * @return array<string, array{class-string<RecordQueueProbe>|null}>
     */
    public static function invalidLegacyCommands(): array
    {
        return [
            'serialized null is not a job' => [null],
            'another job class is not an expiration' => [RecordQueueProbe::class],
        ];
    }
}
