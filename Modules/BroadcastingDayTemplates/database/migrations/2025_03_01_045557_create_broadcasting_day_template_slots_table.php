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
        Schema::create('broadcasting_day_template_slots', function (Blueprint $table) {
            $table->id();
            $table->uuid()->index();
            $table->integer('broadcasting_day_template_id')->index('slots_broadcasting_day_template_id_index');
            $table->string('comment')->nullable();
            $table->string('name')->default('');
            $table->integer('start')->default(0);
            $table->integer('end')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('broadcasting_day_template_slots');
    }
};
