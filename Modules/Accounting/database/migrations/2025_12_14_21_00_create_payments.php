<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('guid_1c', 36)->nullable()->unique()->comment('GUID из 1С');
            $table->string('number')->nullable()->comment('Номер документа');
            $table->timestamp('date')->nullable()->comment('Дата документа');

            // Организация
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('organization_guid_1c', 36)->nullable()->index();

            // Контрагент
            $table->foreignId('counterparty_id')->nullable()->constrained('counterparties')->nullOnDelete();
            $table->string('counterparty_guid_1c', 36)->nullable()->index();

            // Тип платежа
            $table->enum('payment_type', ['incoming', 'outgoing'])->default('incoming')->index();

            // Сумма и валюта
            $table->decimal('amount', 15, 2)->nullable()->comment('Сумма платежа');
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->string('currency_guid_1c', 36)->nullable();

            // Даты и реквизиты
            $table->date('statement_date')->nullable()->comment('Дата выписки')->index();
            $table->text('payment_purpose')->nullable()->comment('Назначение платежа');
            $table->date('incoming_document_date')->nullable()->comment('Дата входящего документа');
            $table->string('incoming_document_number')->nullable()->comment('Номер входящего документа');

            // Банковские счета
            $table->foreignId('organization_bank_account_id')->nullable()
                ->constrained('bank_accounts')->nullOnDelete();
            $table->string('organization_bank_account_guid_1c', 36)->nullable();

            $table->foreignId('counterparty_bank_account_id')->nullable()
                ->constrained('bank_accounts')->nullOnDelete();
            $table->string('counterparty_bank_account_guid_1c', 36)->nullable();

            // Ответственный
            $table->string('responsible_guid_1c', 36)->nullable();
            $table->string('responsible_name')->nullable();

            // Системные поля
            $table->boolean('deletion_mark')->default(false)->index();
            $table->timestamp('last_sync_at')->nullable()->comment('Последняя синхронизация');

            $table->timestamps();
            $table->softDeletes();

            // Индексы
            $table->index(['organization_id', 'date']);
            $table->index(['counterparty_id', 'date']);
            $table->index(['number', 'date']);
            $table->index('last_sync_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
