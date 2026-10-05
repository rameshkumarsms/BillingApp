<?php

namespace App\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;

/** Business-rule violation; rendered as HTTP 422 automatically. */
class BillingException extends DomainException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
