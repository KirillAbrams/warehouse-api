<?php

/**
 * Отдельная операция подтверждения или отмены для проверки срока во время ожидания. Сообщает о готовности,
 * дожидается разрешения родителя и возвращает номер ответа с причиной отказа.
 */

use App\Actions\Reservations\TransitionReservation;
use App\Enums\ReservationStatus;
use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\Reservation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::scalar('SELECT current_database()') !== 'warehouse_test') {
    throw new RuntimeException('Deadline probes require warehouse_test.');
}
$transition = $app->make(TransitionReservation::class);
$reservation = Reservation::query()->findOrFail((int) $argv[2]);
$reservation->getAttribute('expires_at');
Product::query()->findOrFail($reservation->product_id);
// Намеренно готовимся дольше двухсекундного срока.
// Родитель начнёт отсчёт только после сигнала готовности, поэтому медленный запуск не исказит проверку.
usleep(3_000_000);
DB::statement("SET application_name = 'warehouse-deadline-child'");
DB::select('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$argv[4]]);
DB::select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$argv[4]]);

try {
    $transition->handle((int) $argv[1], (int) $argv[2], ReservationStatus::from($argv[3]));
    echo json_encode(['status' => 200], JSON_THROW_ON_ERROR);
} catch (ApiException $exception) {
    echo json_encode(['status' => $exception->httpStatus, 'code' => $exception->errorCode], JSON_THROW_ON_ERROR);
}
