<?php

namespace Modules\Customer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Admin\Models\SupportConfig;

class SupportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $cityId = $request->user()?->city_id;

        $items = SupportConfig::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('city_id')->orWhere('city_id', $cityId))
            ->orderBy('priority')
            ->get(['id', 'title', 'subtitle', 'icon', 'type', 'value', 'color']);

        return response()->json(['data' => $items]);
    }
}
