<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vk_ads_ad_creatives', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->timestamps();
            $table->softDeletes();

            // Связи
            $table->foreignId('vk_ads_ad_id')->constrained();
            $table->foreignId('vk_ads_creative_id')->constrained();

            // Роль креатива в объявлении
            $table->string('role')
                ->comment('Роль креатива: основной или вариант для определенного соотношения сторон');

            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0)
                ->comment('Приоритет показа (для A/B тестирования)');

            // Индексы
            $table->index(['vk_ads_ad_id', 'role']);
            $table->index(['vk_ads_ad_id', 'is_active']);
            $table->unique(['vk_ads_ad_id', 'vk_ads_creative_id', 'role'], 'unique_ad_creative_role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vk_ads_ad_creatives');
    }
};
