<?php

namespace Damms005\LaravelMultipay\Http\Requests;

use Damms005\LaravelMultipay\Services\PaymentHandlers\BasePaymentHandler;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InitiatePaymentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            //in ISO-4217 format
            'currency' => ['required', 'string'],

            'amount' => ['required', 'numeric'],

            'user_id' => ['required', 'numeric'],

            'transaction_description' => ['required', 'string'],

            'metadata' => ['json'],

            'payment_processor' => [
                'required',
                Rule::in(BasePaymentHandler::getNamesOfPaymentHandlers()),
            ],
        ];
    }
}
