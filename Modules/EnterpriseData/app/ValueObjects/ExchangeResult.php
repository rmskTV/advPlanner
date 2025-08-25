<?php

namespace Modules\EnterpriseData\app\ValueObjects;

use Carbon\Carbon;

readonly class ExchangeResult
{
    public function __construct(
        public bool $success,
        public int $processedMessages = 0,
        public int $processedObjects = 0,
        public array $errors = [],
        public array $warnings = [],
        public ?Carbon $startTime = null,
        public ?Carbon $endTime = null
    ) {}

    public function getDuration(): ?float
    {
        if ($this->startTime && $this->endTime) {
            return $this->endTime->diffInSeconds($this->startTime);
        }

        return null;
    }

    public function hasErrors(): bool
    {
        return ! empty($this->errors);
    }

    public function hasWarnings(): bool
    {
        return ! empty($this->warnings);
    }

    public function merge(ExchangeResult $other): ExchangeResult
    {
        return new self(
            $this->success && $other->success,
            $this->processedMessages + $other->processedMessages,
            $this->processedObjects + $other->processedObjects,
            array_merge($this->errors, $other->errors),
            array_merge($this->warnings, $other->warnings),
            $this->startTime ?? $other->startTime,
            $other->endTime ?? $this->endTime
        );
    }
}
