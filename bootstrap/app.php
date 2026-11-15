<?php

/**
 * Собирает приложение, подключает адреса запросов и задаёт общий вид отказов. Пользователь получает постоянный
 * код причины, а подробности внутренних ошибок остаются в журнале.
 */

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Не перенаправляем запрос без ключа на страницу входа; он получит обычный ответ об отказе.
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(null);
    })
    // Все виды отказов ниже приводятся к одной форме ответа.
    ->withExceptions(function (Exceptions $exceptions): void {
        // Для адресов приложения выбираем ответ с полями даже без отдельного пожелания клиента.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Ошибки входных полей возвращаем вместе с указанием, какое поле нужно исправить.
        $exceptions->render(function (ValidationException $exception, Request $request) {
            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'The request data is invalid.',
                    'details' => $exception->errors(),
                ],
            ], 422);
        });

        // Отдельный отказ сообщает об отсутствующем или недействительном ключе доступа.
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            return response()->json([
                'error' => [
                    'code' => 'unauthenticated',
                    'message' => 'A valid bearer token is required.',
                ],
            ], 401);
        });

        // Сохраняем номер отказа и служебные заголовки, в том числе для неизвестного адреса.
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            return response()->json([
                'error' => [
                    'code' => $exception->getStatusCode() === 404 ? 'not_found' : 'http_error',
                    'message' => $exception->getStatusCode() === 404
                        ? 'Resource not found.'
                        : 'The request could not be processed.',
                ],
            ], $exception->getStatusCode(), $exception->getHeaders());
        });

        // Подробности внутренней ошибки остаются в журнале; наружу возвращается короткая причина.
        $exceptions->render(function (Throwable $exception, Request $request) {
            return response()->json([
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'An internal error occurred.',
                ],
            ], 500);
        });
    })
    ->create();
