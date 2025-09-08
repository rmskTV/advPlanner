<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vk_ads_ads', function (Blueprint $table) {
            $table->dropForeign(['primary_creative_id']);
        });

        Schema::table('vk_ads_ads', function (Blueprint $table) {
            // Убираем поля, не соответствующие VK Ads API Banner
            $table->dropColumn([
                'primary_creative_id', 'headline', 'description', 'call_to_action',
                'display_url', 'final_url', 'is_instream', 'instream_position',
                'skippable', 'skip_offset', 'moderation_comment', 'moderated_at'
            ]);
        });

        Schema::table('vk_ads_ads', function (Blueprint $table) {
            // Добавляем поля согласно VK Ads API Banner
            $table->json('content')->nullable()->after('name')
                ->comment('Содержимое баннера (BannerContent)');

            $table->string('delivery')->nullable()->after('status')
                ->comment('Статус трансляции баннера');

            $table->json('issues')->nullable()->after('delivery')
                ->comment('Причины неотображения баннера');

            $table->json('moderation_reasons')->nullable()->after('moderation_status')
                ->comment('Причины отклонения при модерации');

            $table->json('textblocks')->nullable()->after('moderation_reasons')
                ->comment('Блоки текстового содержимого');

            $table->json('urls')->nullable()->after('textblocks')
                ->comment('Объекты ссылок');

            $table->string('ord_marker', 32)->nullable()->after('urls')
                ->comment('Токен маркировки креатива');

            $table->timestamp('created_at_vk')->nullable()->after('ord_marker')
                ->comment('Время создания в VK');

            $table->timestamp('updated_at_vk')->nullable()->after('created_at_vk')
                ->comment('Время последнего обновления в VK');

            // Обновляем индексы
            $table->index('delivery');
            $table->index('ord_marker');
        });
    }

    public function down(): void
    {
        Schema::table('vk_ads_ads', function (Blueprint $table) {
            // Удаляем новые поля
            $table->dropColumn([
                'content', 'delivery', 'issues', 'moderation_reasons',
                'textblocks', 'urls', 'ord_marker', 'created_at_vk', 'updated_at_vk'
            ]);
        });

        Schema::table('vk_ads_ads', function (Blueprint $table) {
            // Возвращаем старые поля
            $table->foreignId('primary_creative_id')
                ->constrained('vk_ads_creatives')
                ->comment('Основной креатив объявления');
            $table->string('headline', 100)->nullable();
            $table->text('description')->nullable();
            $table->string('call_to_action', 50)->nullable();
            $table->string('display_url', 255)->nullable();
            $table->string('final_url', 500)->nullable();
            $table->boolean('is_instream')->default(false);
            $table->string('instream_position')->nullable();
            $table->boolean('skippable')->default(true);
            $table->integer('skip_offset')->nullable();
            $table->text('moderation_comment')->nullable();
            $table->timestamp('moderated_at')->nullable();
        });
    }
};
