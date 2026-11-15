<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Проверяет проход диспетчера по тысяче существующих сообщений без накопления повторов.
 */
class ExpirationDispatchLoadTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Запускает отдельный процесс без измерителя покрытия, чтобы не исказить расход памяти и время. Проверяет
     * два прохода, пределы времени и памяти, а также очистку созданных записей.
     */
    public function test_a_thousand_queued_expirations_are_reconciled_without_growth(): void
    {
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
        $process = new Process(
            [PHP_BINARY, base_path('tests/Support/Processes/expiration-load-process.php')],
            base_path(),
            $environment
        );
        $process->setTimeout(180);
        $process->mustRun();
        $report = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1000, $report['queued_jobs']);
        foreach ($report['scan_seconds'] as $duration) {
            $this->assertLessThanOrEqual(10, $duration);
        }
        $this->assertLessThanOrEqual(128 * 1024 * 1024, $report['peak_memory_bytes']);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('reservations', 0);
        if (! is_dir(storage_path('framework/testing'))) {
            mkdir(storage_path('framework/testing'), recursive: true);
        }
        file_put_contents(
            storage_path('framework/testing/queue-performance.json'),
            json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );
    }
}
