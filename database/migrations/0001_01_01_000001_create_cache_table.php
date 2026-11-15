<?php

/**
 * Создаёт стандартные таблицы временных значений и связанных блокировок.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Готовит таблицы для хранения временных значений средствами базы. В обычном запуске проекта выбрано
     * внешнее хранилище, но стандартная настройка остаётся работоспособной.
     */
    public function up(): void
    {
        // Сохранённые временные значения при выборе хранения в базе.
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->bigInteger('expiration')->index();
        });

        // Служебные блокировки временного хранилища.
        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->bigInteger('expiration')->index();
        });
    }

    /**
     * Убирает обе таблицы этого изменения. Действующие резервы и счётчики товаров здесь не затрагиваются.
     */
    public function down(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }
};
