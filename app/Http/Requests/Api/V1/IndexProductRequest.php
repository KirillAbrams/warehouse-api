<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Проверяет параметры списка товаров до чтения каталога.
 */
class IndexProductRequest extends FormRequest
{
    /**
     * Разрешает положительный номер страницы и ограниченный размер выдачи. Это не позволяет запросить весь
     * каталог одним слишком большим ответом.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config()->integer('reservations.max_per_page')],
        ];
    }
}
