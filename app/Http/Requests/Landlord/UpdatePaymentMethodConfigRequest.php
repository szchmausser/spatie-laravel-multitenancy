<?php

namespace App\Http\Requests\Landlord;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentMethodConfigRequest extends FormRequest
{
    public function rules(): array
    {
        $config = $this->route('payment_method_config');

        return [
            'label' => [
                'required',
                'string',
                'max:255',
                Rule::unique('payment_method_configs')
                    ->where('type', $config->type)
                    ->ignore($config->id),
            ],
            'bank_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:255'],
            'account_holder' => ['required', 'string', 'max:255'],
            'holder_id' => ['required', 'string', 'max:20'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'label.required' => 'La etiqueta es obligatoria.',
            'label.unique' => 'Ya existe una cuenta con esta etiqueta para este tipo de pago.',
            'bank_name.required' => 'El nombre del banco es obligatorio.',
            'account_number.required' => 'El número de cuenta o teléfono es obligatorio.',
            'account_holder.required' => 'El titular de la cuenta es obligatorio.',
            'holder_id.required' => 'El RIF o cédula es obligatorio.',
            'holder_id.max' => 'El RIF o cédula no puede exceder :max caracteres.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
