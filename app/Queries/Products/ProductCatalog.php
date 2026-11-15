<?php

namespace App\Queries\Products;

use App\Exceptions\ApiException;
use App\Models\Product;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Predis\PredisException;

/**
 * Читает каталог и хранит его временную копию. Эта копия служит для просмотра, а решение об удержании всегда
 * принимает основная база.
 */
final readonly class ProductCatalog
{
    /**
     * Получает хранилище временных копий. В рабочем запуске это отдельная служба, а проверки могут подставить
     * другую реализацию.
     */
    public function __construct(private Repository $cache) {}

    /**
     * Читает одну страницу в постоянном порядке по номеру товара. Сохраняет в копии только данные и общее
     * количество, а ссылки собирает заново для текущего запроса.
     *
     * @return LengthAwarePaginator<int, array{id: int, sku: string, name: string, available_stock: int}>
     */
    public function paginate(int $page, int $perPage): LengthAwarePaginator
    {
        // Чтение внутри выполняется, когда готовой временной копии нет или её хранилище недоступно.
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
     * Читает карточку из временной копии либо основной базы. Отсутствующий товар возвращает тот же понятный
     * отказ при любом способе чтения.
     *
     * @return array{id: int, sku: string, name: string, available_stock: int}
     */
    public function find(int $id): array
    {
        // Для одной карточки используется та же защита от отказа хранилища, что и для списка.
        return $this->remember("product:{$id}", function () use ($id): array {
            $product = Product::query()->find($id) ?? throw ApiException::notFound('product');

            return $this->productData($product);
        });
    }

    /**
     * Выбирает только поля, нужные для просмотра. Временная копия получает простой массив без живого объекта
     * базы и скрытых связей.
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
     * Берёт готовое значение либо сохраняет результат переданной операции чтения на заданный срок. При отказе
     * внешнего хранилища читает основную базу и записывает причину в журнал.
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
