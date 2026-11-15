<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

class ApiDiscoveryTest extends TestCase
{
    public function test_root_identifies_the_api(): void
    {
        $response = $this->get('/');

        $response->assertOk()->assertExactJson(['service' => 'Warehouse reservations API', 'version' => 'v1']);
    }
}
