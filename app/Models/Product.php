<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Запись товара в базе: название, артикул и распределение исходного количества между свободным, удержанным и
 * подтверждённым.
 */
#[Fillable(['sku', 'name', 'initial_stock', 'available_stock', 'reserved_stock', 'confirmed_stock'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * Возвращает выборку резервов этого товара. Связь нужна для чтения его истории без включения записей
     * другого товара.
     *
     * @return HasMany<Reservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /**
     * Читает все четыре счётчика как целые числа. Это сохраняет одинаковые виды значений после чтения и записи
     * базы.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'initial_stock' => 'integer',
            'available_stock' => 'integer',
            'reserved_stock' => 'integer',
            'confirmed_stock' => 'integer',
        ];
    }
}
