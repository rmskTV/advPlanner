<?php

namespace Modules\VkAds\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateAdGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'customer_order_item_id' => ['required', 'integer', 'exists:customer_order_items,id'],
            'bid' => ['nullable', 'numeric', 'min:0'],
            'targeting' => ['nullable', 'array'],
            'targeting.sex' => ['nullable', 'in:male,female'],
            'targeting.age_from' => ['nullable', 'integer', 'min:14', 'max:65'],
            'targeting.age_to' => ['nullable', 'integer', 'min:14', 'max:65', 'gte:targeting.age_from'],
            'targeting.geo' => ['nullable', 'array'],
            'targeting.interests' => ['nullable', 'array'],
            'placements' => ['nullable', 'array'],
            'placements.*' => ['string', 'in:feed,stories,apps,websites'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Название группы объявлений обязательно',
            'customer_order_item_id.required' => 'Строка заказа обязательна',
            'customer_order_item_id.exists' => 'Указанная строка заказа не существует',
            'targeting.age_to.gte' => 'Максимальный возраст должен быть больше или равен минимальному',
        ];
    }
}
