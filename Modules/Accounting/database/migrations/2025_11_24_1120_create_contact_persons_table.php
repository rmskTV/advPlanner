<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_persons', function (Blueprint $table) {
            $table->id();

            // Идентификаторы
            $table->string('guid_1c')->unique()->nullable()->comment('GUID из 1С');
            $table->foreignId('counterparty_id')->nullable()
                ->constrained('counterparties')
                ->nullOnDelete()
                ->comment('ID контрагента в системе');
            $table->string('counterparty_guid_1c')->nullable()
                ->comment('GUID контрагента из 1С');

            // ФИО
            $table->string('last_name')->nullable()->comment('Фамилия');
            $table->string('first_name')->nullable()->comment('Имя');
            $table->string('middle_name')->nullable()->comment('Отчество');
            $table->string('full_name')->nullable()->comment('Полное ФИО');

            // Контактная информация
            $table->string('phone')->nullable()->comment('Телефон');
            $table->string('email')->nullable()->comment('Email');

            // Дополнительная информация
            $table->string('position')->nullable()->comment('Должность');
            $table->text('description')->nullable()->comment('Комментарий');

            // Системные поля
            $table->boolean('deletion_mark')->default(false)->comment('Пометка удаления');
            $table->boolean('is_active')->default(true)->comment('Активен');
            $table->timestamp('last_sync_at')->nullable()->comment('Последняя синхронизация');

            $table->timestamps();

            // Индексы
            $table->index('guid_1c');
            $table->index('counterparty_id');
            $table->index('counterparty_guid_1c');
            $table->index('last_name');
            $table->index(['last_name', 'first_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_persons');
    }
};
