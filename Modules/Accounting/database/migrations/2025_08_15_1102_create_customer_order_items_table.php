<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_order_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->timestamps();
            $table->softDeletes();

            // Связь с заказом
            $table->foreignId('customer_order_id')
                ->constrained('customer_orders')
                ->onDelete('cascade')
                ->comment('Заказ клиента');

            // Номер строки
            $table->integer('line_number')->default(0)
                ->comment('Номер строки');

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

            $table->decimal('vat_rate_value', 5, 2)->nullable()
                ->comment('Процент НДС');

            $table->decimal('vat_amount', 15, 2)->nullable()
                ->comment('Сумма НДС');

            // Дополнительные характеристики
            $table->text('characteristics')->nullable()
                ->comment('Характеристики номенклатуры (JSON)');

            $table->text('content')->nullable()
                ->comment('Содержание услуги');

            // Индексы
            $table->index('customer_order_id');
            $table->index('product_guid_1c');
            $table->index('line_number');
            $table->index(['customer_order_id', 'line_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_order_items');
    }
};
