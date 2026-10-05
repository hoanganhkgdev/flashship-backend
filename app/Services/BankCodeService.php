<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Mã ngân hàng chuẩn là số BIN 6 chữ số (dùng chuyển khoản PayOS). Xử lý các tài khoản cũ lưu mã dạng chữ. */
class BankCodeService
{
    public static function isBin(?string $code): bool
    {
        return (bool) preg_match('/^\d{6}$/', (string) $code);
    }

    private static function norm(?string $v): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii((string) $v)));
    }

    /** Tài khoản ngân hàng của tài xế có mã không phải BIN. */
    public static function legacyAccounts(): Collection
    {
        return DB::table('banks as b')->join('users as u', 'u.id', '=', 'b.user_id')
            ->whereRaw("b.bank_code NOT REGEXP '^[0-9]{6}$'")->orWhereNull('b.bank_code')
            ->orderBy('u.name')
            ->get(['b.id', 'b.user_id', 'u.name as driver', 'u.phone', 'b.bank_code', 'b.bank_name']);
    }

    /** Khớp mã/tên cũ với danh mục hiện có, trả về [ngân hàng khớp hoặc null]. */
    public static function match(object $account): ?object
    {
        $banks = DB::table('bank_lists')->get(['code', 'name']);
        foreach ([$account->bank_code, $account->bank_name] as $candidate) {
            $c = self::norm($candidate);
            if ($c === '') {
                continue;
            }
            $hit = $banks->first(fn ($b) => self::norm($b->name) === $c);
            if ($hit) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * Quy đổi các tài khoản cũ về mã BIN. $apply=false chỉ trả kết quả dự kiến.
     *
     * @return array{fixed:Collection,unmatched:Collection}
     */
    public static function fixLegacy(bool $apply): array
    {
        $fixed = collect();
        $unmatched = collect();
        foreach (self::legacyAccounts() as $acc) {
            $bank = self::match($acc);
            if (! $bank) {
                $unmatched->push($acc);

                continue;
            }
            $fixed->push(['account' => $acc, 'bank' => $bank]);
            if ($apply) {
                DB::table('banks')->where('id', $acc->id)->update(['bank_code' => $bank->code, 'bank_name' => $bank->name, 'updated_at' => now()]);
            }
        }

        return ['fixed' => $fixed, 'unmatched' => $unmatched];
    }

    /** Số tài xế đang dùng từng mã ngân hàng. */
    public static function usage(): Collection
    {
        return DB::table('banks')->selectRaw('bank_code, COUNT(*) c')->groupBy('bank_code')->pluck('c', 'bank_code');
    }
}
