<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->integer('line_number')->comment('Номер строки');

            // Заказ клиента
            $table->foreignId('order_id')->nullable()->constrained('customer_orders')->nullOnDelete();
            $table->string('order_guid_1c', 36)->nullable();

            // Суммы
            $table->decimal('amount', 15, 2)->nullable()->comment('Сумма');
            $table->decimal('vat_amount', 15, 2)->nullable()->comment('Сумма НДС');
            $table->decimal('settlement_amount', 15, 2)->nullable()->comment('Сумма взаиморасчетов');

            // Статья ДДС
            $table->string('cash_flow_item_guid_1c', 36)->nullable()->comment('Статья движения денежных средств');
            $table->string('cash_flow_item_code')->nullable();
            $table->string('cash_flow_item_name')->nullable();

            // Договор
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->string('contract_guid_1c', 36)->nullable();

            // Валюта взаиморасчетов
            $table->foreignId('settlement_currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->string('settlement_currency_guid_1c', 36)->nullable();
            $table->decimal('exchange_rate', 12, 6)->nullable()->comment('Курс взаиморасчетов');
            $table->decimal('exchange_multiplier', 12, 6)->nullable()->comment('Кратность взаиморасчетов');

            // Счета учета
            $table->string('advance_account')->nullable()->comment('Счет учета авансов');
            $table->string('settlement_account')->nullable()->comment('Счет учета расчетов');

            // Дополнительные реквизиты
            $table->string('payment_type_extended')->nullable()->comment('Вид расчетов расширенный');
            $table->string('debt_repayment_method')->nullable()->comment('Способ погашения задолженности');

            $table->timestamps();

            // Уникальный индекс для предотвращения дубликатов
            $table->unique(['payment_id', 'line_number']);

            // Индексы для поиска
            $table->index('order_guid_1c');
            $table->index('contract_guid_1c');
            $table->index('cash_flow_item_guid_1c');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_items');
    }
};
