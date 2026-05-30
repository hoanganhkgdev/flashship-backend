<?php

namespace Modules\Customer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Admin\Models\Page;

class LegalController extends Controller
{
    public function show(string $slug): JsonResponse
    {
        $page = Page::where('slug', $slug)->where('is_active', true)->first();

        if (!$page) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy trang'], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'title'   => $page->title,
                'content' => $page->content,
            ],
        ]);
    }
}
