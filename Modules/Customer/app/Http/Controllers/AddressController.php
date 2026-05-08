<?php
namespace Modules\Customer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Customer\Models\CustomerAddress;

class AddressController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $addresses = CustomerAddress::where('user_id', $request->user()->id)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();

        return response()->json(['success' => true, 'data' => $addresses]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label'      => 'nullable|string|max:50',
            'address'    => 'required|string',
            'latitude'   => 'nullable|numeric',
            'longitude'  => 'nullable|numeric',
            'is_default' => 'boolean',
        ]);

        $userId = $request->user()->id;

        if (!empty($data['is_default'])) {
            CustomerAddress::where('user_id', $userId)->update(['is_default' => false]);
        }

        $address = CustomerAddress::create(array_merge($data, ['user_id' => $userId]));

        return response()->json(['success' => true, 'data' => $address], 201);
    }

    public function update(Request $request, CustomerAddress $address): JsonResponse
    {
        if ($address->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Không có quyền'], 403);
        }

        $data = $request->validate([
            'label'      => 'nullable|string|max:50',
            'address'    => 'required|string',
            'latitude'   => 'nullable|numeric',
            'longitude'  => 'nullable|numeric',
            'is_default' => 'boolean',
        ]);

        if (!empty($data['is_default'])) {
            CustomerAddress::where('user_id', $address->user_id)
                ->where('id', '!=', $address->id)
                ->update(['is_default' => false]);
        }

        $address->update($data);

        return response()->json(['success' => true, 'data' => $address->fresh()]);
    }

    public function setDefault(Request $request, CustomerAddress $address): JsonResponse
    {
        if ($address->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Không có quyền'], 403);
        }

        CustomerAddress::where('user_id', $address->user_id)->update(['is_default' => false]);
        $address->update(['is_default' => true]);

        return response()->json(['success' => true, 'data' => $address->fresh()]);
    }

    public function destroy(Request $request, CustomerAddress $address): JsonResponse
    {
        if ($address->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Không có quyền'], 403);
        }

        $address->delete();

        return response()->json(['success' => true]);
    }
}
