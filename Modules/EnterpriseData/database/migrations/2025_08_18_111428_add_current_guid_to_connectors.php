<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_ftp_connectors', function (Blueprint $table) {
            $table->string('current_foreign_guid', 36)->nullable()
                ->after('foreign_base_guid')
                ->comment('Текущий GUID внешней базы (обновляется через NewFrom)');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_ftp_connectors', function (Blueprint $table) {
            $table->dropColumn('current_foreign_guid');
        });
    }
};
