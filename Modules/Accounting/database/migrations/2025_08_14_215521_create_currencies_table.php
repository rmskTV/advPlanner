<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->timestamps();
            $table->softDeletes();

            // Связь с 1С
            $table->string('guid_1c', 36)->unique()->nullable()
                ->comment('GUID объекта в 1С');

            // Данные классификатора
            $table->string('code', 10)->nullable()
                ->comment('Код валюты');

            $table->string('name', 100)->nullable()
                ->comment('Наименование валюты');

            $table->string('full_name', 255)->nullable()
                ->comment('Полное наименование валюты');

            // Параметры прописи
            $table->text('spelling_parameters')->nullable()
                ->comment('Параметры прописи');

            // Дополнительные свойства
            $table->string('symbol', 10)->nullable()
                ->comment('Символ валюты');

            $table->integer('decimal_places')->default(2)
                ->comment('Количество знаков после запятой');

            // Системные поля
            $table->boolean('is_main_currency')->default(false)
                ->comment('Основная валюта');

            $table->boolean('deletion_mark')->default(false)
                ->comment('Пометка удаления');

            $table->timestamp('last_sync_at')->nullable()
                ->comment('Время последней синхронизации');

            // Индексы
            $table->index('code');
            $table->index('name');
            $table->index('is_main_currency');
            $table->index('deletion_mark');
            $table->index('last_sync_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
