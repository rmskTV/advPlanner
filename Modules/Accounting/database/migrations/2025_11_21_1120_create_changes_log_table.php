<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('object_change_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');

            $table->string('source');       // 'B24' или '1C'
            $table->string('entity_type');  // 'Deal', 'Contact', etc
            $table->integer('b24_id')->nullable();      // ID в Б24
            $table->string('1c_id')->nullable();        // GUID в 1с
            $table->string('local_id');     // ID в нашей БД
            $table->string('status');       // 'pending', 'processed', 'error'
            $table->datetime('received_at')->nullable();
            $table->datetime('sent_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });


    }

    public function down(): void
    {
        Schema::dropIfExists('object_change_logs');
    }
};
