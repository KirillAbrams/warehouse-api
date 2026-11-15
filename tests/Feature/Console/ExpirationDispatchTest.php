<?php

namespace Tests\Feature\Console;

use App\Actions\Reservations\CreateReservation;
use App\Contracts\Clock;
use App\Jobs\ExpireReservation;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Predis\Connection\Resource\Exception\StreamInitException;
use Tests\Support\MutableClock;
use Tests\Support\RecordQueueProbe;
use Tests\TestCase;

/**
 * Проверяет точность поиска истечений, состояние настоящей очереди и восстановление потерянных заданий.
 */
class ExpirationDispatchTest extends TestCase
{
    use LazilyRefreshDatabase;

    private RedisQueue $queue;

    private MutableClock $clock;

    /**
     * Выбирает тестовую очередь, очищает её и задаёт время с микросекундами. Каждая проверка начинает с
     * независимого состояния.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'redis', 'queue.connections.redis.after_commit' => false]);
        $this->queue = Queue::connection('redis');
        $this->queue->clear('reservations');
        $this->clock = new MutableClock(CarbonImmutable::parse('2026-11-15T10:00:00.123456Z'));
        $this->app->instance(Clock::class, $this->clock);
    }

    /**
     * Очищает тестовую очередь даже после неудачной проверки. Завершение общей основы вызывается в любом
     * случае.
     */
    protected function tearDown(): void
    {
        try {
            $this->queue->clear('reservations');
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Сдвигает время относительно срока на одну микросекунду. Проверяет точную границу постановки без
     * досрочного изменения резерва.
     */
    #[DataProvider('deadlineOffsets')]
    public function test_discovery_preserves_microseconds(int $offset, int $expectedJobs): void
    {
        $reservation = $this->reservation();
        $this->clock->instant = $reservation->expires_at->addMicroseconds($offset);

        $this->artisan('reservations:expire')->assertSuccessful();

        $this->assertSame($expectedJobs, $this->queue->size('reservations'));
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'reserved']);
    }

    /**
     * Задаёт время до срока, ровно на сроке и после него. Ожидаемое число сообщений указано явно.
     */
    public static function deadlineOffsets(): array
    {
        return [
            'one microsecond early' => [-1, 0],
            'exact deadline' => [0, 1],
            'one microsecond due' => [1, 1],
        ];
    }

    /**
     * Оставляет задание в одном из трёх состояний и двенадцать раз повторяет поиск. Проверяет отсутствие новых
     * копий и изменений удержанного товара.
     */
    #[DataProvider('queueStates')]
    public function test_repeated_scans_keep_one_live_job_in_each_queue_state(string $state): void
    {
        $reservation = $this->reservation();
        $this->clock->instant = $reservation->expires_at->addSecond();
        $job = new ExpireReservation($reservation->id);
        if ($state === 'delayed') {
            $this->queue->later(300, $job, '', 'reservations');
        } else {
            $this->queue->push($job, '', 'reservations');
            if ($state === 'reserved') {
                $this->assertNotNull($this->queue->pop('reservations'));
            }
        }

        for ($pass = 0; $pass < 12; $pass++) {
            $this->artisan('reservations:expire')->assertSuccessful();
        }

        $this->assertSame(1, $this->queue->size('reservations'));
        $this->assertDatabaseHas('products', [
            'id' => $reservation->product_id,
            'available_stock' => 4,
            'reserved_stock' => 1,
        ]);
    }

    /**
     * Перечисляет ожидание, выполнение и отложенный повтор. Эти состояния должны одинаково защищать от
     * повторной постановки.
     */
    public static function queueStates(): array
    {
        return [
            'waiting' => ['ready'],
            'executing' => ['reserved'],
            'retry backoff' => ['delayed'],
        ];
    }

