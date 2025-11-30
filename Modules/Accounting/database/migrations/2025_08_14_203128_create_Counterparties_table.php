<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counterparties', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->timestamps();
            $table->softDeletes();

            // Связь с 1С
            $table->string('guid_1c', 36)->unique()->nullable()
                ->comment('GUID объекта в 1С');

            $table->string('responsible_guid_1c', 36)->nullable()
                ->comment('GUID ответственного сотрудника');

            // Основные реквизиты
            $table->string('name', 255)->nullable()
                ->comment('Наименование контрагента');

            $table->string('full_name', 500)->nullable()
                ->comment('Полное наименование');

            $table->text('description')->nullable()
                ->comment('Описание');

            // Группа
            $table->foreignId('group_id')->nullable()
                ->constrained('counterparty_groups')
                ->onDelete('set null')
                ->comment('Группа контрагента');

            $table->string('group_guid_1c', 36)->nullable()
                ->comment('GUID группы в 1С');

            // Тип контрагента
            $table->enum('entity_type', ['legal', 'individual'])
                ->default('legal')
                ->comment('Тип лица: юридическое/физическое');

            // Коды и регистрационные данные
            $table->string('inn', 12)->nullable()
                ->comment('ИНН');

            $table->string('kpp', 9)->nullable()
                ->comment('КПП');

            $table->string('ogrn', 15)->nullable()
                ->comment('ОГРН');

            $table->string('okpo', 14)->nullable()
                ->comment('ОКПО');

            // Страна регистрации
            $table->string('country_guid_1c', 36)->nullable()
                ->comment('GUID страны в 1С');

            $table->string('country_code', 3)->nullable()
                ->comment('Код страны');

            $table->string('country_name', 100)->nullable()
                ->comment('Название страны');

            // Для ИП и нерезидентов
            $table->string('registration_number', 50)->nullable()
                ->comment('Регистрационный номер нерезидента');

            // Контактная информация
            $table->string('phone', 100)->nullable()
                ->comment('Телефон');

            $table->string('email', 100)->nullable()
                ->comment('Email');

            $table->text('legal_address')->nullable()
                ->comment('Юридический адрес');

            $table->string('legal_address_zip', 10)->nullable()
                ->comment('Индекс юридического адреса');

            $table->text('actual_address')->nullable()
                ->comment('Фактический адрес');

            // Банковские реквизиты (основные)
            $table->string('main_bank_account', 20)->nullable()
                ->comment('Основной расчетный счет');

            $table->string('main_bank_bik', 9)->nullable()
                ->comment('БИК основного банка');

            $table->string('main_bank_name', 255)->nullable()
                ->comment('Наименование основного банка');

            // Дополнительные характеристики
            $table->boolean('is_separate_division')->default(false)
                ->comment('Обособленное подразделение');

            // Системные поля
            $table->boolean('is_our_company')->default(false)
                ->comment('Наша компания');

            $table->boolean('is_pseudoip')->default(false)
                ->comment('признак самозанятого');

            $table->boolean('deletion_mark')->default(false)
                ->comment('Пометка удаления');

            $table->timestamp('last_sync_at')->nullable()
                ->comment('Время последней синхронизации');

            // Индексы
            $table->index('name');
            $table->index('inn');
            $table->index('group_id');
            $table->index('group_guid_1c');
            $table->index('entity_type');
            $table->index('country_guid_1c');
            $table->index('country_code');
            $table->index('deletion_mark');
            $table->index('is_our_company');
            $table->index('is_separate_division');
            $table->index('last_sync_at');

            // Составные индексы
            $table->index(['entity_type', 'deletion_mark']);
            $table->index(['group_id', 'entity_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counterparties');
    }
};
