<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_incoming_confirmations', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            // Связь с коннектором
            $table->foreignId('connector_id')
                ->constrained('exchange_ftp_connectors')
                ->onDelete('cascade');

            // Связь с логом обмена
            $table->foreignId('exchange_log_id')
                ->constrained('exchange_logs')
                ->onDelete('cascade');

            // Номер входящего сообщения
            $table->unsignedInteger('message_no')
                ->comment('Номер входящего сообщения от 1С');

            // Временные метки
            $table->timestamp('processed_at')
                ->comment('Время успешной обработки входящего сообщения');

            $table->boolean('confirmed')->default(false)
                ->comment('Подтверждение отправлено в исходящем сообщении');

            $table->timestamp('confirmed_at')->nullable()
                ->comment('Время отправки подтверждения');

            // Индексы
            $table->index(['connector_id', 'confirmed']);
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_incoming_confirmations');
    }
};
