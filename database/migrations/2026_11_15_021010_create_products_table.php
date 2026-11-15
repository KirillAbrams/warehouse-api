<?php

/**
 * Создаёт товары и проверки правильности распределения их количества.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Задаёт целые счётчики и неповторяющийся артикул. Проверки базы запрещают отрицательные остатки и требуют,
     * чтобы их сумма была равна исходному количеству.
     */
    public function up(): void
    {
        // Исходное количество товара и его распределение между тремя счётчиками.
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('sku', 64)->unique();
            $table->string('name');
            $table->bigInteger('initial_stock');
            $table->bigInteger('available_stock');
            $table->bigInteger('reserved_stock')->default(0);
            $table->bigInteger('confirmed_stock')->default(0);
            $table->timestampsTz(6);
        });

        DB::statement(
            'ALTER TABLE products ADD CONSTRAINT products_nonnegative_stock CHECK (initial_stock >= 0 AND available_stock >= 0 AND reserved_stock >= 0 AND confirmed_stock >= 0)'
        );
        DB::statement(
            'ALTER TABLE products ADD CONSTRAINT products_stock_conservation CHECK (initial_stock = available_stock + reserved_stock + confirmed_stock)'
        );
    }

    /**
     * Убирает таблицу товаров при откате. Сначала должны быть убраны зависимые таблицы резервов.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
