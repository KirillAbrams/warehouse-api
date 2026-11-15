<?php

/**
 * Проверка ключей доступа и общие настройки определения пользователя. Открытый ключ проверяется по защищённому
 * представлению в базе.
 */

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [

    // Адреса браузерных приложений, которым общая среда разрешает сохранять состояние входа.
    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
    ))),

    // Способы определения пользователя, проверяемые перед поиском ключа доступа.
    'guard' => [],

    // Общий срок действия ключей, если он задан. Отдельная запись ключа также может содержать свой срок.
    'expiration' => null,

    // Начальная часть новых ключей доступа. Она помогает отличать их от других случайных строк.
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    // Обработчики общих правил входа и проверки браузерных запросов.
    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
