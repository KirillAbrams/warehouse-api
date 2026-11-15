<?php

/**
 * Способы определения пользователя и стандартные настройки входа. Запросы склада используют ключи доступа, а
 * общие средства среды сохраняют свои обычные настройки.
 */

use App\Models\User;

return [

    // Способы входа, которые общая среда использует, когда вызывающий код не выбрал свой.
    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    // Настройки способов получения текущего пользователя.
    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    // Класс записей, из которых общая среда получает сведения о пользователях.
    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],
    ],

    // Стандартное хранение запросов восстановления пароля. Отдельного действия восстановления в запросах склада
    // нет.
    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    // Время, в течение которого общая среда может считать недавнее подтверждение пароля действительным.
    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
