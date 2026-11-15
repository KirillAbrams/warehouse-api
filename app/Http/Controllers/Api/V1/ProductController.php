<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexProductRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Queries\Products\ProductCatalog;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Принимает запросы на список и карточку товара. Получает данные через чтение каталога и передаёт их в
 * разрешённый вид ответа.
 */
class ProductController extends Controller
{
    /**
     * Берёт проверенные номер и размер страницы. Возвращает список с обычными ссылками на страницы, сохраняя
     * параметры текущего запроса.
     */
    public function index(IndexProductRequest $request, ProductCatalog $catalog): AnonymousResourceCollection
    {
        return ProductResource::collection(
            $catalog->paginate($request->integer('page', 1), $request->integer('per_page', 20)),
        )->preserveQuery();
    }

    /**
     * Получает карточку по проверенному номеру товара. Если товара нет, операция чтения сформирует понятный
     * отказ.
     */
    public function show(int $product, ProductCatalog $catalog): ProductResource
    {
        return new ProductResource($catalog->find($product));
    }
}
