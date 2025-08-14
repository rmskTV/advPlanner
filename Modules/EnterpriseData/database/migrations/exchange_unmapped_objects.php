<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_unmapped_objects', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->timestamps();

            // Связь с коннектором
            $table->foreignId('connector_id')
                ->constrained('exchange_ftp_connectors')
                ->onDelete('cascade');

            // Информация об объекте
            $table->string('object_type', 100)
                ->comment('Тип объекта 1С');

            $table->string('version', 10)->nullable()
                ->comment('Версия формата');

            // Статистика
            $table->unsignedInteger('occurrence_count')->default(1)
                ->comment('Количество встреч объекта');

            $table->timestamp('first_seen_at')
                ->comment('Первое обнаружение');

            $table->timestamp('last_seen_at')
                ->comment('Последнее обнаружение');

            // Примеры данных для анализа
            $table->json('sample_data')->nullable()
                ->comment('Пример структуры объекта');

            // Статус маппинга
            $table->enum('mapping_status', ['pending', 'in_progress', 'completed', 'ignored'])
                ->default('pending')
                ->comment('Статус создания маппинга');

            $table->text('notes')->nullable()
                ->comment('Заметки о маппинге');

            $table->softDeletes();

            // Индексы
            $table->unique(['connector_id', 'object_type']);
            $table->index(['mapping_status', 'last_seen_at']);
            $table->index('occurrence_count');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_unmapped_objects');
    }
};
