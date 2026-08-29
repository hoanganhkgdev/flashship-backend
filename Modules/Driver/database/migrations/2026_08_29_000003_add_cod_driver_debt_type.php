<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('driver_debts', 'debt_type')) return;

        Schema::table('driver_debts', function (Blueprint $table) {
            $table->enum('debt_type', ['weekly', 'commission', 'cod'])
                ->default('weekly')->after('driver_id');
        });
    }
    public function down(): void
    {
        if (Schema::hasColumn('driver_debts', 'debt_type')) {
            Schema::table('driver_debts', fn (Blueprint $table) => $table->dropColumn('debt_type'));
        }
    }
};
