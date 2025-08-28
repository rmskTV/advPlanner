<?php

namespace Modules\VkAds\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateAdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'creative_ids' => ['required', 'array', 'min:1'],
            'creative_ids.*' => ['integer', 'exists:vk_ads_creatives,id'],
            'headline' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:500'],
            'final_url' => ['required', 'url', 'max:500'],
            'call_to_action' => ['nullable', 'string', 'max:50'],
            'display_url' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Название объявления обязательно',
            'creative_ids.required' => 'Необходимо выбрать хотя бы один креатив',
            'creative_ids.min' => 'Необходимо выбрать хотя бы один креатив',
            'headline.required' => 'Заголовок обязателен',
            'description.required' => 'Описание обязательно',
            'final_url.required' => 'Целевая ссылка обязательна',
            'final_url.url' => 'Целевая ссылка должна быть валидным URL',
        ];
    }
}
