<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vk_ads_statistics', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->timestamps();
            $table->softDeletes();

            // Привязка к группе объявлений (изменено с кампании)
            $table->foreignId('vk_ads_ad_group_id')->constrained();

            // СВЯЗЬ С РЕАЛИЗАЦИЕЙ (для передачи в акты)
            $table->foreignId('sale_item_id')->nullable()
                ->constrained('sale_items')->onDelete('set null')
                ->comment('Строка реализации для включения в акт');

            // Период статистики
            $table->date('stats_date');
            $table->string('period_type', 10)->default('day');

            // Метрики
            $table->bigInteger('impressions')->default(0);
            $table->bigInteger('clicks')->default(0);
            $table->decimal('spend', 15, 2)->default(0);
            $table->decimal('ctr', 5, 4)->default(0);
            $table->decimal('cpc', 15, 2)->default(0);
            $table->decimal('cpm', 15, 2)->default(0);

            // Индексы
            $table->index('sale_item_id');
            $table->index(['vk_ads_ad_group_id', 'stats_date']);
            $table->index(['stats_date', 'period_type']);

            // Уникальность по группе и дате
            $table->unique(['vk_ads_ad_group_id', 'stats_date', 'period_type'], 'vk_stats_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vk_ads_statistics');
    }
};
