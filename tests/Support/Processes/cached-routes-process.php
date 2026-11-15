<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
$app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

// Reset authentication state between requests handled by the fresh process.
$request = function (string $method, string $path, string $token) use ($app, $kernel): array {
    $app->make('auth')->forgetGuards();
    $input = Request::create(
        '/api/v1/' . $path,
        $method,
        server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_ACCEPT' => 'application/json']
    );
    $response = $kernel->handle($input);
    $kernel->terminate($input, $response);

    return [
        'status' => $response->getStatusCode(),
        'body' => json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR)
    ];
};

$token = getenv('WAREHOUSE_TEST_TOKEN');
$result = [
    'product' => $request('GET', 'products/' . $argv[1], $token),
    'reservation' => $request('GET', 'reservations/' . $argv[2], $token),
    'invalid_ids' => []
];

foreach (['0', '9223372036854775808', '999999999999999999999999999999999999'] as $id) {
    foreach ([
        ['GET', 'products/' . $id],
        ['GET', 'reservations/' . $id],
        ['POST', 'reservations/' . $id . '/confirm'],
        ['POST', 'reservations/' . $id . '/cancel']
    ] as [$method, $path]) {
        $result['invalid_ids'][] = $request($method, $path, $token);
    }
}

$result['unauthenticated'] = $request('GET', 'products/' . $argv[1], 'invalid');
$result['foreign'] = $request('GET', 'reservations/' . $argv[2], getenv('WAREHOUSE_OTHER_TOKEN'));
$result['missing'] = $request('GET', 'reservations/9223372036854775807', getenv('WAREHOUSE_OTHER_TOKEN'));
$result['cached'] = $app->routesAreCached();
echo json_encode($result, JSON_THROW_ON_ERROR);
