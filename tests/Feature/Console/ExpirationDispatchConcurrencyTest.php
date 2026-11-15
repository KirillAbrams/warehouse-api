<?php

namespace Tests\Feature\Console;

use App\Contracts\ClockInterface;
use App\Models\Product;
use App\Models\User;
use App\Services\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDO;
use Symfony\Component\Process\Process;
use Tests\Support\MutableClock;
use Tests\TestCase;

class ExpirationDispatchConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_eight_blocked_dispatchers_enqueue_each_due_reservation_once(): void
    {
        /** @var RedisQueue $queue */
        $queue = Queue::connection('redis');
        $queue->clear('reservations');
        $clock = new MutableClock(CarbonImmutable::now('UTC')->subMinutes(20));
        $this->app->instance(ClockInterface::class, $clock);
        $owner = User::factory()->create();
        $product = Product::factory()->create(['initial_stock' => 8, 'available_stock' => 8]);
        $ids = [];

        for ($index = 0; $index < 8; $index++) {
            $ids[] = ReservationService::create($owner->id, $product->id, 1, 'parallel-' . $index)->id;
        }
        $configuration = config('database.connections.pgsql');
        $locker = new PDO(
            "pgsql:host={$configuration['host']};port={$configuration['port']};dbname=warehouse_test",
            $configuration['username'],
            $configuration['password']
        );
        $locker->beginTransaction();
        $locker->query("SELECT pg_advisory_xact_lock(hashtextextended('reservation:expire:dispatch', 0))");
        $environment = [
            ...getenv(),
            'APP_ENV' => 'testing',
            'DB_DATABASE' => 'warehouse_test',
            'DB_URL' => '',
            'REDIS_PREFIX' => 'warehouse_test_',
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'redis',
            'XDEBUG_MODE' => 'off',
        ];
        $processes = [];

        try {
            for ($index = 0; $index < 8; $index++) {
                $process = new Process(
                    [
                        PHP_BINARY,
                        base_path('tests/Support/Processes/dispatch-expirations-process.php'),
                    ],
                    base_path(),
                    $environment
                );
                $process->setTimeout(120);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 90;
            do {
                $waiting = (int) DB::scalar(
                    "SELECT count(*) FROM pg_stat_activity WHERE application_name = 'warehouse-dispatch-child' AND datname = 'warehouse_test' AND wait_event_type = 'Lock'"
                );
                if ($waiting === 8) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertSame(8, $waiting, 'Observe every producer actually waiting on the shared advisory lock.');
            $locker->commit();

            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput() . $process->getOutput());
                $this->assertSame(0, json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR)['exit']);
            }
            $actual = $queue->pendingJobs('reservations')
                ->map(fn ($job) => $job->payload['expiration_reservation_id'])
                ->sort()
                ->values()
                ->all();
            $this->assertSame($ids, $actual);
            $this->assertSame(8, $queue->size('reservations'));
            $this->assertDatabaseHas('products', [
                'id' => $product->id,
                'available_stock' => 0,
                'reserved_stock' => 8,
                'confirmed_stock' => 0,
            ]);
        } finally {
            if ($locker->inTransaction()) {
                $locker->rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            $queue->clear('reservations');
        }
    }
}
