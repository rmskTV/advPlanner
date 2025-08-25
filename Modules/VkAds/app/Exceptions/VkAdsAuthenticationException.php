<?php

namespace Modules\VkAds\app\Exceptions;

class VkAdsAuthenticationException extends VkAdsException
{
    public function __construct(
        string $message = 'Authentication failed',
        int $code = 401,
        array $context = []
    ) {
        parent::__construct($message, $code, null, $context);
    }

    public function render($request): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'error' => 'Authentication Error',
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
        ], 401);
    }
}
