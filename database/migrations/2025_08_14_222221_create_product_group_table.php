<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->timestamps();
            $table->softDeletes();

            // Связь с 1С
            $table->string('guid_1c', 36)->unique()->nullable()
                ->comment('GUID объекта в 1С');

            // Основные реквизиты
            $table->string('name', 255)->nullable()
                ->comment('Наименование группы');

            $table->string('code', 50)->nullable()
                ->comment('Код в программе');

            $table->text('description')->nullable()
                ->comment('Описание группы');

            // Иерархия
            $table->foreignId('parent_id')->nullable()
                ->constrained('product_groups')
                ->onDelete('set null')
                ->comment('Родительская группа');

            $table->string('parent_guid_1c', 36)->nullable()
                ->comment('GUID родительской группы в 1С');

            // Системные поля
            $table->boolean('deletion_mark')->default(false)
                ->comment('Пометка удаления');

            $table->timestamp('last_sync_at')->nullable()
                ->comment('Время последней синхронизации');

            // Индексы
            $table->index('name');
            $table->index('code');
            $table->index('parent_id');
            $table->index('parent_guid_1c');
            $table->index('deletion_mark');
            $table->index('last_sync_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_groups');
    }
};
