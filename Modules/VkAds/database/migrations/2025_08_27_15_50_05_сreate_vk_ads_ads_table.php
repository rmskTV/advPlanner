<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vk_ads_ads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->timestamps();
            $table->softDeletes();

            // VK данные
            $table->bigInteger('vk_ad_id')->unique();
            $table->foreignId('vk_ads_ad_group_id')->constrained();

            // Основная информация
            $table->string('name');
            $table->string('status');

            // Креативы
            $table->foreignId('primary_creative_id')
                ->constrained('vk_ads_creatives')
                ->comment('Основной креатив объявления');

            // Тексты объявления
            $table->string('headline', 100)->nullable();
            $table->text('description')->nullable();
            $table->string('call_to_action', 50)->nullable();
            $table->string('display_url', 255)->nullable();
            $table->string('final_url', 500)->nullable();

            // Специфичные настройки для instream
            $table->boolean('is_instream')->default(false);
            $table->string('instream_position')->nullable();
            $table->boolean('skippable')->default(true);
            $table->integer('skip_offset')->nullable(); // секунды до возможности пропустить

            // Статус модерации
            $table->string('moderation_status');
            $table->text('moderation_comment')->nullable();
            $table->timestamp('moderated_at')->nullable();

            // Метаданные
            $table->json('vk_data')->nullable();
            $table->timestamp('last_sync_at')->nullable();

            // Индексы
            $table->index(['vk_ads_ad_group_id', 'status']);
            $table->index('primary_creative_id');
            $table->index(['is_instream', 'instream_position']);
            $table->index('moderation_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vk_ads_ads');
    }
};
