<?php

namespace Modules\VkAds\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vk_account_id' => ['required', 'integer', 'unique:vk_ads_accounts,vk_account_id'],
            'account_name' => ['required', 'string', 'max:255'],
            'account_type' => ['required', 'in:agency,client'],
            'organization_id' => ['required_if:account_type,agency', 'nullable', 'exists:organizations,id'],
            'contract_id' => ['required_if:account_type,client', 'nullable', 'exists:contracts,id'],
            'balance' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'access_roles' => ['nullable', 'array'],
            'can_view_budget' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'vk_account_id.required' => 'ID аккаунта VK Ads обязателен',
            'vk_account_id.unique' => 'Аккаунт с таким ID уже существует',
            'account_name.required' => 'Название аккаунта обязательно',
            'account_type.required' => 'Тип аккаунта обязателен',
            'account_type.in' => 'Тип аккаунта должен быть agency или client',
            'organization_id.required_if' => 'Для агентского аккаунта необходимо указать организацию',
            'contract_id.required_if' => 'Для клиентского аккаунта необходимо указать договор',
        ];
    }
}
