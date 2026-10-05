<?php

namespace Modules\Shop\Http\Controllers;

use App\Services\SupportChannelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SupportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => SupportChannelService::forApp('shop', $request->user()?->city_id)]);
    }
}
