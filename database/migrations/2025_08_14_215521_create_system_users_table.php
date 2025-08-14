<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->timestamps();
            $table->softDeletes();

            // Связь с 1С
            $table->string('guid_1c', 36)->unique()->nullable()
                ->comment('GUID объекта в 1С');

            // Основные реквизиты
            $table->string('name', 255)->nullable()
                ->comment('Наименование пользователя');

            $table->string('login', 100)->nullable()
                ->comment('Логин пользователя');

            $table->text('description')->nullable()
                ->comment('Описание');

            // Связь с физическим лицом
            $table->string('individual_guid_1c', 36)->nullable()
                ->comment('GUID физического лица в 1С');

            // Системные поля
            $table->boolean('is_active')->default(true)
                ->comment('Активный пользователь');

            $table->boolean('deletion_mark')->default(false)
                ->comment('Пометка удаления');

            $table->timestamp('last_sync_at')->nullable()
                ->comment('Время последней синхронизации');

            // Индексы
            $table->index('name');
            $table->index('login');
            $table->index('individual_guid_1c');
            $table->index('is_active');
            $table->index('deletion_mark');
            $table->index('last_sync_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_users');
    }
};
