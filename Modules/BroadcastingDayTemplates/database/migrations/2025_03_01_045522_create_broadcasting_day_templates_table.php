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
        Schema::create('broadcasting_day_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid()->index();
            $table->integer('channel_id')->index();
            $table->integer('start_hour')->default(0);
            $table->string('comment')->nullable();
            $table->string('name')->default('');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('broadcasting_day_templates');
    }
};
