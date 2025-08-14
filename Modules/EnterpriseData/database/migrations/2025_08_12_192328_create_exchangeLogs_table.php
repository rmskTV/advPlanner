<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->timestamps();
            $table->softDeletes();

            // Связь с коннектором
            $table->foreignId('connector_id')
                ->constrained('exchange_ftp_connectors')
                ->onDelete('cascade');

            // Основная информация об обмене
            $table->enum('direction', ['incoming', 'outgoing', 'both'])
                ->comment('Направление обмена');

            $table->unsignedInteger('message_no')->nullable()
                ->comment('Номер сообщения в 1С');

            $table->string('file_name')->nullable()
                ->comment('Имя файла обмена');

            $table->unsignedInteger('objects_count')->nullable()
                ->comment('Количество обработанных объектов');

            // Статус и временные метки
            $table->enum('status', ['started', 'processing', 'completed', 'failed', 'cancelled'])
                ->default('started')
                ->comment('Статус операции');

            $table->timestamp('started_at')->nullable()
                ->comment('Время начала операции');

            $table->timestamp('completed_at')->nullable()
                ->comment('Время завершения операции');

            $table->unsignedInteger('duration_seconds')->nullable()
                ->comment('Длительность операции в секундах');

            // JSON поля для ошибок, предупреждений и метаданных
            $table->json('errors')->nullable()
                ->comment('Массив ошибок операции');

            $table->json('warnings')->nullable()
                ->comment('Массив предупреждений');

            $table->json('metadata')->nullable()
                ->comment('Дополнительные метаданные');

            // Индексы для оптимизации запросов
            $table->index(['connector_id', 'direction']);
            $table->index(['status', 'created_at']);
            $table->index(['direction', 'created_at']);
            $table->index('started_at');
            $table->index('completed_at');
            $table->index('duration_seconds');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_logs');
    }
};
