<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->timestamps();
            $table->softDeletes();

            // Связь с реализацией
            $table->foreignId('sale_id')
                ->constrained('sales')
                ->onDelete('cascade')
                ->comment('Реализация товаров/услуг');

            // Номер строки и идентификатор
            $table->integer('line_number')->default(0)
                ->comment('Номер строки');

            $table->string('line_identifier', 100)->nullable()
                ->comment('Идентификатор строки');

            // Номенклатура
            $table->string('product_guid_1c', 36)->nullable()
                ->comment('GUID номенклатуры в 1С');

            $table->string('product_name', 255)->nullable()
                ->comment('Наименование номенклатуры');

            // Количество и единица измерения
            $table->decimal('quantity', 15, 3)->nullable()
                ->comment('Количество');

            $table->string('unit_guid_1c', 36)->nullable()
                ->comment('GUID единицы измерения в 1С');

            $table->string('unit_name', 100)->nullable()
                ->comment('Наименование единицы измерения');

            // Цены и суммы
            $table->decimal('price', 15, 2)->nullable()
                ->comment('Цена');

            $table->decimal('amount', 15, 2)->nullable()
                ->comment('Сумма');

            $table->decimal('vat_amount', 15, 2)->nullable()
                ->comment('Сумма НДС');

            // Содержание
            $table->text('content')->nullable()
                ->comment('Содержание услуги');

            // Тип услуги
            $table->string('service_type', 100)->nullable()
                ->comment('Тип услуги');

            // Счета учета
            $table->string('income_account', 20)->nullable()
                ->comment('Счет доходов');

            $table->string('expense_account', 20)->nullable()
                ->comment('Счет расходов');

            $table->string('vat_account', 20)->nullable()
                ->comment('Счет учета НДС по реализации');

            // Дополнительные характеристики
            $table->json('characteristics')->nullable()
                ->comment('Характеристики номенклатуры');

            // Индексы
            $table->index('sale_id');
            $table->index('product_guid_1c');
            $table->index('line_number');
            $table->index('line_identifier');
            $table->index(['sale_id', 'line_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
