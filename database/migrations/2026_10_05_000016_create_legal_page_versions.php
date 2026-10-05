<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_page_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('page_id')->index();
            $table->string('title');
            $table->longText('content');
            $table->unsignedBigInteger('performed_by')->nullable(); // người đã thay đổi bản này
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_page_versions');
    }
};
