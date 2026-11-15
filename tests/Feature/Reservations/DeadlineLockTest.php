<?php

namespace Tests\Feature\Reservations;

use App\Models\Product;
use App\Models\User;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeadlineLockTest extends TestCase
{
    use DatabaseMigrations;

    #[DataProvider('blockingCases')]
    public function test_deadline_is_checked_after_waiting_for_decision_locks(string $table, string $target): void
    {
        $owner = User::factory()->create();
        $product = Product::factory()->create();
        $reservation = ReservationService::create($owner->id, $product->id, 2, 'deadline');
        $configuration = config('database.connections.pgsql');
        $locker = new PDO(
            "pgsql:host={$configuration['host']};port={$configuration['port']};dbname={$configuration['database']}",
            $configuration['username'],
            $configuration['password']
        );
        $gate = 'warehouse:deadline:start:' . $reservation->id;
        $statement = $locker->prepare('SELECT pg_advisory_lock(hashtextextended(?, 0))');
        $statement->execute([$gate]);
        $gateHeld = true;
        $environment = [
            ...getenv(),
            'APP_ENV' => 'testing',
            'DB_DATABASE' => 'warehouse_test',
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'APP_KEY' => config('app.key'),
            'XDEBUG_MODE' => 'off'
        ];
        $process = new Process(
            [
                PHP_BINARY,
                base_path('tests/Support/Processes/transition-process.php'),
                (string)$owner->id,
                (string)$reservation->id,
                $target,
                $gate
            ],
            base_path(),
            $environment
        );
        $process->setTimeout(60);

        try {
            $process->start();
            $readyLimit = microtime(true) + 30;
            do {
                $ready = (int)DB::scalar(
                    "SELECT count(*) FROM pg_stat_activity WHERE application_name = 'warehouse-deadline-child' AND datname = 'warehouse_test' AND wait_event_type = 'Lock' AND query LIKE 'SELECT pg_advisory_lock%'"
                );
                if ($ready === 1) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $readyLimit);
            $this->assertSame(1, $ready, 'Child must finish startup before the deadline starts.');

            DB::update(
                "UPDATE reservations SET expires_at = clock_timestamp() + interval '2 seconds' WHERE id = ?",
                [$reservation->id]
            );
            $locker->beginTransaction();
            $statement = $locker->prepare("SELECT id FROM {$table} WHERE id = ? FOR UPDATE");
            $statement->execute([$table === 'products' ? $product->id : $reservation->id]);
            $statement = $locker->prepare('SELECT pg_advisory_unlock(hashtextextended(?, 0))');
            $statement->execute([$gate]);
            $gateHeld = false;

            $deadline = microtime(true) + 8;
            do {
                $waiting = (int)DB::scalar(
                    "SELECT count(*) FROM pg_stat_activity WHERE application_name = 'warehouse-deadline-child' AND datname = 'warehouse_test' AND wait_event_type = 'Lock' AND strpos(query, ?) > 0",
                    ['"' . $table . '"']
                );
                if ($waiting === 1) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            $this->assertSame(1, $waiting, 'Child must be observed waiting on the actual decision row.');
            $this->assertFalse(
                (bool)DB::scalar(
                    'SELECT clock_timestamp() >= expires_at FROM reservations WHERE id = ?',
                    [$reservation->id]
                ),
                'Request must reach the lock before its deadline.'
            );
            do {
                $expired = (bool)DB::scalar(
                    'SELECT clock_timestamp() >= expires_at FROM reservations WHERE id = ?',
                    [$reservation->id]
                );
                if ($expired) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            $this->assertTrue($expired);
            $locker->rollBack();
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertSame(
                ['status' => 409, 'code' => 'reservation_expired'],
                json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR)
            );
        } finally {
            if ($locker->inTransaction()) {
                $locker->rollBack();
            }
            if ($gateHeld) {
                $statement = $locker->prepare('SELECT pg_advisory_unlock(hashtextextended(?, 0))');
                $statement->execute([$gate]);
            }
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }

        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'reserved']);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'available_stock' => 3,
            'reserved_stock' => 2,
            'confirmed_stock' => 0
        ]);
    }

    public static function blockingCases(): array
    {
        return [['products', 'confirmed'], ['reservations', 'cancelled']];
    }
}
