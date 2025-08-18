<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_ftp_connectors', function (Blueprint $table) {
            $table->integer('last_outgoing_message_no')->default(0)
                ->after('current_foreign_guid')
                ->comment('Номер последнего исходящего сообщения');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_ftp_connectors', function (Blueprint $table) {
            $table->dropColumn('last_outgoing_message_no');
        });
    }
};
