<?php

namespace Tests\Feature\Driver;

use Illuminate\Support\Facades\DB;
use Modules\Driver\Services\DriverScoreService;
use Modules\Driver\Services\DriverWalletService;
use Tests\TestCase;

/**
 * Bảo vệ khỏi tái diễn 2 lớp bug tìm thấy 2026-08-19: (1) đọc-sửa-ghi điểm/
 * tiền không khoá dòng driver, 2 sự kiện gần như đồng thời có thể mất 1 lần
 * cộng/trừ; (2) merge khoảng thời gian chồng lấp sai khi tính % online.
 *
 * ─── Yêu cầu môi trường: cần 1 DB MySQL test riêng ─────────────────────────
 * Chạy trên MySQL thật (DB riêng "flashship_backend_test"), KHÔNG dùng SQLite
 * mặc định của bộ test — lockForUpdate() chỉ có ý nghĩa thật với InnoDB
 * row-lock, SQLite không mô phỏng được hành vi khoá/chờ khoá. Máy/CI nào
 * chưa có DB này thì tạo bằng cách clone schema từ DB dev (không cần dữ liệu
 * mẫu, các test tự insert driver riêng):
 *   mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS flashship_backend_test;"
 *   mysqldump -u root -p --no-data --routines --skip-triggers \
 *     --set-gtid-purged=OFF flashship_backend | mysql -u root -p flashship_backend_test
 *   mysqldump -u root -p --no-create-info --set-gtid-purged=OFF \
 *     flashship_backend migrations | mysql -u root -p flashship_backend_test
 * (Chạy thẳng `php artisan migrate` trên DB rỗng hiện KHÔNG dùng được — có 1
 * migration bị lỗi thứ tự phụ thuộc bảng `shifts`, xem
 * 2026_06_30_144531_add_shift_id_to_users_table.php — chưa sửa, ngoài phạm
 * vi bug này.)
 *
 * ─── Kỹ thuật chứng minh "mất update" (lost update) ────────────────────────
 * Thử nghiệm ban đầu dùng cách "giữ khoá ở 1 transaction riêng rồi chờ hàm
 * cần test ném lỗi lock-wait-timeout" — SAI, vì bất kỳ câu UPDATE nào cũng
 * phải chờ khoá được nhả bất kể code có gọi lockForUpdate() hay không
 * (UPDATE luôn cần khoá ghi độc quyền); test kiểu đó PASS cả khi code KHÔNG
 * khoá gì cả, không bắt được bug (đã tự kiểm chứng: revert code thật rồi
 * chạy lại test cũ, vẫn PASS — sai).
 *
 * Cách đúng: tái hiện đúng tình huống race thật — 1 giao dịch khác đã ÂM
 * THẦM ĐỔI GIÁ TRỊ (chưa commit) trong lúc hàm cần test đang chạy trong TIẾN
 * TRÌNH RIÊNG THẬT (proc_open ra `artisan tinker`, KHÔNG dùng pcntl_fork() —
 * fork() nhân đôi cả socket MySQL đang mở, khiến bên con dù mở kết nối mới
 * vẫn vô tình làm chết phiên MySQL phía server dùng chung với cha, ném lỗi
 * "MySQL server has gone away"). Nếu hàm khoá đúng, nó phải CHỜ tới khi giao
 * dịch kia commit rồi mới đọc, thấy đúng giá trị mới. Nếu không khoá, nó đọc
 * giá trị CŨ ngay, tính toán dựa trên giá trị cũ, rồi ghi đè mất thay đổi
 * của giao dịch kia khi cuối cùng cũng ghi được (sau khi giao dịch kia
 * commit và nhả khoá cho UPDATE của nó) — kiểm tra GIÁ TRỊ CUỐI CÙNG, không
 * kiểm tra có ném lỗi hay không. Đã tự kiểm chứng cả 2 chiều: revert code
 * thật → 2 test race fail đúng với giá trị sai dự đoán được (90 thay vì 50,
 * 250000 thay vì 200000); khôi phục code thật → cả 2 pass.
 */
