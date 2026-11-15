<?php

namespace Tests\Feature\Services;

use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProductServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_cached_product_is_returned_as_a_model_without_changing_its_snapshot(): void
    {
        $product = Product::factory()->create();
        $cache = Cache::store('redis');
        $this->app->instance(Repository::class, $cache);
        $key = 'catalog:v1:product:' . $product->id;
        $snapshot = ['id' => $product->id, 'sku' => $product->sku, 'name' => $product->name, 'available_stock' => 3];
        $cache->put($key, $snapshot, 60);

        try {
            $result = ProductService::find($product->id);

            $this->assertInstanceOf(Product::class, $result);
            $this->assertSame($snapshot, $cache->get($key));
            $this->assertSame([
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'available_quantity' => 3,
            ], (new ProductResource($result))->resolve(Request::create('/api/v1/products/' . $product->id)));
            $this->assertSame(5, $product->fresh()->available_stock);
        } finally {
            $cache->forget($key);
        }
    }

    public function test_a_cached_page_contains_models_and_preserves_its_total(): void
    {
        $product = Product::factory()->create();
        $cache = Cache::store('redis');
        $this->app->instance(Repository::class, $cache);
        $key = 'catalog:v1:page:2:1';
        $snapshot = [
            'items' => [['id' => $product->id, 'sku' => $product->sku, 'name' => $product->name, 'available_stock' => 4]],
            'total' => 2,
        ];
        $cache->put($key, $snapshot, 60);

        try {
            $page = ProductService::paginate(2, 1);
            $item = $page->getCollection()->sole();

            $this->assertInstanceOf(Product::class, $item);
            $this->assertSame($product->id, $item->id);
            $this->assertSame(4, $item->available_stock);
            $this->assertSame(2, $page->total());
            $this->assertSame(2, $page->currentPage());
            $this->assertSame($snapshot, $cache->get($key));
        } finally {
            $cache->forget($key);
        }
    }
}
