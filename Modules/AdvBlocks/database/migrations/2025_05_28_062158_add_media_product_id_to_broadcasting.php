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
        Schema::table('adv_block_broadcastings', function (Blueprint $table) {
            $table->integer('media_product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('adv_block_broadcastings', function (Blueprint $table) {
            $table->dropColumn('media_product_id');
        });
    }
};
