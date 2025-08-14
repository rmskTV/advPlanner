<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->timestamps();
            $table->softDeletes();

            // Связь с 1С
            $table->string('guid_1c', 36)->unique()->nullable()
                ->comment('GUID объекта в 1С');

            // Основные реквизиты
            $table->string('number', 50)->nullable()
                ->comment('Номер договора');

            $table->date('date')->nullable()
                ->comment('Дата договора');

            $table->string('name', 255)->nullable()
                ->comment('Наименование договора');

            $table->text('description')->nullable()
                ->comment('Описание договора');

            // Связи
            $table->foreignId('organization_id')->nullable()
                ->constrained('organizations')
                ->onDelete('set null')
                ->comment('Организация');

            $table->string('counterparty_guid_1c', 36)->nullable()
                ->comment('GUID контрагента в 1С');

            $table->string('currency_guid_1c', 36)->nullable()
                ->comment('GUID валюты в 1С');

            // Типы и категории
            $table->string('contract_type', 100)->nullable()
                ->comment('Тип договора (ВидДоговора)');

            $table->string('contract_category', 100)->nullable()
                ->comment('Категория договора');

            // Суммы и условия
            $table->decimal('amount', 15, 2)->nullable()
                ->comment('Сумма договора');

            $table->date('valid_from')->nullable()
                ->comment('Действует с');

            $table->date('valid_to')->nullable()
                ->comment('Действует по');

            // Условия оплаты
            $table->integer('payment_days')->nullable()
                ->comment('Количество дней для оплаты');

            $table->string('payment_terms', 255)->nullable()
                ->comment('Условия оплаты');

            // Агентские договоры
            $table->boolean('is_agent_contract')->default(false)
                ->comment('Учет агентского НДС');

            $table->string('agent_contract_type', 100)->nullable()
                ->comment('Вид агентского договора');

            // Расчеты в условных единицах
            $table->boolean('calculations_in_conditional_units')->default(false)
                ->comment('Расчеты в условных единицах');

            // Статусы
            $table->boolean('is_active')->default(true)
                ->comment('Активный договор');

            $table->boolean('deletion_mark')->default(false)
                ->comment('Пометка удаления');

            // Системные поля
            $table->timestamp('last_sync_at')->nullable()
                ->comment('Время последней синхронизации');

            // Индексы
            $table->index(['number', 'date']);
            $table->index('counterparty_guid_1c');
            $table->index('currency_guid_1c');
            $table->index('organization_id');
            $table->index('contract_type');
            $table->index('is_active');
            $table->index('deletion_mark');
            $table->index(['valid_from', 'valid_to']);
            $table->index('last_sync_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
