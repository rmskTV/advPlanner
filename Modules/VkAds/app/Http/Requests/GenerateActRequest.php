<?php

namespace Modules\VkAds\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateActRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contract_id' => ['required', 'integer', 'exists:contracts,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
            'act_number' => ['nullable', 'string', 'max:50'],
            'services' => ['nullable', 'array'],
            'services.*.name' => ['required_with:services', 'string'],
            'services.*.amount' => ['required_with:services', 'numeric', 'min:0'],
            'total_amount' => ['nullable', 'numeric', 'min:0'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'contract_id.required' => 'Договор обязателен',
            'contract_id.exists' => 'Указанный договор не существует',
            'period_start.required' => 'Дата начала периода обязательна',
            'period_end.required' => 'Дата окончания периода обязательна',
            'period_end.after' => 'Дата окончания должна быть после даты начала',
        ];
    }
}
