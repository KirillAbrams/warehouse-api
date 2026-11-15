<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ConditionalRules;
use Illuminate\Validation\Rule;

/**
 * Проверяет поля создания резерва и обязательный заголовок защиты от повтора. Владелец, состояние и даты
 * назначаются только приложением.
 */
class StoreReservationRequest extends FormRequest
{
    /**
     * Добавляет заголовок защиты от повтора к проверяемым данным. Значение из тела запроса не может подменить
     * этот заголовок.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return [...parent::validationData(), 'idempotency_key' => $this->header('Idempotency-Key')];
    }

    /**
     * Задаёт допустимые виды номера товара, положительное целое количество и требования к ключу. Отдельно
     * запрещает все поля, которыми управляет приложение, даже если клиент передал пустое значение.
     *
     * @return array<string, list<string|ConditionalRules>>
     */
    public function rules(): array
    {
        return [
            'product_id' => [
                'required',
                Rule::when(is_string($this->input('product_id')), 'integer', 'integer:strict'),
                'min:1',
            ],
            'quantity' => ['required', 'integer:strict', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:128', 'regex:/\S/u', 'not_regex:/[\x00-\x1F\x7F]/'],
            'user_id' => ['missing'],
            'owner_id' => ['missing'],
            'status' => ['missing'],
            'expires_at' => ['missing'],
            'created_at' => ['missing'],
            'updated_at' => ['missing'],
            'finalized_at' => ['missing'],
            'id' => ['missing'],
        ];
    }
}
