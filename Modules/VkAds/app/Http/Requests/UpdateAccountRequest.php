<?php

namespace Modules\VkAds\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $accountId = $this->route('id');

        return [
            'vk_account_id' => [
                'sometimes',
                'integer',
                Rule::unique('vk_ads_accounts', 'vk_account_id')->ignore($accountId),
            ],
            'account_name' => ['sometimes', 'string', 'max:255'],
            'account_status' => ['sometimes', 'in:active,blocked,deleted'],
            'balance' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'access_roles' => ['sometimes', 'array'],
            'can_view_budget' => ['sometimes', 'boolean'],
            'sync_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
