<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->timestamps();
            $table->softDeletes();

            // Основная информация
            $table->string('original_name');
            $table->string('hash')->unique();
            $table->enum('status', ['processing', 'done', 'failed']);

            // Пути к файлам
            $table->string('original_file_location');
            $table->string('optimized_file_location')->nullable();
            $table->string('thumbnail_file_location')->nullable();

            // Технические характеристики
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->integer('size')->nullable(); // размер в байтах
            $table->string('mime_type', 100)->nullable();
            $table->string('format', 10)->nullable(); // jpg, png, webp

            // Метаданные
            $table->json('exif_data')->nullable();
            $table->json('variants')->nullable(); // разные размеры

            // Индексы
            $table->index('hash');
            $table->index('status');
            $table->index(['width', 'height']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_files');
    }
};
