<?php

/**
 * Начальная точка входящего запроса. Подключает библиотеки, собирает приложение и передаёт ему полученный
 * запрос.
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// При включённом обслуживании сначала выполняется его подготовленный ответ.
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Подключаем установленные библиотеки и правила поиска классов.
require __DIR__.'/../vendor/autoload.php';

// Собираем приложение и передаём ему текущий запрос пользователя.
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
