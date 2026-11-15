<?php

namespace App\Http\Controllers;

use App\Http\Requests\Product\IndexRequest;
use App\Http\Resources\ProductResource;
use App\Services\ProductService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    /**
     * List products using validated pagination.
     */
    public function index(IndexRequest $request, ProductService $catalog): AnonymousResourceCollection
    {
        return ProductResource::collection(
            $catalog->paginate($request->integer('page', 1), $request->integer('per_page', 20)),
        )->preserveQuery();
    }

    /**
     * Return one product or the documented not-found response.
     */
    public function show(int $product, ProductService $catalog): ProductResource
    {
        return new ProductResource($catalog->find($product));
    }
}
