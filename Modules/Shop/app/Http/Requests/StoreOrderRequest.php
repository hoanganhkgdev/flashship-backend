<?php

namespace Modules\Shop\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_outbound' => 'nullable|boolean',
            'pickup_address' => 'required|string',
            'pickup_lat' => 'nullable|numeric',
            'pickup_lng' => 'nullable|numeric',
            'pickup_phone' => 'nullable|string',
            'pickup_name' => 'nullable|string',
            'pickup_place_name' => 'nullable|string|max:100',
            'delivery_address' => 'required|string',
            'delivery_lat' => 'nullable|numeric',
            'delivery_lng' => 'nullable|numeric',
            'delivery_phone' => 'required|string',
            'delivery_name' => 'nullable|string',
            'delivery_place_name' => 'nullable|string|max:100',
            'order_note' => 'nullable|string',
            'cargo_type' => 'nullable|in:food,flowers,parcel',
            'cargo_note' => 'nullable|string|max:500',
            'cargo_weight' => 'nullable|numeric|min:0.1|max:999',
            'cod_amount' => 'nullable|integer|min:0',
            'voucher_code' => 'nullable|string',
        ];
    }
}
