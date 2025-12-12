<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();

            // Идентификаторы
            $table->string('guid_1c')->unique()->nullable()->comment('GUID из 1С');

            // Владелец счета (контрагент)
            $table->foreignId('counterparty_id')->nullable()
                ->constrained('counterparties')
                ->nullOnDelete()
                ->comment('ID контрагента-владельца');
            $table->string('counterparty_guid_1c')->nullable()
                ->comment('GUID контрагента из 1С');
            $table->foreignId('organization_id')->nullable()
                ->constrained('organizations')
                ->nullOnDelete()
                ->comment('ID организации-владельца');
            // Валюта
            $table->foreignId('currency_id')->nullable()
                ->constrained('currencies')
                ->nullOnDelete()
                ->comment('ID валюты');
            $table->string('currency_guid_1c')->nullable()
                ->comment('GUID валюты из 1С');
            $table->string('currency_code', 10)->nullable()
                ->comment('Код валюты (643 для RUB)');

            // Информация о счете
            $table->string('account_number', 20)->nullable()->comment('Номер счета (20 цифр)');
            $table->string('name')->nullable()->comment('Наименование счета');
            $table->string('account_type', 50)->nullable()->comment('Вид счета (Расчетный и т.д.)');

            // Информация о банке
            $table->string('bank_guid_1c')->nullable()->comment('GUID банка из 1С');
            $table->string('bank_name')->nullable()->comment('Наименование банка');
            $table->string('bank_bik', 9)->nullable()->comment('БИК банка');
            $table->string('bank_correspondent_account', 20)->nullable()->comment('Корреспондентский счет');
            $table->string('bank_swift', 20)->nullable()->comment('SWIFT код банка');

            // Настройки вывода
            $table->boolean('print_month_in_words')->default(false)
                ->comment('Выводить месяц прописью');
            $table->boolean('print_amount_without_kopecks')->default(false)
                ->comment('Выводить сумму без копеек');

            // Системные поля
            $table->boolean('deletion_mark')->default(false)->comment('Пометка удаления');
            $table->boolean('is_active')->default(true)->comment('Активен');
            $table->timestamp('last_sync_at')->nullable()->comment('Последняя синхронизация');

            $table->timestamps();

            // Индексы
            $table->index('guid_1c');
            $table->index('counterparty_id');
            $table->index('counterparty_guid_1c');
            $table->index('account_number');
            $table->index('bank_bik');
            $table->index('bank_guid_1c');
            $table->index('currency_id');
            $table->index('is_active');
            $table->index(['counterparty_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
