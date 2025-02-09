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
        Schema::create('adv_blocks', function (Blueprint $table) {
            $table->id();
            $table->uuid()->index();

            $table->string('name')->default('');
            $table->string('comment')->default('')->nullable();

            $table->integer('adv_block_type_id')->index();
            $table->integer('media_product_id')->index();
            $table->integer('channel_id')->index();

            $table->boolean('is_only_for_package')->default(false);
            $table->float('size')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('adv_blocks');
    }
};
