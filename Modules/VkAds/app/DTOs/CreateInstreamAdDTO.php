<?php

namespace Modules\VkAds\app\DTOs;

use Illuminate\Http\Request;

class CreateInstreamAdDTO
{
    public function __construct(
        public string $name,
        public int $creativeId,
        public string $headline,
        public string $description,
        public string $finalUrl,
        public string $callToAction,
        public string $instreamPosition = 'preroll',
        public bool $skippable = true,
        public ?int $skipOffset = 5
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
            instreamPosition: $request->input('instream_position', 'preroll'),
            skippable: $request->boolean('skippable', true),
            skipOffset: $request->input('skip_offset') ? (int) $request->input('skip_offset') : 5
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
            'instream_position' => $this->instreamPosition,
            'skippable' => $this->skippable,
            'skip_offset' => $this->skipOffset,
        ];
    }
}
