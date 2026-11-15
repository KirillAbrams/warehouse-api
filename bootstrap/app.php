<?php

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
    // Return an authentication error instead of redirecting token clients.
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(null);
    })
    // Keep API errors in the documented JSON format.
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render API errors as JSON even without an Accept header.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Include field errors in validation responses.
        $exceptions->render(function (ValidationException $exception, Request $request) {
            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'The request data is invalid.',
                    'details' => $exception->errors(),
                ],
            ], 422);
        });

        // Handle missing or invalid bearer tokens.
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            return response()->json([
                'error' => [
                    'code' => 'unauthenticated',
                    'message' => 'A valid bearer token is required.',
                ],
            ], 401);
        });

        // Preserve HTTP status codes and protocol headers.
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

        // Log internal details without exposing them in API responses.
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
