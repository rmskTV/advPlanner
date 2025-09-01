<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vk_ads_ad_groups', function (Blueprint $table) {
            // Возрастные ограничения
            $table->string('age_restrictions')->nullable()->after('targetings')
                ->comment('Возрастные ограничения');

            // Режим автоматических ставок
            $table->string('autobidding_mode')->nullable()->after('age_restrictions')
                ->comment('Режим автоматических ставок');

            // Бюджетные ограничения
            $table->decimal('budget_limit', 15, 2)->nullable()->after('autobidding_mode')
                ->comment('Общий лимит бюджета (в рублях)');

            $table->decimal('budget_limit_day', 15, 2)->nullable()->after('budget_limit')
                ->comment('Дневной лимит бюджета (в рублях)');

            // Максимальная цена
            $table->decimal('max_price', 15, 2)->nullable()->after('budget_limit_day')
                ->comment('Максимальная цена (в рублях)');

            // Лимиты уникальных показов
            $table->integer('uniq_shows_limit')->nullable()->after('max_price')
                ->comment('Лимит уникальных показов (-1 = без лимита)');

            $table->string('uniq_shows_period')->nullable()->after('uniq_shows_limit')
                ->comment('Период лимита уникальных показов (day, week, month)');

            // Индексы для часто используемых полей
            $table->index('age_restrictions');
            $table->index('autobidding_mode');
            $table->index('uniq_shows_period');
        });
    }

    public function down(): void
    {
        Schema::table('vk_ads_ad_groups', function (Blueprint $table) {
            $table->dropColumn([
                'age_restrictions',
                'autobidding_mode',
                'budget_limit',
                'budget_limit_day',
                'max_price',
                'uniq_shows_limit',
                'uniq_shows_period'
            ]);
        });
    }
};
