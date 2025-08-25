<?php

namespace Modules\EnterpriseData\app\ValueObjects;

/**
 * VO результата обмена
 *
 * @property array|null $warnings
 *
 * */
readonly class ProcessingResult
{
    public function __construct(
        public bool $success,
        public int $processedCount = 0,
        public array $createdIds = [],
        public array $updatedIds = [],
        public array $deletedIds = [],
        public array $errors = []
    ) {}

    public function hasErrors(): bool
    {
        return ! empty($this->errors);
    }

    public function getErrorCount(): int
    {
        return count($this->errors);
    }

    public function getFirstError(): ?string
    {
        return $this->errors[0] ?? null;
    }

    public function getTotalChanges(): int
    {
        return count($this->createdIds) + count($this->updatedIds) + count($this->deletedIds);
    }

    public function merge(ProcessingResult $other): ProcessingResult
    {
        return new ProcessingResult(
            $this->success && $other->success,
            $this->processedCount + $other->processedCount,
            array_merge($this->createdIds, $other->createdIds),
            array_merge($this->updatedIds, $other->updatedIds),
            array_merge($this->deletedIds, $other->deletedIds),
            array_merge($this->errors, $other->errors)
        );
    }

    public function getSummary(): array
    {
        return [
            'success' => $this->success,
            'processed_count' => $this->processedCount,
            'created_count' => count($this->createdIds),
            'updated_count' => count($this->updatedIds),
            'deleted_count' => count($this->deletedIds),
            'error_count' => $this->getErrorCount(),
            'total_changes' => $this->getTotalChanges(),
        ];
    }
}
