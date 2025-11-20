<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tv_audience_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')
                ->constrained('tv_channels')
                ->onDelete('cascade');
            $table->dateTime('datetime'); // Объединённое поле
            $table->decimal('audience_value', 10, 3);
            $table->timestamps();

            // Уникальный индекс для избежания дублирования
            $table->unique(['channel_id', 'datetime'], 'unique_audience_slot');

            // Индекс для быстрого поиска
            $table->index('datetime');
            $table->index('channel_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tv_audience_data');
    }
};
