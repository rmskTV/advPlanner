<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units_of_measure', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->timestamps();
            $table->softDeletes();

            // Связь с 1С
            $table->string('guid_1c', 36)->unique()->nullable()
                ->comment('GUID объекта в 1С');

            // Данные классификатора
            $table->string('code', 10)->nullable()
                ->comment('Код единицы измерения');

            $table->string('name', 100)->nullable()
                ->comment('Наименование единицы измерения');

            $table->string('full_name', 255)->nullable()
                ->comment('Полное наименование');

            // Дополнительные свойства
            $table->string('symbol', 20)->nullable()
                ->comment('Условное обозначение');

            // Системные поля
            $table->boolean('deletion_mark')->default(false)
                ->comment('Пометка удаления');

            $table->timestamp('last_sync_at')->nullable()
                ->comment('Время последней синхронизации');

            // Индексы
            $table->index('code');
            $table->index('name');
            $table->index('deletion_mark');
            $table->index('last_sync_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units_of_measure');
    }
};
