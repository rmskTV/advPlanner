<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vk_ads_accounts', function (Blueprint $table) {
            // Если поле organization_id уже есть, удаляем его
            if (Schema::hasColumn('vk_ads_accounts', 'organization_id')) {
                $table->dropForeign(['organization_id']);
                $table->dropColumn('organization_id');
            }

            // Добавляем связь с контрагентом
            $table->foreignId('counterparty_id')->nullable()
                ->after('account_type')
                ->constrained('counterparties') // Связь с контрагентами
                ->onDelete('set null')
                ->comment('Контрагент из модуля Accounting');

            // Индексы для производительности
            $table->index('counterparty_id');
        });
    }

    public function down(): void
    {
        Schema::table('vk_ads_accounts', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn(['organization_id']);
        });
    }
};
