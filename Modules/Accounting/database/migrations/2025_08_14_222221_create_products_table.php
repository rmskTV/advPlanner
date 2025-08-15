<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->timestamps();
            $table->softDeletes();

            // Связь с 1С
            $table->string('guid_1c', 36)->unique()->nullable()
                ->comment('GUID объекта в 1С');

            // Основные реквизиты
            $table->string('name', 255)->nullable()
                ->comment('Наименование');

            $table->string('full_name', 500)->nullable()
                ->comment('Полное наименование');

            $table->string('code', 50)->nullable()
                ->comment('Код в программе');

            $table->text('description')->nullable()
                ->comment('Описание');

            // Группа
            $table->foreignId('group_id')->nullable()
                ->constrained('product_groups')
                ->onDelete('set null')
                ->comment('Группа номенклатуры');

            $table->string('group_guid_1c', 36)->nullable()
                ->comment('GUID группы в 1С');

            // Тип номенклатуры
            $table->enum('product_type', ['product', 'service', 'set'])
                ->default('product')
                ->comment('Тип номенклатуры');

            // Единица измерения
            $table->foreignId('unit_of_measure_id')->nullable()
                // ->constrained('units_of_measure')
                // ->onDelete('set null')
                ->comment('Единица измерения');

            $table->string('unit_guid_1c', 36)->nullable()
                ->comment('GUID единицы измерения в 1С');

            // НДС
            $table->string('vat_rate', 20)->nullable()
                ->comment('Ставка НДС');

            // Коды и классификаторы
            $table->string('tru_code', 50)->nullable()
                ->comment('Код ТРУ');

            // Группа аналитического учета
            $table->string('analytics_group_guid_1c', 36)->nullable()
                ->comment('GUID группы аналитического учета в 1С');

            $table->string('analytics_group_code', 50)->nullable()
                ->comment('Код группы аналитического учета');

            $table->string('analytics_group_name', 255)->nullable()
                ->comment('Наименование группы аналитического учета');

            // Вид номенклатуры
            $table->string('product_kind_guid_1c', 36)->nullable()
                ->comment('GUID вида номенклатуры в 1С');

            $table->string('product_kind_name', 255)->nullable()
                ->comment('Наименование вида номенклатуры');

            // Алкогольная продукция
            $table->boolean('is_alcoholic')->default(false)
                ->comment('Алкогольная продукция');

            $table->string('alcohol_type', 100)->nullable()
                ->comment('Вид алкогольной продукции');

            $table->boolean('is_imported_alcohol')->default(false)
                ->comment('Импортная алкогольная продукция');

            $table->string('alcohol_volume')->nullable()
                ->comment('Объем ДАЛ');

            $table->string('alcohol_producer', 255)->nullable()
                ->comment('Производитель/импортер алкоголя');

            // Прослеживаемость
            $table->boolean('is_traceable')->default(false)
                ->comment('Прослеживаемый товар');

            // Системные поля
            $table->boolean('deletion_mark')->default(false)
                ->comment('Пометка удаления');

            $table->timestamp('last_sync_at')->nullable()
                ->comment('Время последней синхронизации');

            // Индексы
            $table->index('name');
            $table->index('code');
            $table->index('group_id');
            $table->index('group_guid_1c');
            $table->index('unit_of_measure_id');
            $table->index('unit_guid_1c');
            $table->index('product_type');
            $table->index('vat_rate');
            $table->index('is_alcoholic');
            $table->index('is_traceable');
            $table->index('deletion_mark');
            $table->index('last_sync_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
