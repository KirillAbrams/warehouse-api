<?php

/**
 * Отправляет двадцать запросов одновременно, удерживая последнюю единицу товара до наблюдения всех ожидающих
 * запросов базы. Проверяет разные и одинаковые ключи. Создаёт отдельные помеченные записи в выбранной базе и
 * сохраняет их историю для последующей проверки.
 */

use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Без переданного адреса обращаемся к службе запросов внутри того же запуска.
$baseUrl = rtrim($argv[1] ?? 'http://api', '/');
$run = bin2hex(random_bytes(6));
$user = User::query()->create([
    'name' => 'Concurrency fixture',
    'email' => "parallel-{$run}@example.test",
    'password' => bin2hex(random_bytes(32)),
]);
$token = $user->createToken('concurrency')->plainTextToken;
$configuration = config('database.connections.pgsql');
$locker = new PDO(
    "pgsql:host={$configuration['host']};port={$configuration['port']};dbname={$configuration['database']}",
    $configuration['username'],
    $configuration['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$report = [];

// Сначала запросы используют разные ключи, затем один общий ключ. У товара есть только одна единица.
foreach (['distinct_keys', 'same_key'] as $scenario) {
    $product = Product::query()->create([
        'sku' => "PAR-{$run}-{$scenario}", 'name' => 'Concurrency fixture',
        'initial_stock' => 1, 'available_stock' => 1,
    ]);
    // Держим товар занятым, пока все двадцать запросов действительно не начнут ждать эту запись.
    $locker->beginTransaction();
    $statement = $locker->prepare('SELECT id FROM products WHERE id = ? FOR UPDATE');
    $statement->execute([$product->id]);

    // Готовим все соединения до ожидания ответов, чтобы запросы выполнялись одновременно.
    $multi = curl_multi_init();
    $handles = [];
    for ($index = 0; $index < 20; $index++) {
        $key = $scenario === 'same_key' ? "parallel-{$run}" : "parallel-{$run}-{$index}";
        $handle = curl_init($baseUrl.'/api/v1/reservations');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['product_id' => $product->id, 'quantity' => 1], JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                "Authorization: Bearer {$token}",
                "Idempotency-Key: {$key}",
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_PROXY => '',
        ]);
        curl_multi_add_handle($multi, $handle);
        $handles[] = $handle;
    }

    // Разрешаем продолжение после наблюдения ожидания в базе, а не после произвольной задержки.
    $started = microtime(true);
    $maxInFlight = 0;
    $waiters = 0;
    $released = false;
    try {
        do {
            $result = curl_multi_exec($multi, $running);
            if ($result !== CURLM_OK) {
                throw new RuntimeException(curl_multi_strerror($result));
            }
            $maxInFlight = max($maxInFlight, $running);
            if (! $released) {
                $waiters = (int) DB::scalar(
                    "SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock' AND pid <> pg_backend_pid()"
                );
                if ($waiters >= 20) {
                    $locker->commit();
                    $released = true;
                } elseif (microtime(true) - $started > 20) {
                    throw new RuntimeException(
                        "Only {$waiters} requests reached the PostgreSQL contention barrier; expected 20."
                    );
                }
            }
            if ($running > 0) {
                curl_multi_select($multi, 0.05);
            }
        } while ($running > 0);
    } finally {
        if ($locker->inTransaction()) {
            $locker->rollBack();
        }
    }

    // Собираем ответы и проверяем сетевые ошибки каждого запроса.
    $statuses = [];
    $ids = [];
    foreach ($handles as $handle) {
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $statuses[$status] = ($statuses[$status] ?? 0) + 1;
        if (curl_errno($handle) !== 0) {
            throw new RuntimeException(curl_error($handle));
        }
        $response = json_decode(curl_multi_getcontent($handle), true, 512, JSON_THROW_ON_ERROR);
        if ($status === 200 || $status === 201) {
            $ids[] = $response['data']['id'];
        } elseif (
            $scenario !== 'distinct_keys' || $status !== 409 || $response['error']['code'] !== 'insufficient_stock'
        ) {
            throw new RuntimeException('Unexpected response: '.json_encode($response, JSON_THROW_ON_ERROR));
        }
        curl_multi_remove_handle($multi, $handle);
    }
    curl_multi_close($multi);
    ksort($statuses);

    // Независимо читаем итоговые счётчики и число резервов прямо из базы.
    $row = DB::selectOne(
        'SELECT initial_stock, available_stock, reserved_stock, confirmed_stock, (SELECT count(*) FROM reservations r WHERE r.product_id = p.id) AS reservation_count FROM products p WHERE id = ?',
        [$product->id]
    );
    // В обоих случаях должен существовать один резерв и ровно одно удержание.
    $expected = $scenario === 'distinct_keys' ? [201 => 1, 409 => 19] : [200 => 19, 201 => 1];
    if ($statuses !== $expected || count(array_unique($ids)) !== 1 || (int) $row->reservation_count !== 1
        || (int) $row->initial_stock !== 1 || (int) $row->available_stock !== 0 || (int) $row->reserved_stock !== 1 || (int) $row->confirmed_stock !== 0
        || $maxInFlight !== 20 || ! $released) {
        throw new RuntimeException(
            'Concurrency invariant failed: '.json_encode([$statuses, $row, $maxInFlight], JSON_THROW_ON_ERROR)
        );
    }

    $report[$scenario] = [
        'requests' => 20, 'max_in_flight' => $maxInFlight, 'postgres_waiters_at_barrier' => $waiters,
        'http_statuses' => $statuses, 'product_id' => $product->id,
        'reservation_id' => $ids[0], 'stock' => $row, 'passed' => true,
    ];
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
