<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_payment_statuses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->timestamps();
            $table->softDeletes();
            // Связь с заказом (основной ключ)
            $table->string('order_guid_1c', 36)
                ->comment('GUID заказа в 1С');

            $table->foreignId('customer_order_id')->nullable()
                ->constrained('customer_orders')
                ->onDelete('cascade')
                ->comment('Заказ клиента');

            // Состояние оплаты
            $table->string('payment_status', 100)
                ->comment('Состояние оплаты');

            // Дополнительная информация о заказе (для случаев когда заказ не найден)
            $table->string('order_number', 50)->nullable()
                ->comment('Номер заказа');

            $table->datetime('order_date')->nullable()
                ->comment('Дата заказа');

            $table->string('organization_guid_1c', 36)->nullable()
                ->comment('GUID организации заказа');

            // Системные поля
            $table->timestamp('last_sync_at')->nullable()
                ->comment('Время последней синхронизации');

            // Индексы
            $table->unique('order_guid_1c'); // Один статус на заказ
            $table->index('customer_order_id');
            $table->index('payment_status');
            $table->index(['order_number', 'order_date']);
            $table->index('last_sync_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payment_statuses');
    }
};
