<?php

use App\Jobs\ExpireReservationJob;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('app.env') !== 'testing' || DB::scalar('SELECT current_database()') !== 'warehouse_test'
    || config('database.redis.options.prefix') !== 'warehouse_test_') {
    throw new RuntimeException('Load probes require warehouse_test and its Redis prefix.');
}
if (DB::table('users')->count() !== 0 || DB::table('products')->count() !== 0) {
    throw new RuntimeException('Load probes require an empty test database.');
}

/** @var RedisQueue $queue */
$queue = Queue::connection('redis');
$queue->clear('reservations');
$owner = User::factory()->create();
$product = Product::factory()->create(['initial_stock' => 1000, 'available_stock' => 0, 'reserved_stock' => 1000]);
$report = [];
try {
    $now = CarbonImmutable::now('UTC');
    $rows = [];
    for ($index = 0; $index < 1000; $index++) {
        $rows[] = [
            'user_id' => $owner->id, 'product_id' => $product->id, 'quantity' => 1,
            'idempotency_key' => 'load-'.$index, 'status' => 'reserved',
            'created_at' => $now->subMinutes(20)->format('Y-m-d H:i:s.uP'),
            'expires_at' => $now->subMinutes(10)->format('Y-m-d H:i:s.uP'),
            'updated_at' => $now->subMinutes(20)->format('Y-m-d H:i:s.uP'),
        ];
    }
    DB::table('reservations')->insert($rows);
    $jobs = DB::table('reservations')
        ->where('user_id', $owner->id)
        ->pluck('id')
        ->map(fn (int $id) => new ExpireReservationJob($id))
        ->all();
    $queue->bulk($jobs, '', 'reservations');
    unset($rows, $jobs);
    memory_reset_peak_usage();
    $durations = [];
    for ($pass = 0; $pass < 2; $pass++) {
        $start = hrtime(true);
        $exit = Artisan::call('reservations:expire');
        if ($exit !== 0) {
            throw new RuntimeException('Load scan failed.');
        }
        $durations[] = (hrtime(true) - $start) / 1_000_000_000;
    }
    $report = [
        'queued_jobs' => $queue->size('reservations'),
        'scan_seconds' => $durations,
        'peak_memory_bytes' => memory_get_peak_usage(true),
    ];
} finally {
    $queue->clear('reservations');
    DB::table('reservations')->where('user_id', $owner->id)->delete();
    $product->delete();
    $owner->delete();
}

echo json_encode($report, JSON_THROW_ON_ERROR).PHP_EOL;
