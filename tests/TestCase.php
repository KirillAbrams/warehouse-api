<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
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
