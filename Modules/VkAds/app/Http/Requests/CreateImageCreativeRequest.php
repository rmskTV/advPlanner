<?php

namespace Modules\VkAds\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateImageCreativeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'format' => ['required', 'in:banner,native,interstitial'],
            'primary_image_file_id' => ['required', 'integer', 'exists:image_files,id'],
            'variant_image_files' => ['nullable', 'array'],
            'variant_image_files.16:9' => ['nullable', 'integer', 'exists:image_files,id'],
            'variant_image_files.9:16' => ['nullable', 'integer', 'exists:image_files,id'],
            'variant_image_files.1:1' => ['nullable', 'integer', 'exists:image_files,id'],
            'variant_image_files.4:5' => ['nullable', 'integer', 'exists:image_files,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Название креатива обязательно',
            'format.required' => 'Формат креатива обязателен',
            'format.in' => 'Недопустимый формат креатива (instream недоступен для изображений)',
            'primary_image_file_id.required' => 'Основной файл изображения обязателен',
            'primary_image_file_id.exists' => 'Указанный файл изображения не существует',
        ];
    }
}
