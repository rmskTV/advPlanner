<?php

namespace Modules\VkAds\app\DTOs;

use Illuminate\Http\Request;

class CreateAdDTO
{
    public function __construct(
        public string $name,
        public int $creativeId,
        public string $headline,
        public string $description,
        public string $finalUrl,
        public string $callToAction = 'Узнать больше',
        public ?string $displayUrl = null
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            name: $request->input('name'),
            creativeId: (int) $request->input('creative_id'),
            headline: $request->input('headline'),
            description: $request->input('description'),
            finalUrl: $request->input('final_url'),
            callToAction: $request->input('call_to_action', 'Узнать больше'),
            displayUrl: $request->input('display_url')
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            creativeId: $data['creative_id'],
            headline: $data['headline'],
            description: $data['description'],
            finalUrl: $data['final_url'],
            callToAction: $data['call_to_action'] ?? 'Узнать больше',
            displayUrl: $data['display_url'] ?? null
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'creative_id' => $this->creativeId,
            'headline' => $this->headline,
            'description' => $this->description,
            'final_url' => $this->finalUrl,
            'call_to_action' => $this->callToAction,
            'display_url' => $this->displayUrl,
        ];
    }
}
