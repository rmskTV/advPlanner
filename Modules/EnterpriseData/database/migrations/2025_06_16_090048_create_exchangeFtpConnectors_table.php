<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('exchange_ftp_connectors', function (Blueprint $table) {
            // Базовые поля
            $table->id();
            $table->uuid('uuid');
            $table->timestamps();
            $table->softDeletes();

            // Идентификаторы баз
            $table->string('own_base_prefix', 10)->comment('Префикс локальной базы (например "Ф2")');
            $table->string('own_base_name')->comment('Название локальной системы');
            $table->string('foreign_base_prefix', 10)->comment('Префикс базы-корреспондента');
            $table->uuid('foreign_base_guid')->nullable()->comment('GUID базы-корреспондента');
            $table->string('foreign_base_name')->comment('Название удаленной системы');

            // FTP-настройки
            $table->string('ftp_path')->comment('Полный путь (ftp://host/path)');
            $table->unsignedSmallInteger('ftp_port')->default(21);
            $table->string('ftp_login');
            $table->string('ftp_password');
            $table->boolean('ftp_passive_mode')->default(true);
            $table->boolean('ftp_transliterate')->default(false);

            // Мета-данные обмена
            $table->string('exchange_plan_name')->comment('Имя плана обмена из 1С');
            $table->string('exchange_format')->comment('Формат данных (EnterpriseData)');

            // Индексы
            $table->index('own_base_prefix');
            $table->index('foreign_base_guid');
        });
    }

    public function down()
    {
        Schema::dropIfExists('exchange_ftp_connectors');
    }
};
