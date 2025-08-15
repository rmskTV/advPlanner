<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->timestamps();
            $table->softDeletes();

            // Связь с 1С
            $table->string('guid_1c', 36)->unique()->nullable()
                ->comment('GUID документа в 1С');

            // Основные реквизиты документа
            $table->string('number', 50)->nullable()
                ->comment('Номер реализации');

            $table->datetime('date')->nullable()
                ->comment('Дата реализации');

            // Вид операции
            $table->string('operation_type', 100)->nullable()
                ->comment('Вид операции');

            // Организация
            $table->foreignId('organization_id')->nullable()
                ->constrained('organizations')
                ->onDelete('set null')
                ->comment('Организация');

            $table->string('organization_guid_1c', 36)->nullable()
                ->comment('GUID организации в 1С');

            // Контрагент
            $table->string('counterparty_guid_1c', 36)->nullable()
                ->comment('GUID контрагента в 1С');

            // Валюта
            $table->string('currency_guid_1c', 36)->nullable()
                ->comment('GUID валюты в 1С');

            // Суммы
            $table->decimal('amount', 15, 2)->nullable()
                ->comment('Сумма реализации');

            $table->boolean('amount_includes_vat')->default(true)
                ->comment('Сумма включает НДС');

            // Данные взаиморасчетов
            $table->string('contract_guid_1c', 36)->nullable()
                ->comment('GUID договора в 1С');

            $table->string('settlement_currency_guid_1c', 36)->nullable()
                ->comment('GUID валюты взаиморасчетов в 1С');

            $table->decimal('exchange_rate', 15, 6)->nullable()
                ->comment('Курс взаиморасчетов');

            $table->decimal('exchange_multiplier', 15, 6)->nullable()
                ->comment('Кратность взаиморасчетов');

            $table->boolean('calculations_in_conditional_units')->default(false)
                ->comment('Расчеты в условных единицах');

            // Связанный заказ
            $table->string('order_guid_1c', 36)->nullable()
                ->comment('GUID заказа в 1С');

            // Доставка
            $table->text('delivery_address')->nullable()
                ->comment('Адрес доставки');

            // Налогообложение
            $table->string('taxation_type', 100)->nullable()
                ->comment('Тип налогообложения');

            // Электронный документ
            $table->string('electronic_document_type', 100)->nullable()
                ->comment('Вид электронного документа');

            // Способ погашения задолженности
            $table->string('debt_settlement_method', 100)->nullable()
                ->comment('Способ погашения задолженности');

            // Руководитель и бухгалтер
            $table->string('director_guid_1c', 36)->nullable()
                ->comment('GUID руководителя в 1С');

            $table->string('accountant_guid_1c', 36)->nullable()
                ->comment('GUID главного бухгалтера в 1С');

            // Банковский счет организации
            $table->string('organization_bank_account_guid_1c', 36)->nullable()
                ->comment('GUID банковского счета организации в 1С');

            // Ответственный
            $table->string('responsible_guid_1c', 36)->nullable()
                ->comment('GUID ответственного в 1С');

            // Системные поля
            $table->boolean('deletion_mark')->default(false)
                ->comment('Пометка удаления');

            $table->timestamp('last_sync_at')->nullable()
                ->comment('Время последней синхронизации');

            // Индексы
            $table->index(['number', 'date']);
            $table->index('organization_id');
            $table->index('organization_guid_1c');
            $table->index('counterparty_guid_1c');
            $table->index('contract_guid_1c');
            $table->index('order_guid_1c');
            $table->index('currency_guid_1c');
            $table->index('operation_type');
            $table->index('date');
            $table->index('deletion_mark');
            $table->index('last_sync_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
