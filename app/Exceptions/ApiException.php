<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Ожидаемый отказ с понятным номером и постоянным кодом причины. Например, нехватка товара или отсутствие
 * доступного резерва.
 */
final class ApiException extends RuntimeException implements ShouldntReport
{
    /**
     * Запоминает номер ответа, код причины и текст. Благодаря этому разные места приложения возвращают ошибки в
     * одной форме.
     */
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * Создаёт отказ об отсутствии товара или резерва. Для чужого резерва используется тот же ответ, чтобы не
     * раскрывать его существование.
     */
    public static function notFound(string $resource): self
    {
        return new self(404, $resource.'_not_found', ucfirst($resource).' not found.');
    }

    /**
     * Собирает ответ с кодом причины и сообщением. В него не попадают внутренние сведения об ошибках базы.
     */
    public function render(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
            ],
        ], $this->httpStatus);
    }
}
