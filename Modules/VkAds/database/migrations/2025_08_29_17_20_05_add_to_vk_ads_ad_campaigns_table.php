<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vk_ads_campaigns', function (Blueprint $table) {
            // Режим автоматических ставок
            $table->string('autobidding_mode')->nullable()->after('campaign_type')
                ->comment('Режим автоматических ставок');

            // Бюджетные ограничения (если еще не добавлены)
            if (! Schema::hasColumn('vk_ads_campaigns', 'budget_limit')) {
                $table->decimal('budget_limit', 15, 2)->nullable()->after('autobidding_mode')
                    ->comment('Общий лимит бюджета (в рублях)');
            }

            if (! Schema::hasColumn('vk_ads_campaigns', 'budget_limit_day')) {
                $table->decimal('budget_limit_day', 15, 2)->nullable()->after('budget_limit')
                    ->comment('Дневной лимит бюджета (в рублях)');
            }

            // Максимальная цена
            $table->decimal('max_price', 15, 2)->nullable()->after('budget_limit_day')
                ->comment('Максимальная цена (в рублях)');

            // Цель кампании (если еще не добавлена)
            if (! Schema::hasColumn('vk_ads_campaigns', 'objective')) {
                $table->string('objective')->nullable()->after('max_price')
                    ->comment('Цель кампании (leadads, website_conversions, etc.)');
            }

            // Ценовая цель
            $table->json('priced_goal')->nullable()->after('objective')
                ->comment('Ценовая цель кампании');

            // Индексы для часто используемых полей
            $table->index('autobidding_mode');
            $table->index('objective');
        });
    }

    public function down(): void
    {
        Schema::table('vk_ads_campaigns', function (Blueprint $table) {
            $table->dropColumn([
                'autobidding_mode',
                'budget_limit',
                'budget_limit_day',
                'max_price',
                'objective',
                'priced_goal',
            ]);
        });
    }
};
