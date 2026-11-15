<?php

namespace Tests\Feature\Queue;

use App\Jobs\ExpireReservation;
use App\Queries\Reservations\QueuedReservationExpirations;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\RedisQueue;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RecordQueueProbe;
use Tests\TestCase;

/**
 * Проверяет явные отказы чтения очереди при неверной настройке или содержимом старого сообщения.
 */
class QueuedReservationExpirationsTest extends TestCase
{
    /**
     * Под именем ожидаемого соединения возвращает другой вид очереди. Проверяет понятный отказ до попытки
     * прочитать неподходящее хранилище.
     */
    public function test_a_non_redis_backend_is_rejected_explicitly(): void
    {
        $this->mock(QueueFactory::class, function (MockInterface $queues): void {
            $queues->shouldReceive('connection')
                ->once()
                ->with('redis')
                ->andReturn(new SyncQueue);
        });
        $query = $this->app->make(QueuedReservationExpirations::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Expiration dispatch requires the Redis queue.');

        $query->ids();
    }

    /**
     * Помещает в настоящую очередь старое сообщение с неподходящим значением задания. Проверяет точный отказ и
     * полную сохранность сообщения.
     */
    #[DataProvider('invalidLegacyCommands')]
    public function test_an_invalid_legacy_command_is_rejected_without_changing_the_queued_message(
        ?string $commandClass,
    ): void {
        /** @var RedisQueue $queue */
        $queue = Queue::connection('redis');
        $queue->clear('reservations');

        try {
            $queue->push(new ExpireReservation(42), '', 'reservations');
            $payload = $queue->pendingJobs('reservations')->sole()->payload;
            $queue->clear('reservations');
            unset($payload['expiration_reservation_id']);
            $command = $commandClass === null ? null : new $commandClass;
            $payload['data']['command'] = serialize($command);
            $queue->pushRaw(json_encode($payload, JSON_THROW_ON_ERROR), 'reservations');
            $before = $queue->pendingJobs('reservations')->sole()->payload;

            try {
                $this->app->make(QueuedReservationExpirations::class)->ids();
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
     * Перечисляет пустое значение и имя постороннего задания. Объект создаётся внутри проверки, чтобы
     * подготовка списка не загружала код до начала измерения покрытия.
     *
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
