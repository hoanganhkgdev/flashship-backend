<?php

namespace App\Console\Commands;

use App\Support\KycFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Chuyển ảnh CCCD / bằng lái đang nằm ở ổ public (xem được bằng đường dẫn công khai) sang ổ riêng. Chạy lại nhiều lần vẫn an toàn. */
class MakeKycFilesPrivate extends Command
{
    protected $signature = 'kyc:make-private {--dry-run : Chỉ đếm, không chuyển}';

    protected $description = 'Chuyển ảnh CCCD / bằng lái từ ổ public sang ổ riêng';

    public function handle(): int
    {
        $public = Storage::disk('public');
        $private = Storage::disk(KycFile::DISK);
        $moved = $missing = $already = 0;
        $dry = (bool) $this->option('dry-run');

        $referenced = DB::table('driver_cccd_images')->pluck('image_path')
            ->merge(DB::table('driver_licenses')->pluck('image_path'))
            ->filter(fn ($p) => $p && KycFile::isValidPath($p))->unique();

        foreach ($referenced as $path) {
            if ($private->exists($path)) {
                $already++;
                // Đã có ở ổ riêng thì bản công khai (nếu còn) phải biến mất.
                $dry || $public->delete($path);

                continue;
            }
            if (! $public->exists($path)) {
                $missing++;

                continue;
            }
            $dry || $this->move($public, $private, $path);
            $moved++;
        }

        // Ảnh cũ không còn dòng nào trỏ tới (bị thay bằng ảnh mới) cũng là giấy tờ tùy thân: chuyển sang ổ riêng,
        // KHÔNG xóa, để không mất gì nếu sau này cần đối chiếu.
        $orphans = collect(KycFile::DIRS)->flatMap(fn ($dir) => $public->files($dir))
            ->filter(fn ($p) => KycFile::isValidPath($p))->reject(fn ($p) => $referenced->contains($p))->values();
        if (! $dry) {
            $orphans->each(fn ($p) => $this->move($public, $private, $p));
        }

        $this->info(($dry ? '[thử] ' : '')."Ảnh đang dùng: chuyển {$moved}, đã ở ổ riêng {$already}, thiếu file {$missing}. Ảnh cũ không còn dùng: chuyển {$orphans->count()}.");

        return self::SUCCESS;
    }

    /** Ghi sang ổ riêng, chỉ xóa bản công khai khi bản riêng đã đúng kích thước. */
    private function move($from, $to, string $path): void
    {
        if (! $to->exists($path)) {
            $to->put($path, $from->get($path));
        }
        if ($to->size($path) === $from->size($path)) {
            $from->delete($path);
        }
    }
}
