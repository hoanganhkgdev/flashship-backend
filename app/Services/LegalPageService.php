<?php

namespace App\Services;

use Modules\Admin\Models\Page;
use Modules\Admin\Models\PageVersion;

/** Lưu và khôi phục phiên bản trang pháp lý; ánh xạ trang web công khai tới trang trong CSDL. */
class LegalPageService
{
    /** Đường dẫn web công khai (link trên App Store/Google Play) → slug trong CSDL. */
    public const WEB = ['privacy' => 'privacy-policy', 'terms' => 'terms-of-service'];

    /** Trang quá cũ (ngày) thì nhắc rà soát. */
    public const STALE_DAYS = 180;

    /** Lưu lại bản đang có TRƯỚC khi sửa, ghi nhận người sửa. */
    public static function snapshot(Page $page, ?int $by): void
    {
        PageVersion::create(['page_id' => $page->id, 'title' => $page->title, 'content' => (string) $page->content, 'performed_by' => $by]);
    }

    /** Khôi phục một phiên bản cũ; bản hiện tại được lưu lại trước nên khôi phục cũng hoàn tác được. */
    public static function restore(PageVersion $version, ?int $by): void
    {
        $page = Page::findOrFail($version->page_id);
        self::snapshot($page, $by);
        $page->update(['title' => $version->title, 'content' => $version->content]);
    }

    public static function webPathFor(string $slug): ?string
    {
        $path = array_search($slug, self::WEB, true);

        return $path === false ? null : '/'.$path;
    }

    public static function isStale(Page $page): bool
    {
        return $page->updated_at && $page->updated_at->lt(now()->subDays(self::STALE_DAYS));
    }
}
