<?php

namespace Modules\VkAds\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateInstreamAdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'creative_id' => ['required', 'integer', 'exists:vk_ads_creatives,id'],
            'headline' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:500'],
            'final_url' => ['required', 'url', 'max:500'],
            'call_to_action' => ['nullable', 'string', 'max:50'],
            'instream_position' => ['required', 'in:preroll,midroll,postroll'],
            'skippable' => ['nullable', 'boolean'],
            'skip_offset' => ['nullable', 'integer', 'min:3', 'max:10'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Название объявления обязательно',
            'creative_id.required' => 'Креатив обязателен',
            'creative_id.exists' => 'Указанный креатив не существует',
            'headline.required' => 'Заголовок обязателен',
            'headline.max' => 'Заголовок не должен превышать 100 символов',
            'description.required' => 'Описание обязательно',
            'final_url.required' => 'Целевая ссылка обязательна',
            'final_url.url' => 'Целевая ссылка должна быть валидным URL',
            'instream_position.required' => 'Позиция instream обязательна',
            'instream_position.in' => 'Недопустимая позиция instream',
            'skip_offset.min' => 'Минимальное время до пропуска: 3 секунды',
            'skip_offset.max' => 'Максимальное время до пропуска: 10 секунд',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Проверяем, что креатив подходит для instream
            $creativeId = $this->input('creative_id');

            if ($creativeId) {
                $creative = \Modules\VkAds\app\Models\VkAdsCreative::find($creativeId);

                if ($creative) {
                    if (! $creative->isVideo()) {
                        $validator->errors()->add('creative_id', 'Для instream рекламы требуется видео креатив');
                    }

                    if ($creative->format !== 'instream') {
                        $validator->errors()->add('creative_id', 'Креатив должен иметь формат instream');
                    }

                    if (! $creative->hasVariantForAspectRatio('16:9')) {
                        $validator->errors()->add('creative_id', 'Креатив должен содержать видео с соотношением 16:9');
                    }
                }
            }
        });
    }
}
