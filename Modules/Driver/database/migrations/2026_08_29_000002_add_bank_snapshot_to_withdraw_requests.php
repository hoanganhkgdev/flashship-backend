<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdraw_requests', function (Blueprint $table) {
            $table->string('bank_code', 50)->nullable()->after('amount');
            $table->string('bank_name')->nullable()->after('bank_code');
            $table->string('account_number', 50)->nullable()->after('bank_name');
            $table->string('account_name')->nullable()->after('account_number');
        });

        // Các yêu cầu cũ chưa xử lý cũng phải có ảnh chụp STK tại thời điểm
        // rollout; từ đây admin không còn đọc quan hệ bank đang thay đổi nữa.
        DB::table('withdraw_requests')->where('status', 'pending')
            ->orderBy('id')->chunkById(200, function ($requests) {
                foreach ($requests as $request) {
                    $bank = DB::table('banks')->where('user_id', $request->driver_id)->first();
                    if (!$bank) continue;
                    DB::table('withdraw_requests')->where('id', $request->id)->update([
                        'bank_code' => $bank->bank_code,
                        'bank_name' => $bank->bank_name,
                        'account_number' => $bank->account_number,
                        'account_name' => $bank->account_name,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('withdraw_requests', function (Blueprint $table) {
            $table->dropColumn(['bank_code', 'bank_name', 'account_number', 'account_name']);
        });
    }
};
