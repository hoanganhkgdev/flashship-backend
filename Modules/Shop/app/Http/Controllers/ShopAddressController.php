<?php

namespace Modules\Shop\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class ShopAddressController extends Controller
{
    private const MAX_ADDRESSES = 50;

    public function index(Request $request): JsonResponse
    {
        $items = DB::table('shop_addresses')
            ->where('shop_user_id', $request->user()->id)
            ->orderByDesc('updated_at')
            ->get(['id', 'label', 'name', 'phone', 'address', 'lat', 'lng'])
            ->map(fn ($a) => [
                'id'      => $a->id,
                'label'   => $a->label,
                'name'    => $a->name,
                'phone'   => $a->phone,
                'address' => $a->address,
                'lat'     => $a->lat ? (float) $a->lat : null,
                'lng'     => $a->lng ? (float) $a->lng : null,
            ]);

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $count = DB::table('shop_addresses')
            ->where('shop_user_id', $request->user()->id)
            ->count();

        if ($count >= self::MAX_ADDRESSES) {
            return response()->json([
                'success' => false,
                'message' => 'Tối đa ' . self::MAX_ADDRESSES . ' địa chỉ.',
            ], 422);
        }

        $data = $request->validate([
            'label'   => 'nullable|string|max:100',
            'name'    => 'required|string|max:100',
            'phone'   => 'required|string|max:20',
            'address' => 'required|string|max:500',
            'lat'     => 'nullable|numeric',
            'lng'     => 'nullable|numeric',
        ]);

        $id = DB::table('shop_addresses')->insertGetId([
            'shop_user_id' => $request->user()->id,
            'label'        => $data['label'] ?? null,
            'name'         => $data['name'],
            'phone'        => $data['phone'],
            'address'      => $data['address'],
            'lat'          => $data['lat'] ?? null,
            'lng'          => $data['lng'] ?? null,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $item = DB::table('shop_addresses')->find($id);

        return response()->json([
            'success' => true,
            'data'    => [
                'id'      => $item->id,
                'label'   => $item->label,
                'name'    => $item->name,
                'phone'   => $item->phone,
                'address' => $item->address,
                'lat'     => $item->lat ? (float) $item->lat : null,
                'lng'     => $item->lng ? (float) $item->lng : null,
            ],
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $address = DB::table('shop_addresses')
            ->where('id', $id)
            ->where('shop_user_id', $request->user()->id)
            ->first();

        if (!$address) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy địa chỉ'], 404);
        }

        $data = $request->validate([
            'label'   => 'nullable|string|max:100',
            'name'    => 'required|string|max:100',
            'phone'   => 'required|string|max:20',
            'address' => 'required|string|max:500',
            'lat'     => 'nullable|numeric',
            'lng'     => 'nullable|numeric',
        ]);

        DB::table('shop_addresses')
            ->where('id', $id)
            ->update(array_merge($data, ['updated_at' => now()]));

        return response()->json(['success' => true, 'message' => 'Đã cập nhật địa chỉ']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $deleted = DB::table('shop_addresses')
            ->where('id', $id)
            ->where('shop_user_id', $request->user()->id)
            ->delete();

        if (!$deleted) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy địa chỉ'], 404);
        }

        return response()->json(['success' => true, 'message' => 'Đã xóa địa chỉ']);
    }
}
