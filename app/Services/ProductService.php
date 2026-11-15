<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Product;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Predis\PredisException;

class ProductService
{
    public function __construct(private Repository $cache) {}

    /**
     * Cache page data while rebuilding links for the current request.
     *
     * @return LengthAwarePaginator<int, array{id: int, sku: string, name: string, available_stock: int}>
     */
    public function paginate(int $page, int $perPage): LengthAwarePaginator
    {
        // Read PostgreSQL on a cache miss or a Redis failure.
        $data = $this->remember("page:{$page}:{$perPage}", function () use ($page, $perPage): array {
            $products = Product::query()
                ->orderBy('id')
                ->paginate($perPage, ['id', 'sku', 'name', 'available_stock'], 'page', $page);

            return [
                'items' => $products->getCollection()
                    ->map(fn (Product $product): array => $this->productData($product))
                    ->all(),
                'total' => $products->total(),
            ];
        });

        return new LengthAwarePaginator($data['items'], $data['total'], $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
        ]);
    }

    /**
     * Get a product from the cache or PostgreSQL.
     *
     * @return array{id: int, sku: string, name: string, available_stock: int}
     */
    public function find(int $id): array
    {
        // Use the same cache failure policy for individual products.
        return $this->remember("product:{$id}", function () use ($id): array {
            $product = Product::query()->find($id) ?? throw ApiException::notFound('product');

            return $this->productData($product);
        });
    }

    /**
     * Select the public product fields stored in the cache.
     *
     * @return array{id: int, sku: string, name: string, available_stock: int}
     */
    private function productData(Product $product): array
    {
        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'available_stock' => $product->available_stock,
        ];
    }

    /**
     * Fall back to PostgreSQL and log Redis failures.
     *
     * @template T
     *
     * @param  Closure(): T  $load
     * @return T
     */
    private function remember(string $key, Closure $load): mixed
    {
        try {
            return $this->cache->remember(
                'catalog:v1:'.$key,
                config()->integer('reservations.catalog_ttl_seconds'),
                $load,
            );
        } catch (PredisException $exception) {
            Log::warning('Catalog cache unavailable; reading PostgreSQL.', ['exception' => $exception->getMessage()]);

            return $load();
        }
    }
}
