<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

/**
 * Проверяет короткий ответ о назначении работающего приложения.
 */
class ApiDiscoveryTest extends TestCase
{
    /**
     * Обращается к корневому адресу без ключа доступа. Проверяет имя службы и номер её открытого набора
     * запросов.
     */
    public function test_root_identifies_the_api(): void
    {
        $response = $this->get('/');

        $response->assertOk()->assertExactJson(['service' => 'Warehouse reservations API', 'version' => 'v1']);
    }
}
