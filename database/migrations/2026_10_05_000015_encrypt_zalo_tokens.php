<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PREFIX = 'enc:v1:';

    public function up(): void
    {
        // Token mã hóa dài hơn bản gốc nên cột phải đủ rộng.
        DB::statement('ALTER TABLE zalo_tokens MODIFY access_token TEXT NOT NULL, MODIFY refresh_token TEXT NOT NULL');

        // Chỉ giữ bản mới nhất: các bản cũ còn refresh token nhưng không còn tác dụng.
        $latest = DB::table('zalo_tokens')->max('id');
        if ($latest) {
            DB::table('zalo_tokens')->where('id', '<', $latest)->delete();
        }

        foreach (DB::table('zalo_tokens')->get() as $row) {
            $update = [];
            foreach (['access_token', 'refresh_token'] as $col) {
                if (! str_starts_with((string) $row->{$col}, self::PREFIX)) {
                    $update[$col] = self::PREFIX.Crypt::encryptString((string) $row->{$col});
                }
            }
            if ($update) {
                DB::table('zalo_tokens')->where('id', $row->id)->update($update);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('zalo_tokens')->get() as $row) {
            $update = [];
            foreach (['access_token', 'refresh_token'] as $col) {
                if (str_starts_with((string) $row->{$col}, self::PREFIX)) {
                    $update[$col] = Crypt::decryptString(substr($row->{$col}, strlen(self::PREFIX)));
                }
            }
            if ($update) {
                DB::table('zalo_tokens')->where('id', $row->id)->update($update);
            }
        }
    }
};
