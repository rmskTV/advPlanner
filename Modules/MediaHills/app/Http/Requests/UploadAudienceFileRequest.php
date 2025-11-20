<?php

namespace Modules\MediaHills\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadAudienceFileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls',
                'max:10240', // 10MB
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Необходимо загрузить файл',
            'file.mimes' => 'Файл должен быть в формате Excel (.xlsx или .xls)',
            'file.max' => 'Размер файла не должен превышать 10MB',
        ];
    }

    public function authorize(): bool
    {
        return true; // Добавьте свою логику авторизации
    }
}
