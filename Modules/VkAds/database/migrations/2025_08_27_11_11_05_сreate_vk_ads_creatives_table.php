<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vk_ads_creatives', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->timestamps();
            $table->softDeletes();

            // VK данные
            $table->bigInteger('vk_creative_id')->unique();
            $table->foreignId('vk_ads_account_id')->constrained();

            // Основная информация
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('creative_type', ['image', 'video', 'html5', 'carousel']);
            $table->enum('format', ['banner', 'instream', 'native', 'interstitial']);

            $table->foreignId('video_file_id')->nullable()
                ->constrained('video_files')->onDelete('set null')
                ->comment('Связь с видеофайлом');

            $table->foreignId('image_file_id')->nullable()
                ->constrained('image_files')->onDelete('set null')
                ->comment('Связь с изображением');

            // Дополнительные варианты (для разных соотношений сторон)
            $table->json('media_variants')->nullable()
                ->comment('Варианты медиафайлов для разных форматов');

            // Технические характеристики основного файла
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->integer('duration')->nullable(); // для видео в секундах
            $table->integer('file_size')->nullable();

            // Статус модерации
            $table->enum('moderation_status', ['pending', 'approved', 'rejected', 'reviewing']);
            $table->text('moderation_comment')->nullable();
            $table->timestamp('moderated_at')->nullable();

            // Метаданные VK
            $table->json('vk_data')->nullable();
            $table->timestamp('last_sync_at')->nullable();

            // Индексы
            $table->index(['vk_ads_account_id', 'creative_type']);
            $table->index('moderation_status');
            $table->index('video_file_id');
            $table->index('image_file_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vk_ads_creatives');
    }
};
