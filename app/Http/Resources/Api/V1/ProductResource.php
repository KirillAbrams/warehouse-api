<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Задаёт поля карточки товара, которые разрешено отдавать пользователю.
 */
class ProductResource extends JsonResource
{
    /**
     * Преобразует подготовленный массив в ответ с номером, артикулом, названием и свободным количеством.
     * Остальные внутренние счётчики в ответ не включаются.
     *
     * @return array{id: int, sku: string, name: string, available_quantity: int}
     */
    public function toArray(Request $request): array
    {
        /** @var array{id: int, sku: string, name: string, available_stock: int} $product */
        $product = $this->resource;

        return [
            'id' => $product['id'],
            'sku' => $product['sku'],
            'name' => $product['name'],
            'available_quantity' => $product['available_stock'],
        ];
    }
}
