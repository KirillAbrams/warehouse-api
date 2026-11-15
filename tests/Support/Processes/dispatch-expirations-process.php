<?php

/**
 * Отдельный запуск диспетчера для проверки одновременной постановки. Проверяет имя базы и служебный префикс
 * очереди, затем сообщает результат родительскому процессу.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (config('app.env') !== 'testing' || DB::scalar('SELECT current_database()') !== 'warehouse_test') {
    throw new RuntimeException('Dispatch probes require warehouse_test.');
}

DB::statement("SET application_name = 'warehouse-dispatch-child'");
$exit = Artisan::call('reservations:expire', ['--no-ansi' => true]);
echo json_encode(['exit' => $exit, 'output' => Artisan::output()], JSON_THROW_ON_ERROR).PHP_EOL;
exit($exit);
