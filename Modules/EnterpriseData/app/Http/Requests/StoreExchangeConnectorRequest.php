<?php

namespace Modules\EnterpriseData\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExchangeConnectorRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'config' => [
                'required',
                'file',
                'mimes:xml',
                'max:1024',
            ],
        ];
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }
}
