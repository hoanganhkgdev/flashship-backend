<?php

namespace Modules\Shop\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBatchOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pickup_address' => 'required|string',
            'pickup_lat' => 'nullable|numeric',
            'pickup_lng' => 'nullable|numeric',
            'pickup_phone' => 'nullable|string',
            'pickup_name' => 'nullable|string',
            'cargo_type' => 'nullable|in:food,flowers,parcel',
            'cargo_weight' => 'nullable|numeric|min:0',
            'order_note' => 'nullable|string',
            'voucher_code' => 'nullable|string',
            'stops' => 'required|array|min:1|max:10',
            'stops.*.address' => 'required|string',
            'stops.*.lat' => 'nullable|numeric',
            'stops.*.lng' => 'nullable|numeric',
            'stops.*.phone' => 'required|string',
            'stops.*.name' => 'nullable|string',
            'stops.*.cod_amount' => 'nullable|integer|min:0',
            'stops.*.note' => 'nullable|string',
        ];
    }
}