    /**
     * Удаляет уже поставленное сообщение и повторяет поиск. Проверяет его восстановление, настоящее выполнение
     * и однократный возврат товара.
     */
    public function test_lost_job_is_rediscovered_on_the_next_scan(): void
    {
        $reservation = $this->reservation();
        $this->clock->instant = $reservation->expires_at->addSecond();
        $this->artisan('reservations:expire')->assertSuccessful();
        $this->assertSame(1, $this->queue->size('reservations'));
        $this->queue->clear('reservations');

        $this->artisan('reservations:expire')->assertSuccessful();

        $this->assertSame(1, $this->queue->size('reservations'));
        $job = $this->queue->pop('reservations');
        $this->assertNotNull($job);
        $job->fire();
        $this->assertSame(0, $this->queue->size('reservations'));
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'expired']);
        $this->assertDatabaseHas('products', [
            'id' => $reservation->product_id,
            'available_stock' => 5,
            'reserved_stock' => 0,
        ]);
    }

    /**
     * Меняет общую очередь по умолчанию на немедленное выполнение. Проверяет, что обычный диспетчер всё равно
     * отправляет сообщение в своё именованное соединение.
     */
    public function test_async_dispatch_uses_redis_when_the_application_default_is_sync(): void
    {
        $reservation = $this->reservation();
        $this->clock->instant = $reservation->expires_at->addSecond();
        config(['queue.default' => 'sync']);

        $this->artisan('reservations:expire')->assertSuccessful();

        $this->assertSame(1, $this->queue->size('reservations'));
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'reserved']);
    }

    /**
     * Создаёт старое сообщение без дополнительного номера и добавляет постороннее задание. Проверяет
     * распознавание старого истечения без создания новой копии.
     */
    public function test_a_legacy_payload_is_deduplicated_without_instantiating_unrelated_jobs(): void
    {
        $reservation = $this->reservation();
        $this->clock->instant = $reservation->expires_at->addSecond();
        $this->queue->push(new ExpireReservation($reservation->id), '', 'reservations');
        $payload = $this->queue->pendingJobs('reservations')->sole()->payload;
        $this->queue->clear('reservations');
        unset($payload['expiration_reservation_id']);
        $this->queue->pushRaw(json_encode($payload, JSON_THROW_ON_ERROR), 'reservations');
        $this->queue->push(new RecordQueueProbe, '', 'reservations');

        $this->artisan('reservations:expire')->assertSuccessful();

        $this->assertSame(2, $this->queue->size('reservations'));
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'reserved']);
    }

    /**
     * Запрещает любое обращение к соединению очереди. Прямая обработка на точной границе срока должна завершить
     * резерв и вернуть товар через базу.
     */
    public function test_sync_recovers_a_fractional_deadline_without_contacting_redis(): void
    {
        $reservation = $this->reservation();
        $this->clock->instant = $reservation->expires_at;
        Queue::shouldReceive('connection')->never();

        $this->artisan('reservations:expire', ['--sync' => true])->assertSuccessful();

        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'expired']);
        $this->assertDatabaseHas('products', [
            'id' => $reservation->product_id,
            'available_stock' => 5,
            'reserved_stock' => 0,
        ]);
    }

    /**
     * Возвращает взятое сообщение в отложенное состояние. Проверяет отсутствие копии до его потери и
     * восстановление после удаления.
     */
    public function test_a_released_job_prevents_duplicate_dispatch_until_it_is_lost(): void
    {
        $reservation = $this->reservation();
        $this->clock->instant = $reservation->expires_at->addSecond();
        $this->artisan('reservations:expire')->assertSuccessful();
        $job = $this->queue->pop('reservations');
        $this->assertNotNull($job);
        $job->release(300);

        $this->artisan('reservations:expire')->assertSuccessful();

        $this->assertSame(1, $this->queue->size('reservations'));
        $this->assertSame(1, $this->queue->delayedSize('reservations'));
        $this->queue->clear('reservations');
        $this->artisan('reservations:expire')->assertSuccessful();
        $this->assertSame(1, $this->queue->pendingSize('reservations'));
    }

    /**
     * Вызывает контролируемый отказ второй отправки. Повторный проход должен сохранить первое сообщение и
     * добавить только отсутствующее второе.
     */
    public function test_partial_dispatch_failure_recovers_only_the_missing_job(): void
    {
        $first = $this->reservation();
        $second = $this->reservation();
        $this->clock->instant = $second->expires_at->addSecond();
        $failed = false;
        Queue::createPayloadUsing(static function (string $connection, ?string $queue, array $payload) use ($second, &$failed): array {
            $job = $payload['data']['command'] ?? null;
            if ($job instanceof ExpireReservation && $job->reservationId === $second->id && ! $failed) {
                $failed = true;
                throw new StreamInitException('Injected queue write failure.');
            }

            return [];
        });

        try {
            try {
                Artisan::call('reservations:expire');
                $this->fail('The second dispatch must fail.');
            } catch (StreamInitException $exception) {
                $this->assertSame('Injected queue write failure.', $exception->getMessage());
            }
            $this->assertSame(1, $this->queue->size('reservations'));
            $this->artisan('reservations:expire')->assertSuccessful();
            $this->artisan('reservations:expire')->assertSuccessful();

            $payloads = $this->queue->pendingJobs('reservations')
                ->map(fn ($job) => $job->payload['expiration_reservation_id'])
                ->sort()
                ->values()
                ->all();
            $this->assertSame([$first->id, $second->id], $payloads);
            $this->assertDatabaseCount('reservations', 2);
        } finally {
            Queue::createPayloadUsing(null);
        }
    }

    /**
     * Создаёт отдельного владельца, товар и один настоящий резерв. Общая подготовка использует ту же операцию,
     * что и запросы приложения.
     */
    private function reservation(): Reservation
    {
        return $this->app->make(CreateReservation::class)
            ->handle(User::factory()
                ->create()
                ->id, Product::factory()
                ->create()
                ->id, 1, 'dispatch')
            ->reservation;
    }
}
