<?php

namespace App\Http\Requests\Reservation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ConditionalRules;
use Illuminate\Validation\Rule;

class StoreRequest extends FormRequest
{
    /**
     * Use the required header as the authoritative idempotency key.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return [...parent::validationData(), 'idempotency_key' => $this->header('Idempotency-Key')];
    }

    /**
     * Validate reservation input and reject server-owned fields.
     *
     * @return array<string, string|list<string|ConditionalRules>>
     */
    public function rules(): array
    {
        return [
            'product_id' => [
                'required',
                Rule::when(is_string($this->input('product_id')), 'integer', 'integer:strict'),
                'min:1'
            ],
            'quantity' => 'required|integer:strict|min:1',
            'idempotency_key' => 'required|string|max:128|regex:/\S/u|not_regex:/[\x00-\x1F\x7F]/',
            'user_id' => 'missing',
            'owner_id' => 'missing',
            'status' => 'missing',
            'expires_at' => 'missing',
            'created_at' => 'missing',
            'updated_at' => 'missing',
            'finalized_at' => 'missing',
            'id' => 'missing'
        ];
    }
}
