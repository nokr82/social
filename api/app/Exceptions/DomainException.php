<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * 비즈니스 규칙 위반. 앱이 code로 분기하고 message를 그대로 띄울 수 있게 한다.
 * 예: throw new DomainException('PLAZA_FULL', '광장 정원이 가득 찼어요', 409);
 */
class DomainException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['code' => $this->errorCode, 'message' => $this->getMessage()], $this->status);
    }
}
