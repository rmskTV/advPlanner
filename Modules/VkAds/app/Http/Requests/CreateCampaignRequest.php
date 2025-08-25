<?php

namespace Modules\VkAds\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateCampaignRequest extends FormRequest
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
            'campaign_type' => ['required', 'in:promoted_posts,website_conversions,mobile_app_promotion'],
            'daily_budget' => ['nullable', 'numeric', 'min:0'],
            'total_budget' => ['nullable', 'numeric', 'min:0'],
            'budget_type' => ['required', 'in:daily,total'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'targeting' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Название кампании обязательно',
            'campaign_type.required' => 'Тип кампании обязателен',
            'campaign_type.in' => 'Недопустимый тип кампании',
            'start_date.required' => 'Дата начала обязательна',
            'start_date.after_or_equal' => 'Дата начала не может быть в прошлом',
            'end_date.after' => 'Дата окончания должна быть после даты начала',
            'budget_type.in' => 'Тип бюджета должен быть daily или total',
        ];
    }
}
