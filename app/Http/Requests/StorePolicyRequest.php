<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StorePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_request_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'agent_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'customer_name' => ['required', 'string', 'min:2', 'max:255'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before:trip_start'],
            'destination' => ['required', 'string', Rule::in(['DOMESTIC', 'INTERNATIONAL'])],
            'trip_start' => ['required', 'date_format:Y-m-d'],
            'trip_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:trip_start'],
            'plan_code' => ['required', 'string', Rule::in(['BASIC', 'PLUS', 'PREMIUM'])],
            'sum_insured' => ['required', Rule::in([100000, 300000, 500000, '100000', '300000', '500000'])],
        ];
    }

    public function messages(): array
    {
        return [
            'date_of_birth.before' => 'date_of_birth must be earlier than trip_start.',
            'trip_end.after_or_equal' => 'trip_end must be on or after trip_start.',
            'destination.in' => 'destination must be either DOMESTIC or INTERNATIONAL.',
            'plan_code.in' => 'plan_code must be one of: BASIC, PLUS, PREMIUM.',
            'sum_insured.in' => 'sum_insured must be exactly one of: 100000, 300000, or 500000.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The given data was invalid.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
