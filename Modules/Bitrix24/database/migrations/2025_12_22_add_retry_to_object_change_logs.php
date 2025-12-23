<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('object_change_logs', function (Blueprint $table) {
            // Retry механизм
            $table->unsignedTinyInteger('retry_count')->default(0)->after('status');
            $table->timestamp('next_retry_at')->nullable()->after('retry_count');
            $table->timestamp('locked_at')->nullable()->after('next_retry_at');

            // Индексы для быстрого поиска
            $table->index(['status', 'next_retry_at'], 'idx_status_retry');
            $table->index('locked_at', 'idx_locked');
        });

    }

    public function down(): void
    {
        Schema::table('object_change_logs', function (Blueprint $table) {
            $table->dropIndex('idx_status_retry');
            $table->dropIndex('idx_locked');
            $table->dropColumn(['retry_count', 'next_retry_at', 'locked_at']);
        });
    }
};
