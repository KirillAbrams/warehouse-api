<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись одного резерва с владельцем, товаром, количеством и сроком. Даты хранят микросекунды, чтобы решение на
 * границе срока было точным.
 *
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $created_at
 */
#[Fillable([
    'user_id',
    'product_id',
    'quantity',
    'idempotency_key',
    'status',
    'expires_at',
    'finalized_at',
    'created_at',
    'updated_at',
])]
class Reservation extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * Получает владельца по сохранённому номеру пользователя. Связь описывает принадлежность записи и сама не
     * разрешает доступ к ней.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Получает товар, количество которого удерживает резерв. Связь не должна выбирать товар по номеру владельца
     * или другого резерва.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Приводит номера и количество к целым числам, состояние к допустимому перечню, а даты к неизменяемым
     * значениям. Это защищает сравнения от случайного изменения времени в памяти.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'product_id' => 'integer',
            'quantity' => 'integer',
            'status' => ReservationStatus::class,
            'expires_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
