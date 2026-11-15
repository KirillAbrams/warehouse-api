<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

final class ApiException extends RuntimeException implements ShouldntReport
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * Return the same error for missing and inaccessible records.
     */
    public static function notFound(string $resource): self
    {
        return new self(404, $resource.'_not_found', ucfirst($resource).' not found.');
    }

    /**
     * Render the documented API error envelope.
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
