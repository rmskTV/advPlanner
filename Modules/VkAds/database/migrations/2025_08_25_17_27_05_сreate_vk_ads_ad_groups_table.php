<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vk_ads_ad_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->timestamps();
            $table->softDeletes();

            // VK данные
            $table->bigInteger('vk_ad_group_id')->unique();
            $table->foreignId('vk_ads_campaign_id')->constrained();
            $table->string('name');

            // СВЯЗЬ С ЗАКАЗОМ КЛИЕНТА (перенесено с кампании)
            $table->foreignId('customer_order_item_id')->nullable()
                ->constrained('customer_order_items')->onDelete('cascade')
                ->comment('Строка заказа клиента, к которой привязана группа объявлений');
            $table->foreignId('vk_ads_account_id')->nullable()->after('id')
                ->constrained('vk_ads_accounts')->onDelete('cascade')
                ->comment('VK Ads аккаунт');

            // Настройки группы
            $table->enum('status', ['active', 'paused', 'deleted']);
            $table->decimal('bid', 15, 2)->nullable();
            $table->json('targetings')->nullable();
            $table->json('placements')->nullable();

            // Синхронизация
            $table->timestamp('last_sync_at')->nullable();
            $table->json('vk_data')->nullable();

            // Индексы
            $table->index('customer_order_item_id');
            $table->index(['vk_ads_campaign_id', 'status']);
            $table->index('vk_ads_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vk_ads_ad_groups');
    }
};
