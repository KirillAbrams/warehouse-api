<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

final class ApiException extends RuntimeException implements ShouldntReport
{
    /**
     * Create an API error with its public status, code and message.
     *
     * @param int $httpStatus
     * @param string $errorCode
     * @param string $message
     */
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }

    /**
     * Return the same error for missing and inaccessible records.
     *
     * @param string $resource
     * @return self
     */
    public static function notFound(string $resource): self
    {
        return new self(404, $resource . '_not_found', ucfirst($resource) . ' not found.');
    }

    /**
     * Render the documented API error envelope.
     *
     * @return JsonResponse
     */
    public function render(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage()
            ]
        ], $this->httpStatus);
    }
}
