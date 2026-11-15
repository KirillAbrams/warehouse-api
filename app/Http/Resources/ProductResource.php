<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Expose public product data.
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
