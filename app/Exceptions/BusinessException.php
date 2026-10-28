<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A refusal the app can explain to the student: rendered as `{ message, code }` (contract BusinessError). */
class BusinessException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 403)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], $this->status);
    }
}
