<?php

namespace App\Http\Controllers;

use App\Http\Requests\Product\FilterRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    /**
     * Get a page of products.
     *
     * GET /api/v1/products
     */
    public function index(FilterRequest $request): JsonResponse
    {
        $products = ProductService::paginate($request->integer('page', 1), $request->integer('per_page', 20));

        return ProductResource::collection($products)->preserveQuery()->response();
    }

    /**
     * Get a product from the catalogue snapshot.
     *
     * GET /api/v1/products/{product}
     */
    public function show(Product $product): JsonResponse
    {
        return response()->json(['data' => new ProductResource($product)]);
    }
}
