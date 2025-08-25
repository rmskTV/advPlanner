<?php

namespace Modules\VkAds\app\Exceptions;

class AgencyAccessException extends VkAdsException
{
    public function __construct(
        string $message = 'Access denied for agency operation',
        int $code = 403,
        array $context = []
    ) {
        parent::__construct($message, $code, null, $context);
    }

    public function render($request): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'error' => 'Agency Access Error',
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
        ], 403);
    }
}
