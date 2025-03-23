<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('adv_block_broadcastings', function (Blueprint $table) {
            $table->id();
            $table->uuid()->index();

            $table->integer('adv_block_id');
            $table->integer('channel_id');
            $table->timestamp('broadcast_at')->index();
            $table->float('size')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['channel_id', 'broadcast_at']);
            $table->index(['adv_block_id', 'broadcast_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('adv_block_broadcasting');
    }
};
