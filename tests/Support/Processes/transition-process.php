<?php

use App\Enums\ReservationStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\Reservation;
use App\Services\ReservationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
$app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::scalar('SELECT current_database()') !== 'warehouse_test') {
    throw new RuntimeException('Deadline probes require warehouse_test.');
}
$reservation = Reservation::findOrFail((int)$argv[2]);
$reservation->getAttribute('expires_at');
Product::findOrFail($reservation->product_id);
// Delay preparation beyond the two-second test deadline.
// Start the parent clock only after readiness so slow bootstrap cannot satisfy the assertion.
usleep(3_000_000);
DB::statement("SET application_name = 'warehouse-deadline-child'");
DB::select('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$argv[4]]);
DB::select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$argv[4]]);

try {
    ReservationService::transition((int)$argv[1], (int)$argv[2], ReservationStatusEnum::from($argv[3]));
    echo json_encode(['status' => 200], JSON_THROW_ON_ERROR);
} catch (ApiException $exception) {
    echo json_encode(['status' => $exception->httpStatus, 'code' => $exception->errorCode], JSON_THROW_ON_ERROR);
}
