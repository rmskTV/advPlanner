<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vk_ads_accounts', function (Blueprint $table) {
            // Связь с организацией
            $table->foreignId('organization_id')->nullable()
                ->after('account_type')
                ->constrained('organizations')
                ->onDelete('set null')
                ->comment('Организация из модуля Accounting');

            // Связь с договором
            $table->foreignId('contract_id')->nullable()
                ->after('organization_id')
                ->constrained('contracts')
                ->onDelete('set null')
                ->comment('Договор из модуля Accounting');

            // Индексы для производительности
            $table->index('organization_id');
            $table->index('contract_id');
        });
    }

    public function down(): void
    {
        Schema::table('vk_ads_accounts', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['contract_id']);
            $table->dropColumn(['organization_id', 'contract_id']);
        });
    }
};
