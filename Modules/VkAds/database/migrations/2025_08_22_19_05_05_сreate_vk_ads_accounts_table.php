<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vk_ads_accounts', function (Blueprint $table) {
            // Базовые поля CatalogObject
            $table->id();
            $table->uuid('uuid');
            $table->timestamps();
            $table->softDeletes();

            // VK Ads специфичные поля
            $table->bigInteger('vk_account_id')->unique();
            $table->bigInteger('vk_user_id')->nullable()->after('vk_account_id')
                ->comment('ID пользователя в VK (для agency_client_credentials)');
            $table->string('vk_username')->nullable()->after('vk_user_id')
                ->comment('Username пользователя в VK');
            $table->string('account_name');
            $table->enum('account_type', ['general', 'agency', 'client']);
            $table->enum('account_status', ['active', 'blocked', 'deleted']);
            $table->bigInteger('agency_account_id')->nullable(); // для клиентских аккаунтов

            // Финансовые данные
            $table->decimal('balance', 15, 2)->default(0);
            $table->string('currency', 3)->default('RUB');

            // Доступы и права
            $table->json('access_roles')->nullable();
            $table->boolean('can_view_budget')->default(false);

            // Синхронизация
            $table->timestamp('last_sync_at')->nullable();
            $table->boolean('sync_enabled')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vk_ads_accounts');
    }
};