class ScoreAndWalletRaceConditionTest extends TestCase
{
    private const TEST_CONNECTION = [
        'driver'    => 'mysql',
        'host'      => '127.0.0.1',
        'port'      => '3306',
        'database'  => 'flashship_backend_test',
        'username'  => 'root',
        'password'  => 'Nika221124@',
        'charset'   => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'strict'    => true,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Trỏ connection mặc định ("mysql", nơi DriverScoreService/
        // DriverWalletService thật sự chạy) vào DB test riêng — không đụng
        // DB dev/production.
        config(['database.connections.mysql' => self::TEST_CONNECTION]);
        config(['database.default' => 'mysql']);
        DB::purge('mysql');

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('driver_score_logs')->truncate();
        DB::table('driver_wallet_transactions')->truncate();
        DB::table('driver_wallets')->truncate();
        DB::table('users')->where('email', 'like', 'race-test-%')->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function makeDriver(int $score = 100): int
    {
        return DB::table('users')->insertGetId([
            'name'         => 'Race Test Driver',
            'email'        => 'race-test-' . uniqid('', true) . '@test.local',
            'password'     => 'x',
            'user_type'    => 'driver',
            'driver_score' => $score,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    /**
     * Chạy $code trong 1 TIẾN TRÌNH RIÊNG THẬT (proc_open + `artisan
     * tinker`), không phải pcntl_fork() — fork() nhân đôi luôn cả socket
     * MySQL đang mở của tiến trình cha, và bên con dù có gọi DB::purge() để
     * mở kết nối mới thì lúc PDO cũ trong con bị huỷ vẫn gửi gói QUIT xuống
     * đúng socket TCP đang dùng chung với cha (fork chỉ nhân bản file
     * descriptor, không nhân bản phiên MySQL phía server) — làm chết luôn
     * kết nối của cha ("MySQL server has gone away"). proc_open thực sự
     * exec() ra tiến trình PHP hoàn toàn mới, không có gì để chia sẻ/đụng
     * độ với kết nối của tiến trình test.
     *
     * Đợi 1 chút rồi mới commit transaction "đang giữ" ở tiến trình cha —
     * mô phỏng đúng thứ tự 1 giao dịch khác đang xử lý (chưa commit) ngay
     * khi hàm cần test bắt đầu chạy.
     */
    private function runInSeparateProcess(string $code): void
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open('php artisan tinker', $descriptors, $pipes, base_path());
        if (!is_resource($process)) {
            $this->fail('Không mở được tiến trình con (proc_open) để test race condition.');
        }

        fwrite($pipes[0], $code . "\n");
        fclose($pipes[0]);

        usleep(300_000); // đủ để tiến trình con chắc chắn đã tới bước đọc/khoá
        DB::commit();

        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }

    private function connectionOverrideCode(): string
    {
        // Tiến trình con (proc_open) kế thừa nguyên biến môi trường của
        // process test — gồm cả DB_CONNECTION=sqlite mà phpunit.xml gán
        // cứng — nên phải override CẢ database.default lẫn config kết nối
        // "mysql", không chỉ mỗi config kết nối (nếu không, DB::table(...)
        // không chỉ định connection cụ thể vẫn âm thầm rơi về sqlite).
        $db = self::TEST_CONNECTION;
        return "config(['database.default' => 'mysql', 'database.connections.mysql' => ['driver'=>'{$db['driver']}','host'=>'{$db['host']}',"
            . "'port'=>'{$db['port']}','database'=>'{$db['database']}','username'=>'{$db['username']}',"
            . "'password'=>'{$db['password']}','charset'=>'{$db['charset']}']]);"
            . "\\Illuminate\\Support\\Facades\\DB::purge('mysql');";
    }

    // ─── Không mất update khi có giao dịch khác chen vào (chống bug 2026-08-19) ──

    public function test_on_shift_online_rate_does_not_lose_a_concurrent_score_change(): void
    {
        $driverId = $this->makeDriver(100);

        // Mô phỏng 1 sự kiện chấm điểm KHÁC đang xử lý cùng dòng driver này
        // (vd onComplete() vừa cộng streak) — đổi điểm 100 → 60, CHƯA commit.
        DB::beginTransaction();
        DB::table('users')->where('id', $driverId)->lockForUpdate()->first();
        DB::table('users')->where('id', $driverId)->update(['driver_score' => 60]);

        $this->runInSeparateProcess($this->connectionOverrideCode() .
            "\\Modules\\Driver\\Services\\DriverScoreService::onShiftOnlineRate({$driverId}, 0.55);"); // shift_online_low, -10

        $finalScore = (int) DB::table('users')->where('id', $driverId)->value('driver_score');

        $this->assertSame(50, $finalScore,
            'onShiftOnlineRate() phải thấy giá trị 60 vừa commit (không phải ' .
            '100 cũ) rồi mới trừ tiếp 10 → đúng phải ra 50. Ra ' . $finalScore .
            ' nghĩa là hàm đọc giá trị CŨ trước khi giao dịch khác commit rồi ' .
            'ghi đè mất thay đổi đó — tái hiện đúng bug 2026-08-19 (mất 1 lần ' .
            'cộng/trừ điểm khi trùng lúc với 1 sự kiện chấm điểm khác).');
    }

    public function test_wallet_adjust_does_not_lose_a_concurrent_balance_change(): void
    {
        $driverId = $this->makeDriver();
        DriverWalletService::adjust($driverId, 200_000, 'credit', 'seed', 'seed_' . $driverId);

        // Mô phỏng 1 giao dịch ví KHÁC đang xử lý cùng dòng ví này (vd 1 đơn
        // khác vừa trừ tiền) — đổi số dư 200k → 150k, CHƯA commit.
        DB::beginTransaction();
        DB::table('driver_wallets')->where('driver_id', $driverId)->lockForUpdate()->first();
        DB::table('driver_wallets')->where('driver_id', $driverId)->update(['balance' => 150_000]);

        $this->runInSeparateProcess($this->connectionOverrideCode() .
            "\\Modules\\Driver\\Services\\DriverWalletService::adjust({$driverId}, 50000, 'credit', 'thuong', 'concurrent_bonus');");

        $finalBalance = DB::table('driver_wallets')->where('driver_id', $driverId)->value('balance');

        $this->assertEquals(200_000, $finalBalance,
            'adjust() phải thấy số dư 150k vừa commit (không phải 200k cũ) ' .
            'rồi mới cộng thêm 50k → đúng phải ra 200k. Ra ' . $finalBalance .
            ' nghĩa là hàm đọc số dư CŨ trước khi giao dịch khác commit rồi ' .
            'ghi đè mất thay đổi đó — tái hiện đúng bug ví 2026-08-19 (mất 1 ' .
            'giao dịch khi 2 lệnh cộng/trừ tiền chạy gần như đồng thời).');
    }

    // ─── Đúng kết quả (không cần race, chạy tuần tự) ──────────────────────────

    public function test_on_shift_online_rate_score_brackets(): void
    {
        $cases = [
            0.90 => [0,   'shift_online_normal'],
            0.75 => [-3,  'shift_online_reduced'],
            0.65 => [-5,  'shift_online_mid'],
            0.55 => [-10, 'shift_online_low'],
            0.30 => [-15, 'shift_online_critical'],
        ];

        foreach ($cases as $percent => [$expectedDelta, $expectedReason]) {
            $driverId = $this->makeDriver(100);
            DriverScoreService::onShiftOnlineRate($driverId, $percent);

            $log = DB::table('driver_score_logs')->where('driver_id', $driverId)->latest('id')->first();

            $this->assertNotNull($log, "Không có log điểm nào được ghi cho percent={$percent}");
            $this->assertSame($expectedReason, $log->reason, "Sai reason cho percent={$percent}");
            $this->assertSame($expectedDelta, (int) $log->delta, "Sai delta cho percent={$percent}");
            $this->assertSame(100 + $expectedDelta, (int) $log->score_after, "Sai score_after cho percent={$percent}");
        }
    }

    public function test_on_shift_online_rate_clamps_to_min_score(): void
    {
        $driverId = $this->makeDriver(5); // gần MIN_SCORE (0)
        DriverScoreService::onShiftOnlineRate($driverId, 0.0); // shift_online_critical, -15

        $score = DB::table('users')->where('id', $driverId)->value('driver_score');
        $this->assertSame(0, (int) $score, 'Điểm không được xuống dưới MIN_SCORE (0)');
    }

    public function test_offer_penalties_match_business_rules(): void
    {
        $viewedDriver = $this->makeDriver(100);
        DriverScoreService::onViewedTimeout($viewedDriver);
        $this->assertSame(98, (int) DB::table('users')->where('id', $viewedDriver)->value('driver_score'));

        // DB test legacy chưa có cột unviewed_offer_count; khóa mức
        // phạt. DispatchService chỉ gọi khi có received_at ACK.
        $this->assertSame(-2, DriverScoreService::SCORE_UNVIEWED_X3);
    }

    public function test_wallet_balance_correct_after_sequential_credit_and_debit(): void
    {
        $driverId = $this->makeDriver();

        DriverWalletService::adjust($driverId, 200_000, 'credit', 'đơn 1', 'order_1');
        DriverWalletService::adjust($driverId, 150_000, 'credit', 'đơn 2', 'order_2');
        DriverWalletService::adjust($driverId, 100_000, 'debit', 'rút tiền', 'withdraw_1');

        $balance = DB::table('driver_wallets')->where('driver_id', $driverId)->value('balance');
        $this->assertEquals(250_000, $balance,
            '200k + 150k - 100k phải ra đúng 250k — sai nghĩa là có giao dịch bị mất hoặc cộng/trừ sai chiều.');
    }

    public function test_wallet_adjust_rejects_debit_beyond_balance(): void
    {
        $driverId = $this->makeDriver();
        DriverWalletService::adjust($driverId, 50_000, 'credit', 'seed', 'seed_1');

        $this->expectException(\Exception::class);
        DriverWalletService::adjust($driverId, 100_000, 'debit', 'rút quá số dư', 'withdraw_over');
    }

    public function test_wallet_adjust_is_idempotent_for_duplicate_reference(): void
    {
        $driverId = $this->makeDriver();

        DriverWalletService::adjust($driverId, 100_000, 'credit', 'đơn 1', 'order_dup');
        DriverWalletService::adjust($driverId, 100_000, 'credit', 'đơn 1 (gọi lại)', 'order_dup');

        $balance = DB::table('driver_wallets')->where('driver_id', $driverId)->value('balance');
        $this->assertEquals(100_000, $balance,
            'Gọi adjust() 2 lần cùng $ref (vd job retry) không được cộng tiền 2 lần.');

        $count = DB::table('driver_wallet_transactions')
            ->whereIn('wallet_id', DB::table('driver_wallets')->where('driver_id', $driverId)->pluck('id'))
            ->where('reference', 'order_dup')
            ->count();
        $this->assertSame(1, $count, 'Không được tạo 2 dòng giao dịch trùng reference.');
    }
}
