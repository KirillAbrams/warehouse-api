<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

class FilterRequest extends FormRequest
{
    /**
     * Validate page number and bounded page size.
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'page' => 'sometimes|integer|min:1|max:2147483647',
            'per_page' => 'sometimes|integer|min:1|max:' . config()->integer('reservations.max_per_page')
        ];
    }
}
