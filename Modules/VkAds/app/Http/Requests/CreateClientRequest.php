<?php

namespace Modules\VkAds\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contract_id' => ['required', 'integer', 'exists:contracts,id'],
            'vk_account_id' => ['required', 'integer', 'unique:vk_ads_accounts,vk_account_id'],
            'account_name' => ['required', 'string', 'max:255'],
            'access_roles' => ['nullable', 'array'],
            'can_view_budget' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'contract_id.required' => 'Договор обязателен',
            'contract_id.exists' => 'Указанный договор не существует',
            'vk_account_id.required' => 'ID аккаунта VK Ads обязателен',
            'vk_account_id.unique' => 'Аккаунт с таким ID уже существует',
            'account_name.required' => 'Название аккаунта обязательно',
        ];
    }
}
