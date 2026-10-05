<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_debt_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('debt_id')->index();
            // created | adjusted | paid_wallet | paid_payos | paid_manual | waived | overdue | reminded
            $table->string('action', 20);
            $table->decimal('amount', 12, 0)->default(0);
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('driver_debts', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('status');
            $table->string('paid_via', 20)->nullable()->after('paid_at'); // wallet | payos | manual | waived
        });

        // Dữ liệu cũ: suy kênh thu từ các nguồn đã có.
        DB::statement("UPDATE driver_debts d JOIN payment_orders p ON p.type = 'debt_payment' AND p.status = 'paid' AND p.ref_id = d.id
            SET d.paid_via = 'payos', d.paid_at = p.updated_at WHERE d.status = 'paid'");
        DB::statement("UPDATE driver_debts SET paid_at = updated_at WHERE status = 'paid' AND paid_at IS NULL");
        DB::statement("UPDATE driver_debts d JOIN driver_wallet_transactions t ON t.reference = CONCAT('debt_admin_', d.id)
            SET d.paid_via = 'wallet' WHERE d.status = 'paid' AND d.paid_via IS NULL");
    }

    public function down(): void
    {
        Schema::table('driver_debts', function (Blueprint $table) {
            $table->dropColumn(['paid_at', 'paid_via']);
        });
        Schema::dropIfExists('driver_debt_logs');
    }
};
