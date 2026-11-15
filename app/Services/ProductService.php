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
    /**
     * Cache page data while rebuilding links for the current request.
     *
     * @param int $page
     * @param int $perPage
     * @return LengthAwarePaginator<int, Product>
     */
    public static function paginate(int $page, int $perPage): LengthAwarePaginator
    {
        // Read PostgreSQL on a cache miss or a Redis failure.
        $data = self::remember("page:{$page}:{$perPage}", function () use ($page, $perPage): array {
            $products = Product::orderBy('id')
                ->paginate($perPage, ['id', 'sku', 'name', 'available_stock'], 'page', $page);

            return [
                'items' => $products->getCollection()
                    ->map(fn(Product $product): array => self::productData($product))
                    ->all(),
                'total' => $products->total()
            ];
        });

        return new LengthAwarePaginator(Product::hydrate($data['items']), $data['total'], $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath()
        ]);
    }

    /**
     * Get a product from the cache or PostgreSQL.
     *
     * @param int $id
     * @return Product
     */
    public static function find(int $id): Product
    {
        // Use the same cache failure policy for individual products.
        $data = self::remember("product:{$id}", function () use ($id): array {
            $product = Product::find($id) ?? throw ApiException::notFound('product');

            return self::productData($product);
        });

        return Product::hydrate([$data])->sole();
    }

    /**
     * Select the public product fields stored in the cache.
     *
     * @param Product $product
     * @return array{id: int, sku: string, name: string, available_stock: int}
     */
    private static function productData(Product $product): array
    {
        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'available_stock' => $product->available_stock
        ];
    }

    /**
     * Fall back to PostgreSQL and log Redis failures.
     *
     * @template T
     *
     * @param string $key
     * @param Closure(): T $load
     * @return T
     */
    private static function remember(string $key, Closure $load): mixed
    {
        try {
            return app(Repository::class)->remember(
                'catalog:v1:' . $key,
                config()->integer('reservations.catalog_ttl_seconds'),
                $load
            );
        } catch (PredisException $exception) {
            Log::warning('Catalog cache unavailable; reading PostgreSQL.', ['exception' => $exception->getMessage()]);

            return $load();
        }
    }
}
