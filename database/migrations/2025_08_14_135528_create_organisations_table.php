<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->timestamps();
            $table->softDeletes();

            // Связь с 1С
            $table->string('guid_1c', 36)->unique()->nullable()
                ->comment('GUID объекта в 1С');

            // Основные реквизиты
            $table->string('name', 255)
                ->comment('Наименование организации');

            $table->string('full_name', 500)->nullable()
                ->comment('Полное наименование');

            $table->string('prefix', 10)->nullable()
                ->comment('Префикс организации');

            // Коды и регистрационные данные
            $table->string('inn', 12)->nullable()
                ->comment('ИНН');

            $table->string('kpp', 9)->nullable()
                ->comment('КПП');

            $table->string('okpo', 14)->nullable()
                ->comment('ОКПО');

            $table->string('okato', 11)->nullable()
                ->comment('ОКАТО');

            $table->string('oktmo', 11)->nullable()
                ->comment('ОКТМО');

            $table->string('ogrn', 15)->nullable()
                ->comment('ОГРН');

            $table->string('okved', 10)->nullable()
                ->comment('ОКВЭД');

            $table->string('okopf', 10)->nullable()
                ->comment('ОКОПФ');

            $table->string('okfs', 10)->nullable()
                ->comment('ОКФС');

            // Юридический адрес - увеличиваем размер
            $table->text('legal_address')->nullable()
                ->comment('Юридический адрес');

            $table->string('legal_address_zip', 10)->nullable()
                ->comment('Индекс юридического адреса');

            // Контактная информация - увеличиваем размер полей
            $table->string('phone', 100)->nullable()
                ->comment('Телефон');

            $table->string('email', 100)->nullable()
                ->comment('Email');

            $table->string('website', 255)->nullable()
                ->comment('Веб-сайт');

            // Банковские реквизиты (основные)
            $table->string('main_bank_account', 20)->nullable()
                ->comment('Основной расчетный счет');

            $table->string('main_bank_bik', 9)->nullable()
                ->comment('БИК основного банка');

            $table->string('main_bank_name', 255)->nullable()
                ->comment('Наименование основного банка');

            // Руководитель
            $table->string('director_name', 255)->nullable()
                ->comment('ФИО руководителя');

            $table->string('director_position', 100)->nullable()
                ->comment('Должность руководителя');

            // Главный бухгалтер
            $table->string('accountant_name', 255)->nullable()
                ->comment('ФИО главного бухгалтера');

            // Системные поля
            $table->boolean('is_our_organization')->default(false)
                ->comment('Наша организация');

            $table->boolean('deletion_mark')->default(false)
                ->comment('Пометка удаления');

            $table->timestamp('last_sync_at')->nullable()
                ->comment('Время последней синхронизации');

            // Индексы
            $table->index('inn');
            $table->index('name');
            $table->index('deletion_mark');
            $table->index('is_our_organization');
            $table->index('last_sync_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
