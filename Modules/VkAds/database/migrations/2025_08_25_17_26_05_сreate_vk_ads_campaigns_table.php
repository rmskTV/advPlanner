<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vk_ads_campaigns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->timestamps();
            $table->softDeletes();

            // VK данные
            $table->bigInteger('vk_campaign_id')->unique();
            $table->foreignId('vk_ads_account_id')->constrained();
            $table->string('name');
            $table->text('description')->nullable();

            // Статус и настройки
            $table->enum('status', ['active', 'paused', 'deleted', 'archived']);
            $table->enum('campaign_type', ['promoted_posts', 'website_conversions', 'mobile_app_promotion']);

            // Бюджет
            $table->decimal('daily_budget', 15, 2)->nullable();
            $table->decimal('total_budget', 15, 2)->nullable();
            $table->enum('budget_type', ['daily', 'total']);

            // Даты
            $table->date('start_date');
            $table->date('end_date')->nullable();

            // Синхронизация
            $table->timestamp('last_sync_at')->nullable();
            $table->json('vk_data')->nullable();

            // Индексы
            $table->index(['vk_ads_account_id', 'status']);
            $table->index('start_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vk_ads_campaigns');
    }
};
