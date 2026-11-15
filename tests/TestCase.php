<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

/**
 * Общая основа проверок приложения. Не позволяет случайно запустить их на рабочей базе.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Загружает приложение и проверяет выбранное соединение и имя базы. При любом другом имени останавливает
     * запуск до работы с тестовыми записями.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if (
            $app['config']
                ->get('database.default') !== 'pgsql' || $app['config']
                ->get('database.connections.pgsql.database') !== 'warehouse_test'
        ) {
            throw new RuntimeException('Tests may only use the dedicated warehouse_test PostgreSQL database.');
        }

        return $app;
    }
}
