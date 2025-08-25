<?php

namespace Modules\VkAds\app\Exceptions;

class VkAdsApiException extends VkAdsException
{
    private ?array $apiResponse = null;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?array $apiResponse = null,
        array $context = []
    ) {
        parent::__construct($message, $code, null, $context);
        $this->apiResponse = $apiResponse;
    }

    public function getApiResponse(): ?array
    {
        return $this->apiResponse;
    }

    public function getApiErrorCode(): ?int
    {
        return $this->apiResponse['error']['error_code'] ?? null;
    }

    public function getApiErrorMessage(): ?string
    {
        return $this->apiResponse['error']['error_msg'] ?? null;
    }

    public function report(): void
    {
        \Log::error('VK Ads API Exception: '.$this->getMessage(), [
            'code' => $this->getCode(),
            'api_error_code' => $this->getApiErrorCode(),
            'api_error_message' => $this->getApiErrorMessage(),
            'api_response' => $this->apiResponse,
            'context' => $this->getContext(),
        ]);
    }

    public function render($request)
    {
        return response()->json([
            'error' => 'VK Ads API Error',
            'message' => $this->getMessage(),
            'api_error_code' => $this->getApiErrorCode(),
            'api_error_message' => $this->getApiErrorMessage(),
        ], 500);
    }
}
