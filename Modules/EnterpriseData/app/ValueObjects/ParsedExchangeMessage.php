<?php

namespace Modules\EnterpriseData\app\ValueObjects;

readonly class ParsedExchangeMessage
{
    public function __construct(
        public ExchangeHeader $header,
        public ExchangeBody $body,
        public ?string $fileName = null
    ) {}

    public function isConfirmation(): bool
    {
        return $this->header->isConfirmationMessage();
    }

    public function getMessageId(): string
    {
        return $this->header->fromNode.'_'.$this->header->messageNo;
    }

    public function hasData(): bool
    {
        return ! $this->body->isEmpty();
    }

    public function getObjectsCount(): int
    {
        return $this->body->getObjectsCount();
    }

    public function getUniqueObjectTypes(): array
    {
        return $this->body->getUniqueObjectTypes();
    }

    public function isFromNode(string $nodeId): bool
    {
        return $this->header->fromNode === $nodeId;
    }

    public function isToNode(string $nodeId): bool
    {
        return $this->header->toNode === $nodeId;
    }

    public function getConfirmationInfo(): array
    {
        return [
            'exchange_plan' => $this->header->exchangePlan,
            'from_node' => $this->header->fromNode,
            'to_node' => $this->header->toNode,
            'message_no' => $this->header->messageNo,
            'received_no' => $this->header->receivedNo,
        ];
    }

    public function getSummary(): array
    {
        return [
            'message_id' => $this->getMessageId(),
            'creation_date' => $this->header->creationDate->toISOString(),
            'objects_count' => $this->getObjectsCount(),
            'object_types' => $this->getUniqueObjectTypes(),
            'is_confirmation' => $this->isConfirmation(),
            'has_data' => $this->hasData(),
            'format' => $this->header->format,
            'available_versions' => $this->header->availableVersions,
        ];
    }
}
